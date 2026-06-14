<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\Prompt;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use App\Repositories\Contracts\ParkRepositoryInterface;
use App\Services\ReservationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Customer flow: find nearest parks with free spaces and reserve one.
 *
 * Steps:
 *   ask_location → user shares location (WhatsApp location msg / Telegram
 *                  location msg, both normalised to "lat,lng" by the
 *                  channel parser)
 *   choose_park  → user replies with the number of the park to reserve
 */
class NearbyParksFlow
{
    public const FLOW = 'nearby_parks';
    private const TTL_MINUTES = 10;
    private const RADIUS_METERS = 5000;
    private const LIMIT = 5;

    public function __construct(
        private readonly ParkRepositoryInterface $parks,
        private readonly ReservationService $reservations,
        private readonly BotNotifier $notifier,
    ) {}

    public function handle(BotSession $session, string $message): OutboundReply
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        if (in_array(mb_strtolower(trim($message)), ['cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'ask_location' => $this->showResults($session, $message),
            'choose_park'  => $this->reserve($session, $message),
            default        => OutboundReply::empty(),
        };
    }

    private function start(BotSession $session): OutboundReply
    {
        if (!$session->getUser()) {
            return OutboundReply::text("📱 يجب تسجيل الدخول أولاً.");
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'ask_location',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            Prompt::ask("📍 شارك موقعك الحالي عبر زر الموقع في تطبيقك،\n")
        );
    }

    private function showResults(BotSession $session, string $message): OutboundReply
    {
        [$lat, $lng] = $this->parseCoords($message);
        if ($lat === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ موقع غير صالح. شارك موقعك أو أرسل: lat,lng")
            );
        }

        $parks = $this->parks->nearby(
            latitude:     $lat,
            longitude:    $lng,
            radiusMeters: self::RADIUS_METERS,
            limit:        self::LIMIT,
        );

        if ($parks->isEmpty()) {
            $session->reset();
            $km = self::RADIUS_METERS / 1000;
            return OutboundReply::text("😔 لا توجد مواقف فارغة ضمن {$km} كم من موقعك.");
        }

        // Persist the result list keyed by 1..N so the user can pick by number.
        $catalog = [];
        $lines   = ["📍 أقرب المواقف الفارغة إليك:\n"];

        foreach ($parks as $i => $park) {
            $n        = $i + 1;
            $distance = $this->formatDistance((int) round($park->distance_meters));

            $catalog[$n] = [
                'id'          => $park->id,
                'name'        => $park->name,
                'lat'         => (float) $park->lat,
                'lng'         => (float) $park->lng,
                'free_spaces' => (int)   $park->free_spaces,
            ];

            $lines[] = "*{$n}. {$park->name}*";
            $lines[] = "   📏 {$distance}  •  🅿️ {$park->free_spaces} مكان فارغ";
            $lines[] = '';
        }

        $lines[] = "أرسل رقم الموقف للحجز (مثال: 1)";
        $lines[] = "أو أرسل 'cancel' للإلغاء.";

        $session->update([
            'step'       => 'choose_park',
            'data'       => ['parks' => $catalog],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::ctaUrl(
            body:    implode("\n", $lines),
            ctaText: '🗺️ عرض على الخريطة',
            url:     $this->buildAllOnMapUrl($parks, $lat, $lng),
        );
    }

    private function reserve(BotSession $session, string $message): OutboundReply
    {
        $msg = trim(DigitNormalizer::toAscii($message));
        if (!ctype_digit($msg)) {
            return OutboundReply::text(Prompt::ask("⚠️ أرسل رقم الموقف فقط (مثال: 1)."));
        }

        $catalog = $session->getData()['parks'] ?? [];
        $choice  = $catalog[(int) $msg] ?? null;

        if (!$choice) {
            return OutboundReply::text(
                Prompt::ask("⚠️ رقم غير صالح. اختر من الأرقام في القائمة أعلاه.")
            );
        }

        $park = Park::find($choice['id']);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ لم يعد هذا الموقف متاحاً.");
        }

        try {
            $reserve = $this->reservations->reserve($session->getUser(), $park);
        } catch (RuntimeException $e) {
            $session->reset();
            return OutboundReply::text(
                $e->getMessage() === 'PARK_FULL'
                    ? " للأسف لم يعد هناك أماكن فارغة في هذا الموقف. حاول واحداً آخر."
                    : "⚠️ تعذر الحجز. حاول مرة أخرى."
            );
        }

        $session->reset();

        // notify the park owner about the new reservation.
        $this->notifyOwner($reserve, $park);

        $expires = $reserve->expires_at->setTimezone(config('app.timezone'))->format('H:i');
        $mapsUrl = sprintf('https://www.google.com/maps?q=%F,%F', $choice['lat'], $choice['lng']);

        // Show the customer the exact identifier the owner will ask for
        // at the gate. Telegram drivers don't have a phone, so we surface
        // their chat_id instead — it's what the owner enters in CarEntry.
        $identifierLine = $this->buildCustomerIdentifierLine($session->getUser());

        return OutboundReply::text(
            "✅ تم حجز مكان لك في *{$choice['name']}*\n\n"
            . "🗺️ الاتجاهات: {$mapsUrl}\n"
            . "⏰ صالح حتّى الساعة {$expires}\n\n"
            . $identifierLine
            . "إذا لم تصل قبل ذلك سيتم إلغاء الحجز تلقائياً."
        );
    }

    /**
     * Notify the park owner across every channel they're enrolled in.
     * Failures must not affect the customer's success response.
     */
    private function notifyOwner(Reserve $reserve, Park $park): void
    {
        try {
            // $park->loadMissing('owner');
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $customer      = $reserve->user ?? $reserve->user;
            $customerName  = $customer?->name ?: 'سائق';
            $customerLine  = $this->describeIdentifierForOwner($customer);

            $expires = $reserve->expires_at
                ? $reserve->expires_at->setTimezone(config('app.timezone'))->format('H:i')
                : '—';

            $message = "🔔 *حجز جديد في موقفك*\n\n"
                     . "الموقف: *{$park->name}*\n"
                     . "الزبون: *{$customerName}*\n"
                     . "{$customerLine}\n"
                     . "صالح حتّى الساعة: {$expires}\n"
                     . "الأماكن المتبقية: *{$park->free_spaces}*\n\n"
                     . "_سيتم إلغاء الحجز تلقائياً إذا لم يصل الزبون في الوقت المحدد._";

            $this->notifier->notify($owner, OutboundReply::text($message));
        } catch (Throwable $e) {
            Log::warning('Owner reservation notification failed', [
                'reserve_id' => $reserve->id,
                'park_id'    => $park->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function formatDistance(int $meters): string
    {
        return $meters >= 1000
            ? number_format($meters / 1000, 1) . ' كم'
            : "{$meters} م";
    }

    /**
     * Build the "give this to the owner at the gate" hint shown to the
     * customer right after a successful reservation. Telegram drivers
     * have no phone, so we surface their chat_id — it's what the owner
     * types into CarEntry's `identifier` step.
     */
    private function buildCustomerIdentifierLine(?User $customer): string
    {
        if (!$customer) {
            return '"لم يتم تحديد رقم تواصلك مع الموقف. تأكد من تسجيل رقم هاتفك في إعدادات الحساب أو ربط حسابك بتليجرام."';
        }

        if ($customer->phone_number) {
            return "🔑 *رقمك لدى الموقف:* `{$customer->phone_number}`\n"
                . "_اعطِ هذا الرقم لصاحب الموقف عند وصولك._\n\n";
        }

        if ($customer->telegram_chat_id) {
            return "🔑 *معرّفك لدى الموقف (Telegram ID):* `{$customer->telegram_chat_id}`\n"
                . "_اعطِ هذا الرقم لصاحب الموقف عند وصولك._\n\n";
        }

        return '';
    }

    /**
     * Format the customer's identifier for the owner's new-reservation
     * notification. Phone takes precedence when both exist (extremely
     * rare — only if the same person linked both channels).
     */
    private function describeIdentifierForOwner(?User $customer): string
    {
        if (!$customer) {
            return "رقم التواصل: —";
        }

        if ($customer->phone_number) {
            return "رقم التواصل: `+" . ltrim($customer->phone_number, '+') . "`";
        }

        if ($customer->telegram_chat_id) {
            return "معرّف تيليجرام: `{$customer->telegram_chat_id}`";
        }

        return "رقم التواصل: —";
    }

    /**
     * Build a public URL pointing at our /parks/map page with the entire
     * result list packed into a single short token. The page is stateless
     * and reads everything from the URL.
     */
    private function buildAllOnMapUrl(Collection $parks, float $userLat, float $userLng): string
    {
        $rows = $parks->map(fn ($p) => sprintf(
            '%F,%F,%d,%s',
            $p->lat,
            $p->lng,
            (int) $p->free_spaces,
            str_replace([',', '|'], ' ', mb_substr((string) $p->name, 0, 40)),
        ))->implode('|');

        $token = rtrim(strtr(base64_encode($rows), '+/', '-_'), '=');

        return route('parks.map', [
            'p'   => $token,
            'lat' => $userLat,
            'lng' => $userLng,
        ]);
    }

    /**
     * take the location from msg in whatsapp bot/ telegram bot
     *  and convert it into lat,lng
     * @return array{0: ?float, 1: ?float}
     */
    private function parseCoords(string $message): array
    {
        $parts = array_map('trim', explode(',', $message));
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return [null, null];
        }

        $lat = (float) $parts[0];
        $lng = (float) $parts[1];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return [null, null];
        }

        return [$lat, $lng];
    }
}
