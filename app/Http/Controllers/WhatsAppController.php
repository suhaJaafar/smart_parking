<?php

namespace App\Http\Controllers;

use App\Enums\RoleTypes;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\ReservationService;
use App\Services\WhatsApp\CarEntryFlow;
use App\Services\WhatsApp\CarExitFlow;
use App\Services\WhatsApp\MenuRenderer;
use App\Services\WhatsApp\NearbyParksFlow;
use App\Services\WhatsApp\OnboardingFlow;
use App\Services\WhatsApp\ParkCreationFlow;
use App\Services\WhatsApp\WhatsAppNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(
        private readonly ParkCreationFlow $parkFlow,
        private readonly CarEntryFlow $carEntryFlow,
        private readonly CarExitFlow $carExitFlow,
        private readonly NearbyParksFlow $nearbyParksFlow,
        private readonly OnboardingFlow $onboardingFlow,
        private readonly ReservationService $reservations,
        private readonly WhatsAppNotifier $notifier,
        private readonly MenuRenderer $menu,
    ) {}

    // ===============================
    // WEBHOOK VERIFICATION (GET)
    // ===============================
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            Log::info('Webhook verified successfully!');
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    // ===============================
    // RECEIVE MESSAGES (POST)
    // ===============================
    public function receive(Request $request): Response
    {
        Log::info('WA webhook received', ['raw' => $request->getContent()]);

        $body = json_decode($request->getContent(), true);

        if (($body['object'] ?? '') === 'whatsapp_business_account') {
            foreach ($body['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    $value = $change['value'] ?? [];

                    if (isset($value['statuses'])) {
                        Log::info('Skipping status update');
                        continue;
                    }

                    foreach ($value['messages'] ?? [] as $message) {
                        $from = $message['from'] ?? null;
                        $type = $message['type'] ?? 'text';
                        $text = $this->extractContent($message, $type);

                        if ($from && $text !== '') {
                            Log::info("Message from {$from} ({$type}): {$text}");
                            $this->handleMessage($from, $text);
                        }
                    }
                }
            }
        }

        return response('OK', 200);
    }

    /**
     * Convert any inbound message type into a plain text payload the flows can parse.
     */
    private function extractContent(array $message, string $type): string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? '',

            'location' => isset($message['location']['latitude'], $message['location']['longitude'])
                ? sprintf('%s,%s', $message['location']['latitude'], $message['location']['longitude'])
                : '',

            'interactive' => $message['interactive']['button_reply']['id']
                          ?? $message['interactive']['list_reply']['id']
                          ?? '',

            default => '',
        };
    }

    // ===============================
    // BOT LOGIC
    // ===============================
    private function handleMessage(string $from, string $text): void
    {
        $session = $this->resolveSession($from);
        $msg     = trim($text);
        $lower   = mb_strtolower($msg);

        // ===============================================================
        // GLOBAL ESCAPE COMMANDS
        // These work at any time — even mid-flow — so the user can abort
        // and bounce back to the menu (e.g. switch from owner to customer
        // path) without finishing whatever they started.
        // Onboarding (no user yet) is excluded so we don't drop someone
        // before they finish registering.
        // ===============================================================
        $escapeCommands = [
            'cancel', 'الغاء', 'إلغاء',
            'menu', 'القائمة',
            'back', 'رجوع',
            'restart', 'اعادة', 'إعادة',
        ];

        // Global trigger: re-run onboarding (driver/owner question) from anywhere.
        $restartOnboardingCommands = [
            'register', 'تسجيل', 'switch', 'تبديل', 'change role', 'تغيير الدور',
        ];

        // Global trigger: customer wants to release their currently held slot.
        // Matched BEFORE generic "cancel" so the longer phrase wins.
        $cancelReservationCommands = [
            'cancel reservation', 'cancel my reservation',
            'الغاء حجزي', 'إلغاء حجزي', 'الغاء الحجز', 'إلغاء الحجز',
        ];

        // Global trigger: owner wants to see the parks they already own.
        $myParksCommands = [
            'my park', 'my parks', 'parks', 'parks list',
            'موقفي', 'مواقفي', 'مواقف', 'مواقف جراجي',
        ];

        // Global trigger: show the command cheat-sheet at any time.
        $helpCommands = [
            'help', '?', 'مساعدة', 'الاوامر', 'الأوامر', 'commands',
        ];

        // Global trigger: show the user's profile + any active reservation.
        $statusCommands = [
            'status', 'الحالة', 'حسابي', 'my status', 'profile',
        ];

        if ($session->user && in_array($lower, $helpCommands, true)) {
            $this->sendMessage($from, $this->helpSheet($session));
            return;
        }

        if ($session->user && in_array($lower, $statusCommands, true)) {
            $this->sendMessage($from, $this->userStatus($session));
            return;
        }

        if ($session->user && in_array($lower, $cancelReservationCommands, true)) {
            $this->sendMessage($from, $this->cancelActiveReservation($session));
            return;
        }

        if ($session->user && in_array($lower, $myParksCommands, true)) {
            $this->sendReply($from, $this->myParks($session));
            return;
        }

        // Rename command: "اسمي <new name>" or "name <new name>".
        // Single-shot — no flow needed.
        if ($session->user && preg_match('/^(?:اسمي|name)\s+(.+)$/iu', $msg, $m)) {
            $this->sendMessage($from, $this->renameUser($session, $m[1]));
            return;
        }

        // Bare "اسمي" / "name" with no value → show usage hint.
        if ($session->user && in_array($lower, ['اسمي', 'name', 'rename'], true)) {
            $this->sendMessage(
                $from,
                "✍️ لتعديل اسمك أرسل:\n*اسمي* ثم اسمك الجديد\n"
                . "_مثال: اسمي أحمد محمد_"
            );
            return;
        }

        if ($session->user && in_array($lower, $restartOnboardingCommands, true)) {
            $session->reset();
            $session->load(['user', 'user.roles']);
            $reply = $this->startFlow($session, OnboardingFlow::class);
            $this->sendMessage($from, $reply);
            return;
        }

        if ($session->user && in_array($lower, $escapeCommands, true)) {
            $session->reset();
            $session->load(['user', 'user.roles']);
            $this->sendMessage($from, $this->handleIdle($session, 'القائمة'));
            return;
        }

        // Mid-flow: route to that flow.
        $reply = match ($session->flow) {
            OnboardingFlow::FLOW   => $this->onboardingFlow->handle($session, $msg),
            CarEntryFlow::FLOW     => $this->carEntryFlow->handle($session, $msg),
            CarExitFlow::FLOW      => $this->carExitFlow->handle($session, $msg),
            NearbyParksFlow::FLOW  => $this->nearbyParksFlow->handle($session, $msg),
            ParkCreationFlow::FLOW => $this->parkFlow->handle($session, $msg),
            default                => null,
        };

        if ($reply !== null) {
            $this->sendReply($from, $reply);
            return;
        }

        // Idle — handle top-level commands.
        $reply = $this->handleIdle($session, $msg);
        $this->sendReply($from, $reply);
    }

    /**
     * Send either a plain text reply or a structured interactive payload.
     *
     * Supported structured shapes (returned by flows):
     *   ['type' => 'cta_url', 'body' => ..., 'cta_text' => ..., 'url' => ...]
     */
    private function sendReply(string $to, string|array $reply): void
    {
        if (is_string($reply)) {
            if ($reply !== '') {
                $this->sendMessage($to, $reply);
            }
            return;
        }

        if (($reply['type'] ?? '') === 'cta_url') {
            $this->sendCtaUrl($to, $reply['body'], $reply['cta_text'], $reply['url']);
            return;
        }

        // Unknown shape — fall back to text body if present.
        if (isset($reply['body'])) {
            $this->sendMessage($to, $reply['body']);
        }
    }

    /**
     * Top-level menu when the user has no active flow.
     * Behavior depends on whether the phone is registered and what roles they have.
     */
    private function handleIdle(WhatsAppSession $session, string $msg): string
    {
        // Unknown phone → start onboarding immediately on any message.
        if (!$session->user) {
            return $this->startFlow($session, OnboardingFlow::class);
        }

        $lower = mb_strtolower($msg);

        // Global "cancel" — useful even when no flow is active.
        if (in_array($lower, ['Cancel', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return "تم إلغاء العملية.\nأرسل *القائمة* للقائمة أو *مساعدة* للأوامر.";
        }

        $roles      = $session->user->roles->pluck('role')->all();
        $isOwner    = in_array(RoleTypes::SPACE_OWNER, $roles, true);
        $isCustomer = in_array(RoleTypes::CUSTOMER, $roles, true)
                   || in_array(RoleTypes::USER, $roles, true);

        // Owner-only commands (entries / exits require an existing park).
        if ($isOwner) {
            $ownerMap = [
                '1'           => CarEntryFlow::class,
                '2'           => CarExitFlow::class,
                'enter car'   => CarEntryFlow::class,
                'exit car'    => CarExitFlow::class,
            ];
            if (isset($ownerMap[$lower])) {
                return $this->startFlow($session, $ownerMap[$lower]);
            }
        }

        // Park creation: owners only. Customers wishing to register a park
        // should restart onboarding via 'switch' / 'تسجيل' and pick the
        // "I own a park" path.
        if ($isOwner) {
            $createParkMap = [
                '3'           => ParkCreationFlow::class,
                'create park' => ParkCreationFlow::class,
                'إنشاء موقف'  => ParkCreationFlow::class,
                'انشاء موقف'  => ParkCreationFlow::class,
            ];
            if (isset($createParkMap[$lower])) {
                return $this->startFlow($session, $createParkMap[$lower]);
            }
        }

        // Customer commands.
        if ($isCustomer) {
            $customerMap = [
                '4'         => NearbyParksFlow::class,
                'nearby'    => NearbyParksFlow::class,
                'find park' => NearbyParksFlow::class,
            ];
            if (isset($customerMap[$lower])) {
                return $this->startFlow($session, $customerMap[$lower]);
            }
        }

        if (in_array($lower, ['hello', 'hi', 'مرحبا', 'هلو', 'menu', 'القائمة'], true)) {
            return $this->menu->for($session->user);
        }

        return "عذراً، لم أفهم رسالتك 😊\n"
             . "أرسل *مساعدة* لرؤية كل الأوامر، أو *القائمة* للقائمة.";
    }

    /**
     * Bootstrap a fresh flow: reset the session into the new flow's idle state,
     * then call its handler with an empty message to emit the first prompt.
     */
    private function startFlow(WhatsAppSession $session, string $flowClass): string
    {
        $session->update([
            'flow' => $flowClass::FLOW,
            'step' => 'idle',
            'data' => [],
        ]);

        $handler = match ($flowClass) {
            OnboardingFlow::class   => $this->onboardingFlow,
            CarEntryFlow::class     => $this->carEntryFlow,
            CarExitFlow::class      => $this->carExitFlow,
            NearbyParksFlow::class  => $this->nearbyParksFlow,
            ParkCreationFlow::class => $this->parkFlow,
        };

        return $handler->handle($session->fresh(['user']), '') ?? '';
    }

    /**
     * Render the role-aware main menu, with an active-reservation banner
     * at the top when one exists, and a smarter footer pointing to `help`.
     */
    private function menuFor(?User $user): string
    {
        return $this->menu->for($user);
    }

    /**
     * One-line summary of the user's active reservation, or null if none.
     * Shown at the top of the menu so customers always know what's pending.
     */
    private function activeReservationBanner(User $user): ?string
    {
        return $this->menu->activeReservationBanner($user);
    }

    /**
     * Categorized command cheat-sheet. The bot's discoverability tool.
     */
    private function helpSheet(WhatsAppSession $session): string
    {
        $user       = $session->user;
        $roles      = $user ? $user->roles->pluck('role')->all() : [];
        $isOwner    = in_array(RoleTypes::SPACE_OWNER, $roles, true);
        $isCustomer = in_array(RoleTypes::CUSTOMER, $roles, true)
                   || in_array(RoleTypes::USER, $roles, true);

        $lines = [
            "📖 *دليل الأوامر — ParkIQ*",
            '',
            "🔹 *قائمة الخيارات الرئيسية:*",
            "   • *القائمة* أو *menu*  — عرض القائمة",
            '',
        ];

        if ($isOwner) {
            $lines[] = "🅿️ *مالك موقف:*";
            $lines[] = "   *1*  دخول سيارة للكراج";
            $lines[] = "   *2*  خروج سيارة من الكراج";
            $lines[] = "   *3*  إنشاء موقف جديد";
            $lines[] = "   *موقفي*  عرض مواقفي المسجلة";
            $lines[] = '';
        }

        if ($isCustomer) {
            $lines[] = "🚗 *سائق:*";
            $lines[] = "   *4*  البحث عن أقرب موقف";
            $lines[] = "   *الغاء حجزي*  إلغاء الحجز الحالي";
            $lines[] = '';
        }

        $lines[] = "🔄 *تغيير دورك:*";
        $lines[] = "   *تسجيل* أو *register* — يفتح خطوة اختيار الدور (سائق / مالك موقف)";
        $lines[] = '';

        $lines[] = "✍️ *تعديل اسمك:*";
        $lines[] = "   *اسمي* ثم اسمك الجديد — مثال: *اسمي أحمد محمد*";
        $lines[] = '';

        $lines[] = "🆘 *في أي وقت:*";
        $lines[] = "   *الغاء*  أو *cancel*  — إلغاء العملية الحالية";
        $lines[] = "   *رجوع*  أو *back*    — العودة للقائمة";
        $lines[] = "   *الحالة* أو *status*  — حسابي وحجزي الحالي";
        $lines[] = "   *مساعدة* أو *help*   — عرض هذا الدليل";

        return implode("\n", $lines);
    }

    /**
     * Personal status: who the bot thinks you are + your active reservation.
     * Replaces "is the bot remembering me correctly?" guesswork.
     */
    private function userStatus(WhatsAppSession $session): string
    {
        $user  = $session->user;
        $roles = $user->roles->pluck('role')
            ->map(fn ($r) => match ($r) {
                RoleTypes::SPACE_OWNER => '🅿️ مالك موقف',
                RoleTypes::CUSTOMER, RoleTypes::USER => '🚗 سائق',
                default => null,
            })
            ->filter()
            ->unique()
            ->implode(' • ');

        $lines = [
            "👤 *حسابك في ParkIQ*",
            '',
            "الاسم: *{$user->name}*",
            "رقم الواتساب: *+{$user->phone_number}*",
            "الأدوار: " . ($roles ?: '—'),
        ];

        if (in_array(RoleTypes::SPACE_OWNER, $user->roles->pluck('role')->all(), true)) {
            $parkCount = $user->ownedParks()->count();
            $lines[] = "المواقف المسجلة: *{$parkCount}*";
        }

        $banner = $this->activeReservationBanner($user);
        $lines[] = '';
        $lines[] = $banner ?? "_لا يوجد حجز فعّال حالياً._";

        $lines[] = '';
        $lines[] = "_لتعديل اسمك أرسل: *اسمي* ثم اسمك الجديد_";
        $lines[] = "_لرؤية كل الأوامر أرسل: *مساعدة*_";

        return implode("\n", $lines);
    }

    /**
     * Update the user's display name. Validates length; rejects blanks.
     */
    private function renameUser(WhatsAppSession $session, string $newName): string
    {
        $name = trim($newName);
        if ($name === '' || mb_strlen($name) > 100) {
            return "⚠️ اسم غير صالح. أرسل اسماً بين 1 و 100 حرف.";
        }

        $session->user->update(['name' => $name]);

        return "✅ تم تحديث اسمك إلى: *{$name}*";
    }

    /**
     * List the parks owned by the current user. Returns either:
     *   • a cta_url payload (single park, with a "Open in Maps" button), or
     *   • a plain text catalogue (zero parks, or many parks).
     *
     * @return string|array<string, mixed>
     */
    private function myParks(WhatsAppSession $session): string|array
    {
        $user = $session->user;

        $isOwner = in_array(RoleTypes::SPACE_OWNER, $user->roles->pluck('role')->all(), true);
        if (!$isOwner) {
            return "🚫 هذا الأمر متاح لمالكي المواقف فقط.\n"
                 . "_لتفعيل وضع مالك موقف أرسل: *تسجيل*_";
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Park> $parks */
        $parks = $user->ownedParks()->with('location')->latest('created_at')->get();

        if ($parks->isEmpty()) {
            return "ℹ️ لم تقم بإنشاء أي موقف بعد.\n"
                 . "أرسل *القائمة* ثم اختر *3* لإنشاء موقفك الأول.";
        }

        if ($parks->count() === 1) {
            /** @var Park $park */
            $park = $parks->first();
            $body = $this->renderParkLine($park, withIndex: null, withMapUrl: false);

            return [
                'type'     => 'cta_url',
                'body'     => "🅿️ *موقفك المسجّل:*\n\n" . $body,
                'cta_text' => '🗺️ عرض الموقع',
                'url'      => "https://www.google.com/maps?q={$park->lat},{$park->lng}",
            ];
        }

        $lines = ["🅿️ *مواقفك المسجّلة* (" . $parks->count() . "):", ''];
        foreach ($parks as $i => $park) {
            $lines[] = $this->renderParkLine($park, withIndex: $i + 1, withMapUrl: true);
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /**
     * One park, formatted for the parks-list output.
     */
    private function renderParkLine(Park $park, ?int $withIndex, bool $withMapUrl): string
    {
        $prefix = $withIndex !== null ? "*{$withIndex}.* " : '';
        $city   = $park->location?->city;

        $line  = "{$prefix}*{$park->name}*\n";
        $line .= "   السعة: {$park->capacity} • المتاح: {$park->free_spaces}\n";
        if ($city) {
            $line .= "   المدينة: {$city}\n";
        }
        if ($withMapUrl) {
            $line .= "   📍 https://www.google.com/maps?q={$park->lat},{$park->lng}";
        }

        return rtrim($line);
    }

    /**
     * Release the user's most recent ACTIVE reservation, refunding the slot.
     * Returns a localized message describing the outcome.
     */
    private function cancelActiveReservation(WhatsAppSession $session): string
    {
        $reserve = Reserve::where('user_id', $session->user->id)
            ->where('status', Reserve::STATUS_ACTIVE)
            ->latest('created_at')
            ->first();

        if (!$reserve) {
            return "ℹ️ لا يوجد حجز فعّال لإلغائه.\nأرسل 'القائمة' لرؤية القائمة.";
        }

        $reserve = $this->reservations->cancel($reserve);
        $park    = $reserve->park;

        return "✅ تم إلغاء حجزك في *{$park->name}* وإعادة المكان إلى المتاح.";
    }

    /**
     * Get-or-create a session row for this phone, linking it to a User if registered.
     */
    private function resolveSession(string $from): WhatsAppSession
    {
        $session = WhatsAppSession::firstOrNew(['phone' => $from]);

        if (!$session->exists) {
            $session->step = 'idle';
            $session->data = [];
        }

        if ($session->user_id === null) {
            $user = User::where('phone_number', $from)
                ->orWhere('phone_number', ltrim($from, '0'))
                ->first();

            // Only auto-link if the user has a bot-relevant role.
            if ($user) {
                $hasBotRole = $user->roles->contains(
                    fn ($r) => in_array($r->role, [
                        RoleTypes::SPACE_OWNER,
                        RoleTypes::CUSTOMER,
                        RoleTypes::USER,
                    ], true)
                );
                if ($hasBotRole) {
                    $session->user_id = $user->id;
                }
            }
        }

        $session->save();

        $session->load(['user', 'user.roles']);

        // Defensive: if the linked user no longer exists, clear the link.
        if ($session->user_id !== null && $session->user === null) {
            $session->update(['user_id' => null]);
            $session->load(['user', 'user.roles']);
        }

        return $session;
    }

    // ===============================
    // SEND MESSAGE TO META GRAPH API
    // ===============================
    private function sendMessage(string $to, string $message): void
    {
        $this->notifier->send($to, $message);
    }

    /**
     * Send an interactive "Call to Action" message: one tappable button that
     * opens an external URL. Body text appears above the button.
     *
     * Meta limits: display_text ≤ 20 chars, body text ≤ 1024 chars.
     */
    private function sendCtaUrl(string $to, string $body, string $ctaText, string $url): void
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken   = config('services.whatsapp.access_token');
        $apiVersion    = config('services.whatsapp.api_version', 'v18.0');

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'cta_url',
                'body'   => ['text' => mb_substr($body, 0, 1024)],
                'action' => [
                    'name'       => 'cta_url',
                    'parameters' => [
                        'display_text' => mb_substr($ctaText, 0, 20),
                        'url'          => $url,
                    ],
                ],
            ],
        ];

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages", $payload);

        if ($response->failed()) {
            Log::error('WhatsApp CTA send failed: ' . $response->body());
            // Fallback to a plain-text variant so the user still sees something.
            $this->sendMessage($to, $body . "\n\n" . $url);
        }
    }
}
