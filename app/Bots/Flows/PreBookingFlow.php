<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\Prompt;
use App\Models\Park;
use App\Models\Reserve;
use App\Repositories\Contracts\ParkRepositoryInterface;
use App\Services\Payments\PaymentService;
use App\Services\ReservationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Customer flow: pre-book a parking space remotely and pay in advance.
 *
 * Unlike {@see NearbyParksFlow} (an on-site reservation that the space owner
 * settles when the car physically enters), a pre-booking lets the customer
 * reserve a space for a place they are *not* at yet (e.g. reserving near work
 * while still at home) and pay the price up-front. Because it is paid in
 * advance, the owner does not need to "enter the car" to trigger payment.
 *
 * Steps:
 *   ask_location → user shares the location of the place they want to park at
 *   choose_park  → user replies with the number of the park to reserve
 *   confirm      → user sends "تم الحجز" to confirm and receive the pay link
 */
class PreBookingFlow
{
    public const FLOW = 'pre_booking';
    private const TTL_MINUTES = 15;
    private const RADIUS_METERS = 5000;
    private const LIMIT = 5;

    /** Words that confirm the booking and move the customer to payment. */
    private const CONFIRM_WORDS = ['تم الحجز', 'تم', 'تأكيد', 'confirm', 'دفع', 'pay'];

    public function __construct(
        private readonly ParkRepositoryInterface $parks,
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
        private readonly BotNotifier $notifier,
    ) {}

    public function handle(BotSession $session, string $message): OutboundReply
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        if (in_array(mb_strtolower(trim($message)), ['0', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'ask_location' => $this->showResults($session, $message),
            'choose_park'  => $this->reserve($session, $message),
            'confirm'      => $this->confirmAndPay($session, $message),
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
            Prompt::ask("📍 شارك *موقع المكان الذي تريد الحجز فيه* (مثال: العمل) عبر زر الموقع في تطبيقك،\n")
        );
    }

    private function showResults(BotSession $session, string $message): OutboundReply
    {
        [$lat, $lng] = $this->parseCoords($message);
        if ($lat === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ موقع غير صالح. شارك الموقع أو أرسل: lat,lng")
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
            return OutboundReply::text("😔 لا توجد مواقف فارغة ضمن {$km} كم من المكان المطلوب.");
        }

        // Persist the result list keyed by 1..N so the user can pick by number.
        $catalog = [];
        $lines   = ["📍 أقرب المواقف الفارغة للمكان المطلوب:\n"];

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

        $lines[] = "أرسل رقم الموقف للحجز المسبق (مثال: 1)";
        $lines[] = "أو أرسل *0* للإلغاء.";

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
            $reserve = $this->reservations->reserve($session->getUser(), $park, preBooking: true);
        } catch (RuntimeException $e) {
            $session->reset();
            return OutboundReply::text(
                $e->getMessage() === 'PARK_FULL'
                    ? " للأسف لم يعد هناك أماكن فارغة في هذا الموقف. حاول واحداً آخر."
                    : "⚠️ تعذر الحجز. حاول مرة أخرى."
            );
        }

        // notify the park owner about the new pre-booking.
        $this->notifyOwner($reserve, $park);

        // Move to the confirm/pay step, remembering which reserve to settle.
        $session->update([
            'step'       => 'confirm',
            'data'       => ['reserve_id' => $reserve->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $expires = $reserve->expires_at->setTimezone(config('app.timezone'))->format('H:i');
        $mapsUrl = sprintf('https://www.google.com/maps?q=%F,%F', $choice['lat'], $choice['lng']);

        return OutboundReply::text(
            "✅ تم حجز مكان لك مسبقاً في *{$choice['name']}*\n\n"
            . "🗺️ الاتجاهات: {$mapsUrl}\n"
            . "⏰ صالح حتّى الساعة {$expires}\n"
            . "🔑 *booking code:* `{$reserve->booking_code}`\n\n"
            . "💳 لإتمام الحجز، ادفع الآن بإرسال *تم الحجز*\n"
            . "_أو أرسل *0* للإلغاء._"
        );
    }

    private function confirmAndPay(BotSession $session, string $message): OutboundReply
    {
        if (!in_array(mb_strtolower(trim($message)), self::CONFIRM_WORDS, true)) {
            return OutboundReply::text(
                Prompt::ask("✳️ أرسل *تم الحجز* لتأكيد الحجز والانتقال للدفع المسبق، أو *0* للإلغاء.")
            );
        }

        $reserveId = $session->getData()['reserve_id'] ?? null;
        $reserve   = $reserveId ? Reserve::with('park')->find($reserveId) : null;

        if (!$reserve) {
            $session->reset();
            return OutboundReply::text("❌ تعذّر العثور على الحجز. ابدأ من جديد.");
        }

        try {
            $payment = $this->payments->ensureForReserve($reserve);
        } catch (Throwable $e) {
            Log::error('Pre-booking payment provisioning failed', [
                'reserve_id' => $reserve->id,
                'error'      => $e->getMessage(),
            ]);
            $session->reset();
            return OutboundReply::text("⚠️ تعذّر تجهيز الدفع. حاول لاحقاً.");
        }

        $session->reset();

        $url    = route('payments.redirect', $payment->token);
        $amount = number_format((float) $payment->amount, 0) . ' ' . $payment->currency;

        return OutboundReply::text(
            "💳 *الدفع المسبق لحجزك*\n\n"
            . "🅿️ الموقف: *{$reserve->park->name}*\n"
            . "💰 المبلغ: *{$amount}*\n"
            . "🔑 booking code: `{$reserve->booking_code}`\n\n"
            . "لإتمام الدفع الآن:\n{$url}\n\n"
            . "_بعد الدفع، أعطِ رمز الحجز لصاحب الموقف عند وصولك._"
        );
    }

    /**
     * Notify the park owner across every channel they're enrolled in.
     * Failures must not affect the customer's success response.
     */
    private function notifyOwner(Reserve $reserve, Park $park): void
    {
        try {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $customer     = $reserve->user;
            $customerName = $customer?->name ?: 'سائق';

            $expires = $reserve->expires_at
                ? $reserve->expires_at->setTimezone(config('app.timezone'))->format('H:i')
                : '—';

            $message = "🔔 *حجز مسبق جديد في موقفك*\n\n"
                     . "الموقف: *{$park->name}*\n"
                     . "الزبون: *{$customerName}*\n"
                     . "booking code: *`{$reserve->booking_code}`*\n"
                     . "صالح حتّى الساعة: {$expires}\n"
                     . "الأماكن المتبقية: *{$park->free_spaces}*\n\n"
                     . "_حجز مسبق مدفوع \u2014 لا حاجة لإدخال السيارة لتفعيل الدفع._";

            $this->notifier->notify($owner, OutboundReply::text($message));
        } catch (Throwable $e) {
            Log::warning('Owner pre-booking notification failed', [
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
