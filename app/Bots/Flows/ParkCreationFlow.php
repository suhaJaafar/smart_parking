<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\Prompt;
use App\Data\LocationData;
use App\Data\ParkData;
use App\Enums\CountryTypes;
use App\Enums\RoleTypes;
use App\Enums\StateTypes;
use App\Services\ParkService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * State-machine for creating a Park.
 *
 * Steps: name → capacity → price → location → done. The owner sets a flat
 * price that is charged once per reservation. The city is reverse-geocoded
 * from the shared location, not asked from the user.
 *
 * State persists in whichever channel session table (whatsapp_sessions /
 * telegram_sessions) so it survives restarts.
 */
class ParkCreationFlow
{
    public const FLOW = 'park_create';
    private const TTL_MINUTES = 15;

    public function __construct(
        private readonly ParkService $parkService,
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
            'name'     => $this->askCapacity($session, $message),
            'capacity' => $this->askPrice($session, $message),
            'price'    => $this->askLocation($session, $message),
            'location' => $this->finish($session, $message),
            default    => OutboundReply::empty(),
        };
    }

    private function start(BotSession $session): OutboundReply
    {
        $user = $session->getUser();

        if (!$user) {
            return OutboundReply::text("📱 حسابك غير مسجل في النظام. الرجاء التسجيل أولاً.");
        }

        $hasOwnerRole = $user->roles()
            ->where('role', RoleTypes::SPACE_OWNER->value)
            ->exists();

        if (!$hasOwnerRole) {
            return OutboundReply::text("🚫 تحتاج صلاحية مالك موقف لإنشاء موقف.");
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'name',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(Prompt::ask("📝 ما اسم الموقف؟"));
    }

    private function askCapacity(BotSession $session, string $message): OutboundReply
    {
        $name = trim($message);
        if ($name === '' || mb_strlen($name) > 255) {
            return OutboundReply::text(
                Prompt::ask("⚠️ اسم غير صالح. أرسل اسماً بين 1 و 255 حرف.")
            );
        }

        $this->merge($session, ['name' => $name], 'capacity');
        return OutboundReply::text(Prompt::ask("🔢 ما السعة الكلية؟ (رقم صحيح موجب)"));
    }

    private function askPrice(BotSession $session, string $message): OutboundReply
    {
        $msg = trim(DigitNormalizer::toAscii($message));
        if (!ctype_digit($msg) || (int) $msg < 1) {
            return OutboundReply::text(Prompt::ask("⚠️ أرسل رقماً صحيحاً موجباً للسعة."));
        }

        $cap = (int) $msg;
        $this->merge($session, ['capacity' => $cap, 'free_spaces' => $cap], 'price');

        return OutboundReply::text(
            Prompt::ask("💰 ما سعر الحجز الواحد؟ (بالدينار العراقي — يُخصم مرة واحدة عند دخول السيارة)")
        );
    }

    private function askLocation(BotSession $session, string $message): OutboundReply
    {
        $msg = trim(DigitNormalizer::toAscii($message));
        if (!ctype_digit($msg) || (int) $msg < 1) {
            return OutboundReply::text(
                Prompt::ask("⚠️ أرسل سعراً صحيحاً موجباً بالدينار (مثال: 2000).")
            );
        }

        $price = (int) $msg;
        $this->merge($session, ['price' => $price], 'location');

        return OutboundReply::text(
            Prompt::ask("📍 شارك موقعك عبر زر الموقع في تطبيقك\n_سيتم تحديد المدينة تلقائياً._")
        );
    }

    private function finish(BotSession $session, string $message): OutboundReply
    {
        [$lat, $lng] = $this->parseCoords($message);
        if ($lat === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ موقع غير صالح. أرسل: lat,lng (مثال: 33.31,44.38)")
            );
        }

        $data = $session->getData();
        $city = $this->reverseGeocodeCity($lat, $lng);

        try {
            $park = $this->parkService->createWithLocation(
                location: new LocationData(
                    country:      CountryTypes::IRAQ,
                    state:        StateTypes::BAGHDAD,
                    city:         $city,
                    postalCode:   null,
                    latitude:     $lat,
                    longitude:    $lng,
                    extraDetails: 'Created via ' . $session->getChannel(),
                ),
                park: new ParkData(
                    name:     $data['name'],
                    capacity: $data['capacity'],
                    price:    isset($data['price']) ? (float) $data['price'] : null,
                ),
                owner: $session->getUser(),
            );
        } catch (Throwable $e) {
            Log::error('Bot park create failed', ['error' => $e->getMessage()]);
            $session->reset();
            return OutboundReply::text("❌ فشل إنشاء الموقف: {$e->getMessage()}");
        }

        $session->reset();

        $cityLine = $city ? "المدينة: {$city}\n" : '';
        $priceLine = 'السعر: ' . number_format((float) $park->price, 0)
                   . ' ' . config('services.qicard.currency') . " (يُخصم مرة واحدة)\n";
        $body = "✅ تم إنشاء الموقف!\n"
              . "الاسم: {$park->name}\n"
              . "السعة: {$park->capacity}\n"
              . $priceLine
              . $cityLine
              . "\n"
              . "أرسل *موقفي* لعرض جميع مواقفك، أو *القائمة* للقائمة الرئيسية.";

        return OutboundReply::ctaUrl(
            body:    $body,
            ctaText: '🗺️ عرض الموقع',
            url:     "https://www.google.com/maps?q={$lat},{$lng}",
        );
    }

    private function merge(BotSession $session, array $patch, string $nextStep): void
    {
        $session->update([
            'data'       => array_merge($session->getData(), $patch),
            'step'       => $nextStep,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function parseCoords(string $message): array
    {
        // Accept Arabic/Persian digits typed for lat,lng too.
        $parts = array_map('trim', explode(',', DigitNormalizer::toAscii($message)));
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

    /**
     * Best-effort reverse geocode using OpenStreetMap Nominatim.
     * Returns null on any failure — the caller treats city as optional.
     */
    private function reverseGeocodeCity(float $lat, float $lng): ?string
    {
        try {
            $response = Http::withHeaders([
                    'User-Agent'      => 'SmartParking/1.0 (bot)',
                    'Accept-Language' => 'ar,en',
                ])
                ->timeout(5)
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format'         => 'jsonv2',
                    'lat'            => $lat,
                    'lon'            => $lng,
                    'zoom'           => 14,
                    'addressdetails' => 1,
                ]);

            if ($response->failed()) {
                Log::warning('Nominatim reverse failed', ['status' => $response->status()]);
                return null;
            }

            $address = $response->json('address') ?? [];
            $city = $address['city']
                 ?? $address['town']
                 ?? $address['village']
                 ?? $address['municipality']
                 ?? $address['county']
                 ?? $address['state']
                 ?? null;

            return is_string($city) && $city !== '' ? $city : null;
        } catch (Throwable $e) {
            Log::warning('Nominatim reverse exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
