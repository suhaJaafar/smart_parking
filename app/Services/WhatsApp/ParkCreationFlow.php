<?php

namespace App\Services\WhatsApp;

use App\Enums\CountryTypes;
use App\Enums\RoleTypes;
use App\Enums\StateTypes;
use App\Models\WhatsAppSession;
use App\Services\ParkService;
use App\Services\WhatsApp\Prompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * State-machine for creating a Park via WhatsApp.
 *
 * Steps: name → capacity → location → done. The city is reverse-geocoded
 * from the shared location, not asked from the user.
 * State persists in the `whatsapp_sessions` table so it survives restarts.
 */
class ParkCreationFlow
{
    public const FLOW = 'park_create';
    private const TTL_MINUTES = 15;

    public function __construct(
        private readonly ParkService $parkService,
    ) {}

    /**
     * Returns the bot reply (string or interactive payload), or null if no reply should be sent.
     *
     * @return string|array<string, mixed>|null
     */
    public function handle(WhatsAppSession $session, string $message): string|array|null
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        // Cancel keyword
        if (in_array(mb_strtolower(trim($message)), ['cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return "تم إلغاء العملية.";
        }

        // Entry point
        if ($session->step === 'idle') {
            return $this->start($session);
        }

        return match ($session->step) {
            'name'     => $this->askCapacity($session, $message),
            'capacity' => $this->askLocation($session, $message),
            'location' => $this->finish($session, $message),
            default    => null,
        };
    }

    private function start(WhatsAppSession $session): string
    {
        $user = $session->user;

        if (!$user) {
            return "📱 رقمك غير مسجل في النظام. الرجاء التسجيل أولاً.";
        }

        $hasOwnerRole = $user->roles()
            ->where('role', RoleTypes::SPACE_OWNER->value)
            ->exists();

        if (!$hasOwnerRole) {
            return "🚫 تحتاج صلاحية مالك موقف لإنشاء موقف.";
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'name',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return Prompt::ask("📝 ما اسم الموقف؟");
    }

    private function askCapacity(WhatsAppSession $session, string $message): string
    {
        $name = trim($message);
        if ($name === '' || mb_strlen($name) > 255) {
            return Prompt::ask("⚠️ اسم غير صالح. أرسل اسماً بين 1 و 255 حرف.");
        }

        $this->merge($session, ['name' => $name], 'capacity');
        return Prompt::ask("🅿️ ما السعة الكلية؟ (رقم صحيح موجب)");
    }

    private function askLocation(WhatsAppSession $session, string $message): string
    {
        $msg = trim($message);
        if (!ctype_digit($msg) || (int) $msg < 1) {
            return Prompt::ask("⚠️ أرسل رقماً صحيحاً موجباً للسعة.");
        }

        $cap = (int) $msg;
        $this->merge($session, ['capacity' => $cap, 'free_spaces' => $cap], 'location');
        return Prompt::ask("📍 شارك موقعك عبر زر الموقع في واتساب\n_سيتم تحديد المدينة تلقائياً._");
    }

    /**
     * @return string|array<string, mixed>
     */
    private function finish(WhatsAppSession $session, string $message): string|array
    {
        [$lat, $lng] = $this->parseCoords($message);
        if ($lat === null) {
            return Prompt::ask("⚠️ موقع غير صالح. أرسل: lat,lng (مثال: 33.31,44.38)");
        }

        $data = $session->data ?? [];
        $city = $this->reverseGeocodeCity($lat, $lng);

        try {
            $park = $this->parkService->createWithLocation(
                locationData: [
                    'country'       => CountryTypes::IRAQ->value,
                    'state'         => StateTypes::BAGHDAD->value,
                    'city'          => $city,
                    'postal_code'   => null,
                    'latitude'      => $lat,
                    'longitude'     => $lng,
                    'extra_details' => 'Created via WhatsApp',
                ],
                parkData: [
                    'name'        => $data['name'],
                    'capacity'    => $data['capacity'],
                    'free_spaces' => $data['free_spaces'],
                ],
                owner: $session->user,
            );
        } catch (Throwable $e) {
            Log::error('WA park create failed', ['error' => $e->getMessage()]);
            $session->reset();
            return "❌ فشل إنشاء الموقف: {$e->getMessage()}";
        }

        $session->reset();

        $cityLine = $city ? "المدينة: {$city}\n" : '';
        $body = "✅ تم إنشاء الموقف!\n"
              . "الاسم: {$park->name}\n"
              . "السعة: {$park->capacity}\n"
              . $cityLine
              . "\n"
              . "أرسل *موقفي* لعرض جميع مواقفك، أو *القائمة* للقائمة الرئيسية.";

        return [
            'type'     => 'cta_url',
            'body'     => $body,
            'cta_text' => '🗺️ عرض الموقع',
            'url'      => "https://www.google.com/maps?q={$park->lat},{$park->lng}",
        ];
    }

    private function merge(WhatsAppSession $session, array $patch, string $nextStep): void
    {
        $session->update([
            'data'       => array_merge($session->data ?? [], $patch),
            'step'       => $nextStep,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /**
     * Accepts "lat,lng" text or a pre-built "lat,lng" from a WhatsApp location message.
     *
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

    /**
     * Best-effort reverse geocode using OpenStreetMap Nominatim.
     * Returns null on any failure — the caller treats city as optional.
     */
    private function reverseGeocodeCity(float $lat, float $lng): ?string
    {
        try {
            $response = Http::withHeaders([
                    'User-Agent' => 'SmartParking/1.0 (whatsapp-bot)',
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
