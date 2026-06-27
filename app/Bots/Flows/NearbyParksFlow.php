<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Flows\Concerns\CollectsPhoneNumber;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\Prompt;
use App\Models\Park;
use App\Models\Reserve;
use App\Repositories\Contracts\ParkRepositoryInterface;
use App\Services\ReservationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    use CollectsPhoneNumber;

    public const FLOW = 'nearby_parks';
    private const TTL_MINUTES = 10;
    private const RADIUS_METERS = 5000;
    private const LIMIT = 5;

    /** Prefix used to tag a tapped park button so it round-trips as inbound text. */
    private const PARK_OPTION_PREFIX = 'park:';

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

        if (in_array(mb_strtolower(trim($message)), ['0', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'ask_location' => $this->showResults($session, $message),
            'ask_phone'    => $this->onPhoneStep($session, $message),
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

        // Show the nearby parks first; the phone number is only requested
        // once the customer actually picks a park to reserve (see reserve()).
        return $this->showParks($session, $lat, $lng);
    }

    /**
     * Resume after the phone step: still collecting → return its reply;
     * captured → complete the reservation for the park the customer picked.
     */
    private function onPhoneStep(BotSession $session, string $message): OutboundReply
    {
        $result = $this->handlePhoneStep($session, $message);
        if ($result instanceof OutboundReply) {
            return $result;
        }

        $park = Park::find($result['id'] ?? null);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ لم يعد هذا الموقف متاحاً.");
        }

        return $this->completeReservation($session, $park, $result);
    }

    private function showParks(BotSession $session, float $lat, float $lng): OutboundReply
    {
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

        // Persist the result list keyed by 1..N so a tapped button (or a typed
        // number, as a fallback) can be resolved back to a concrete park.
        $catalog = [];
        $options = [];

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

            $options[] = [
                'id'          => self::PARK_OPTION_PREFIX . $park->id,
                'title'       => $this->parkChoiceLabel($park->name, $distance, (int) $park->free_spaces),
                'description' => $this->parkChoiceDetail($park->name, $distance, (int) $park->free_spaces),
            ];
        }

        $session->update([
            'step'       => 'choose_park',
            'data'       => ['parks' => $catalog],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $body = "📍 أقرب المواقف الفارغة إليك:\n\n"
              . "اختر الموقف الذي تريد الحجز فيه، أو أرسل *0* للإلغاء.";

        if($parks->count() === 1){
            return OutboundReply::buttons(
                body:       $body,
                options:    $options,
                listButton: 'اختر الموقف',
                linkButton: [
                    'title' => '🗺️ عرض الموقف على الخريطة',
                    'url'   => $this->buildAllOnMapUrl($parks, $lat, $lng),
                ],
            );
        }
        return OutboundReply::buttons(
            body:       $body,
            options:    $options,
            listButton: 'اختر الموقف',
            linkButton: [
                'title' => '🗺️ عرض الكل على الخريطة',
                'url'   => $this->buildAllOnMapUrl($parks, $lat, $lng),
            ],
        );
    }

    private function reserve(BotSession $session, string $message): OutboundReply
    {
        // The customer can refine their search at any time by sharing a new
        // location while the list is open — refresh the nearby parks instead
        // of treating the coordinates as an (invalid) menu choice.
        [$lat, $lng] = $this->parseCoords(trim($message));
        if ($lat !== null && $lng !== null) {
            return $this->showParks($session, $lat, $lng);
        }

        $catalog = $session->getData()['parks'] ?? [];
        $choice  = $this->resolveParkChoice(trim($message), $catalog);

        if (!$choice) {
            return OutboundReply::text(
                Prompt::ask("⚠️ اختر أحد المواقف أعلاه، أو شارك موقعك من جديد لتحديث القائمة.")
            );
        }

        $park = Park::find($choice['id']);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ لم يعد هذا الموقف متاحاً.");
        }

        // Now that a park is chosen, collect the customer's phone (once) so
        // the owner can reach them. Skipped if already known (e.g. WhatsApp).
        if ($this->needsPhone($session->getUser())) {
            return $this->startPhoneGate(
                $session,
                $choice,
                self::TTL_MINUTES,
                "⚠️ _لن تتمكن من إجراء حجز بدون مشاركة رقم هاتفك._"
            );
        }

        return $this->completeReservation($session, $park, $choice);
    }

    /**
     * Place the reservation for the chosen park, notify the owner and return
     * the customer's success message. Reached either directly (phone already
     * on file) or after the phone gate resolves.
     *
     * @param array{id:string,name:string,lat:float,lng:float,free_spaces?:int} $choice
     */
    private function completeReservation(BotSession $session, Park $park, array $choice): OutboundReply
    {
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

        $expires = $reserve->expires_at->setTimezone(config('app.timezone'))->format('h:i A');
        $mapsUrl = sprintf('https://www.google.com/maps?q=%F,%F', $choice['lat'], $choice['lng']);
        $priceText = number_format((float) $park->price, 0) . ' ' . config('services.qicard.currency');

        return OutboundReply::text(
            "✅ تم حجز مكان لك في *{$choice['name']}*\n\n"
            . "🗺️ للاتجاهات: [اضغط هنا]({$mapsUrl})\n"
            . "⏰ صالح حتّى الساعة {$expires}\n"
            . "💰 سعر الحجز: *{$priceText}* (يُخصم مرة واحدة)\n\n"
            . "🚗 عند وصولك سيؤكّد صاحب الموقف دخول سيارتك مباشرةً.\n"
            . "إذا لم تصل قبل الوقت المحدد سيتم إلغاء الحجز تلقائياً."
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
            $customerPhone = $customer?->phone_number;

            $expires = $reserve->expires_at
                ? $reserve->expires_at->setTimezone(config('app.timezone'))->format('h:i A')
                : '—';

            $contactLine = $customerPhone ? "📱 هاتف الزبون: *+{$customerPhone}*\n" : '';

            $message = "🔔 *حجز جديد في موقفك*\n\n"
                     . "الموقف: *{$park->name}*\n"
                     . "الزبون: *{$customerName}*\n"
                     . $contactLine
                     . "صالح حتّى الساعة: {$expires}\n"
                     . "الأماكن المتبقية: *{$park->free_spaces}*\n\n"
                     . "_سيظهر الزبون في قائمة السيارات الواصلة عند وصوله. سيتم الإلغاء تلقائياً إذا لم يصل في الوقت المحدد._";

            $this->notifier->notify($owner, OutboundReply::text($message));
        } catch (Throwable $e) {
            Log::warning('Owner reservation notification failed', [
                'reserve_id' => $reserve->id,
                'park_id'    => $park->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the customer's park choice from either a tapped button (id of
     * the form "park:<uuid>") or a typed list number, against the catalog
     * persisted when the results were shown.
     *
     * @param  array<int|string, array{id:string,name:string,lat:float,lng:float,free_spaces:int}>  $catalog
     * @return array{id:string,name:string,lat:float,lng:float,free_spaces:int}|null
     */
    private function resolveParkChoice(string $raw, array $catalog): ?array
    {
        if (str_starts_with(mb_strtolower($raw), self::PARK_OPTION_PREFIX)) {
            $parkId = Str::after($raw, self::PARK_OPTION_PREFIX);
            return collect($catalog)->firstWhere('id', $parkId);
        }

        $number = DigitNormalizer::toAscii($raw);
        if (ctype_digit($number)) {
            return $catalog[(int) $number] ?? null;
        }

        return null;
    }

    private function formatDistance(int $meters): string
    {
        $value = $meters >= 1000
            ? rtrim(rtrim(number_format($meters / 1000, 1), '0'), '.') . ' كم'
            : "{$meters} متر";

        return DigitNormalizer::toArabic($value);
    }

    /**
     * Tappable label for a park choice, e.g.
     *   "كراج المروج ، يبعد مسافة ١٠٠ متر ، ٢٠ مكان متاح متوفر".
     * Arabic-named parks read "يبعد مسافة", others read "يبعد".
     */
    private function parkChoiceLabel(string $name, string $distance, int $freeSpaces): string
    {
        return trim($name) . ' ، ' . $this->parkChoiceDetail($name, $distance, $freeSpaces);
    }

    /**
     * The distance + availability portion of a park choice, used as the
     * label suffix and as the WhatsApp list-row description.
     */
    private function parkChoiceDetail(string $name, string $distance, int $freeSpaces): string
    {
        $verb = preg_match('/\p{Arabic}/u', $name) ? 'يبعد مسافة' : 'يبعد';
        $free = DigitNormalizer::toArabic((string) $freeSpaces);

        return "{$verb} {$distance} ، {$free} مكان متاح متوفر";
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
