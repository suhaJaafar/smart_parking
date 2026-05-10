<?php

namespace App\Services\WhatsApp;

use App\Models\Park;
use App\Models\Reserve;
use App\Models\WhatsAppSession;
use App\Repositories\Contracts\ParkRepositoryInterface;
use App\Services\ReservationService;
use App\Services\WhatsApp\Prompt;
use App\Services\WhatsApp\WhatsAppNotifier;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Customer flow: find nearest parks with free spaces and reserve one.
 * Steps:
 *   ask_location → user shares WhatsApp location (or types "lat,lng")
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
        private readonly WhatsAppNotifier $notifier,
    ) {}

    public function handle(WhatsAppSession $session, string $message): string|array|null
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        if (in_array(mb_strtolower(trim($message)), ['cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return "تم إلغاء العملية.";
        }

        if ($session->step === 'idle') {
            return $this->start($session);
        }

        return match ($session->step) {
            'ask_location' => $this->showResults($session, $message),
            'choose_park'  => $this->reserve($session, $message),
            default        => null,
        };
    }

    private function start(WhatsAppSession $session): string
    {
        if (!$session->user) {
            return "📱 يجب تسجيل الدخول أولاً.";
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'ask_location',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return Prompt::ask(
            "📍 شارك موقعك الحالي عبر زر الموقع في واتساب،\n"
        );
    }

    private function showResults(WhatsAppSession $session, string $message): string|array
    {
        [$lat, $lng] = $this->parseCoords($message);
        if ($lat === null) {
            return Prompt::ask("⚠️ موقع غير صالح. شارك موقعك أو أرسل: lat,lng");
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
            return "😔 لا توجد مواقف فارغة ضمن {$km} كم من موقعك.";
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

        $body = implode("\n", $lines);

        // Always return a CTA-URL interactive so the customer can open one map
        // showing every nearby park (even a single result) plotted next to
        // their current location.
        return [
            'type'     => 'cta_url',
            'body'     => $body,
            'cta_text' => '🗺️ عرض على الخريطة',
            'url'      => $this->buildAllOnMapUrl($parks, $lat, $lng),
        ];
    }

    private function reserve(WhatsAppSession $session, string $message): string
    {
        $msg = trim($message);
        if (!ctype_digit($msg)) {
            return Prompt::ask("⚠️ أرسل رقم الموقف فقط (مثال: 1).");
        }

        $catalog = $session->data['parks'] ?? [];
        $choice  = $catalog[(int) $msg] ?? null;

        if (!$choice) {
            return Prompt::ask("⚠️ رقم غير صالح. اختر من الأرقام في القائمة أعلاه.");
        }

        $park = Park::find($choice['id']);
        if (!$park) {
            $session->reset();
            return "❌ لم يعد هذا الموقف متاحاً.";
        }

        try {
            $reserve = $this->reservations->reserve($session->user, $park);
        } catch (RuntimeException $e) {
            $session->reset();
            return $e->getMessage() === 'PARK_FULL'
                ? " للأسف لم يعد هناك أماكن فارغة في هذا الموقف. حاول واحداً آخر."
                : "⚠️ تعذر الحجز. حاول مرة أخرى.";
        }

        $session->reset();

        // Best-effort: notify the park owner about the new reservation.
        // Failures here must not affect the customer's success response.
        $this->notifyOwner($reserve, $park);

        $expires = $reserve->expires_at->setTimezone(config('app.timezone'))->format('H:i');
        $mapsUrl = sprintf('https://www.google.com/maps?q=%F,%F', $choice['lat'], $choice['lng']);

        return "✅ تم حجز مكان لك في *{$choice['name']}*\n\n"
             . "🗺️ الاتجاهات: {$mapsUrl}\n"
             . "⏰ صالح حتّى الساعة {$expires}\n\n"
             . "إذا لم تصل قبل ذلك سيتم إلغاء الحجز تلقائياً.";
    }

    /**
     * Send a WhatsApp DM to the park owner letting them know a customer has
     * just reserved a slot. Owner phone may be missing (legacy data) — in
     * that case we silently skip; the WhatsAppNotifier is itself best-effort.
     */
    private function notifyOwner(Reserve $reserve, Park $park): void
    {
        try {
            $park->loadMissing('owner');
            $owner = $park->owner;
            if (!$owner || !$owner->phone_number) {
                return;
            }

            $customer     = $reserve->user ?? $reserve->loadMissing('user')->user;
            $customerName = $customer?->name ?: 'سائق';
            $customerPhone = $customer?->phone_number
                ? '+' . ltrim($customer->phone_number, '+')
                : '—';

            $expires = $reserve->expires_at
                ? $reserve->expires_at->setTimezone(config('app.timezone'))->format('H:i')
                : '—';

            $message = "🔔 *حجز جديد في موقفك*\n\n"
                     . "الموقف: *{$park->name}*\n"
                     . "الزبون: *{$customerName}*\n"
                     . "رقم الواتساب: {$customerPhone}\n"
                     . "صالح حتّى الساعة: {$expires}\n"
                     . "الأماكن المتبقية: *{$park->free_spaces}*\n\n"
                     . "_سيتم إلغاء الحجز تلقائياً إذا لم يصل الزبون في الوقت المحدد._";

            $this->notifier->send($owner->phone_number, $message);
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
     * Build a public URL pointing at our /parks/map page with the entire result
     * list packed into a single short token. The page is stateless and reads
     * everything from the URL.
     */
    private function buildAllOnMapUrl(\Illuminate\Support\Collection $parks, float $userLat, float $userLng): string
    {
        $rows = $parks->map(fn ($p) => sprintf(
            '%F,%F,%d,%s',
            $p->lat,
            $p->lng,
            (int) $p->free_spaces,
            // Strip commas and pipes — they're our row/field separators.
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
