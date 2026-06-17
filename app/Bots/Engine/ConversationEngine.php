<?php

namespace App\Bots\Engine;

use App\Bots\Channels\Telegram\TelegramLoginService;
use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Flows\CarEntryFlow;
use App\Bots\Flows\CarExitFlow;
use App\Bots\Flows\NearbyParksFlow;
use App\Bots\Flows\OnboardingFlow;
use App\Bots\Flows\ParkCreationFlow;
use App\Bots\Flows\PreBookingFlow;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\MenuRenderer;
use App\Enums\RoleTypes;
use App\Models\Park;
use App\Models\Reserve;
use App\Services\ReservationService;

/**
 * Channel-agnostic brain of the bot.
 *
 * Both {@see \App\Bots\Channels\WhatsApp\WhatsAppController} and
 * {@see \App\Bots\Channels\Telegram\TelegramController} delegate to this
 * single engine. Each controller is responsible only for:
 *   • normalising the inbound payload into (BotSession, text, type)
 *   • taking the {@see OutboundReply} this engine returns and putting it
 *     on the wire via their channel transport.
 *
 * Everything else — escape commands, flow dispatch, idle menu, help sheet,
 * rename, status, park listings, location shortcut, cancellation — lives
 * here, in one place, identical for every channel.
 */
class ConversationEngine
{
    /** Inbound message types the engine understands. */
    public const TYPE_TEXT     = 'text';
    public const TYPE_LOCATION = 'location';

    /** Cancel / back / restart — anything that should drop the user back to the menu. */
    private const ESCAPE_COMMANDS = [
        '0', 'cancel', 'الغاء', 'إلغاء',
        'menu', 'القائمة',
        '00', 'back', 'رجوع',
        'restart', 'اعادة', 'إعادة',
    ];

    /** Re-run onboarding (switch role). */
    private const RESTART_ONBOARDING_COMMANDS = [
        'register', 'تسجيل', 'switch', 'تبديل', 'change role', 'تغيير الدور', '8️⃣',
    ];

    /** Customer wants to release their currently held slot. */
    private const CANCEL_RESERVATION_COMMANDS = [
        'cancel reservation', 'cancel my reservation',
        'الغاء حجزي', 'إلغاء حجزي', 'الغاء الحجز', 'إلغاء الحجز',
    ];

    /** Owner wants to see the parks they already own. */
    private const MY_PARKS_COMMANDS = [
        'my park', 'my parks', 'parks', 'parks list',
        'موقفي', 'مواقفي', 'مواقف', 'مواقف كراجي', 'مواقف الكراجات',
    ];

    /** Show the command cheat-sheet at any time. */
    private const HELP_COMMANDS = [
        'help', '?', 'مساعدة', 'الاوامر', 'الأوامر', 'commands',
    ];

    /** Show the user's profile + any active reservation. */
    private const STATUS_COMMANDS = [
        'status', 'الحالة', 'حسابي', 'my status', 'profile',
    ];

    /** Owner asks for a one-time code to sign in to the web dashboard. */
    private const DASHBOARD_LOGIN_COMMANDS = [
        'login', 'log in', 'dashboard', 'web', 'signin', 'sign in',
        'تسجيل الدخول', 'دخول', 'اللوحة', 'لوحة التحكم',
    ];

    public function __construct(
        private readonly OnboardingFlow $onboardingFlow,
        private readonly CarEntryFlow $carEntryFlow,
        private readonly CarExitFlow $carExitFlow,
        private readonly NearbyParksFlow $nearbyParksFlow,
        private readonly ParkCreationFlow $parkFlow,
        private readonly PreBookingFlow $preBookingFlow,
        private readonly MenuRenderer $menu,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * Resolve a single inbound message into an outbound reply.
     *
     * `$type` MUST be one of the TYPE_* constants. `$text` is the already-
     * normalised payload (for `TYPE_LOCATION` this is "lat,lng").
     */
    public function handle(BotSession $session, string $text, string $type): OutboundReply
    {
        $msg = trim($text);

        /*
         * Add ability to usr to send numbers in arabic/english ...
         */
        if ($type === self::TYPE_TEXT) {
            $msg = DigitNormalizer::toAscii($msg);
        }

        // Telegram users naturally type slash commands ("/start", "/help").
        // Strip a single leading "/" so they map onto the same vocabulary
        // as WhatsApp users typing plain words ("start", "help").
        if ($type === self::TYPE_TEXT && str_starts_with($msg, '/') && !str_contains($msg, ' ')) {
            $msg = ltrim($msg, '/');
        }

        $lower = mb_strtolower($msg);

        // -------------------------------------------------------------
        // LOCATION SHORTCUT
        // A bare location share is treated as "find me the nearest park"
        // — no questions asked. For an unknown user we silently provision
        // a CUSTOMER account first so the reservation step downstream
        // has a real user to attach.
        //
        // Active in two states:
        //   • no flow at all (idle registered user)
        //   • mid-onboarding (the location implicitly answers "I want
        //     to find a park as a driver", overriding the role prompt)
        //
        // Deliberately NOT active in `NearbyParksFlow` (already at
        // ask_location — the flow consumes it) or `ParkCreationFlow`
        // (owner is registering the location of their own park).
        // -------------------------------------------------------------
        $currentFlow  = $session->getFlow();
        $allowShortcut = $currentFlow === null || $currentFlow === OnboardingFlow::FLOW;

        if ($type === self::TYPE_LOCATION && $msg !== '' && $allowShortcut) {
            return $this->locationShortcut($session, $msg);
        }

        // -------------------------------------------------------------
        // UNIVERSAL ESCAPE COMMANDS
        // These work even when the user has no account yet, so a user
        // who got stuck mid-onboarding can always type /start or /cancel
        // to bail out and begin fresh.
        // -------------------------------------------------------------
        $isFreshStart = in_array($lower, ['start', 'menu', 'القائمة', 'hello', 'hi', 'مرحبا', 'هلو'], true);
        $isCancel     = in_array($lower, ['0', 'cancel', 'الغاء', 'إلغاء', '00', 'back', 'رجوع'], true);

        if (!$session->getUser() && ($isFreshStart || $isCancel)) {
            $session->reset();
            return $this->startFlow($session->fresh(), OnboardingFlow::class);
        }

        // -------------------------------------------------------------
        // GLOBAL COMMANDS (registered users only)
        // -------------------------------------------------------------
        if ($session->getUser()) {
            if (in_array($lower, self::HELP_COMMANDS, true)) {
                return OutboundReply::text($this->helpSheet($session));
            }

            if (in_array($lower, self::STATUS_COMMANDS, true)) {
                return OutboundReply::text($this->userStatus($session));
            }

            if (in_array($lower, self::DASHBOARD_LOGIN_COMMANDS, true)) {
                return $this->issueDashboardLoginCode($session);
            }

            if (in_array($lower, self::CANCEL_RESERVATION_COMMANDS, true)) {
                return OutboundReply::text($this->cancelActiveReservation($session));
            }

            if (in_array($lower, self::MY_PARKS_COMMANDS, true)) {
                return $this->myParks($session);
            }

            // Rename command: "اسمي <new name>" or "name <new name>".
            if (preg_match('/^(?:اسمي|name)\s+(.+)$/iu', $msg, $m)) {
                return OutboundReply::text($this->renameUser($session, $m[1]));
            }

            // Bare "اسمي" / "name" with no value → show usage hint.
            if (in_array($lower, ['اسمي', 'name', 'rename'], true)) {
                return OutboundReply::text(
                    "✍️ لتعديل اسمك أرسل:\n*اسمي* ثم اسمك الجديد\n"
                    . "_مثال: اسمي أحمد محمد_"
                );
            }

            if (in_array($lower, self::RESTART_ONBOARDING_COMMANDS, true)) {
                $session->reset();
                return $this->startFlow($session->fresh(['user', 'user.roles']), OnboardingFlow::class);
            }

            if (in_array($lower, self::ESCAPE_COMMANDS, true)) {
                $session->reset();
                return $this->handleIdle($session->fresh(['user', 'user.roles']), 'القائمة');
            }
        }

        // -------------------------------------------------------------
        // MID-FLOW DISPATCH
        // -------------------------------------------------------------
        $reply = match ($session->getFlow()) {
            OnboardingFlow::FLOW   => $this->onboardingFlow->handle($session, $msg),
            CarEntryFlow::FLOW     => $this->carEntryFlow->handle($session, $msg),
            CarExitFlow::FLOW      => $this->carExitFlow->handle($session, $msg),
            NearbyParksFlow::FLOW  => $this->nearbyParksFlow->handle($session, $msg),
            PreBookingFlow::FLOW   => $this->preBookingFlow->handle($session, $msg),
            ParkCreationFlow::FLOW => $this->parkFlow->handle($session, $msg),
            default                => null,
        };

        if ($reply !== null) {
            return $reply;
        }

        // -------------------------------------------------------------
        // IDLE — top-level menu commands
        // -------------------------------------------------------------
        return $this->handleIdle($session, $msg);
    }

    // =====================================================================
    // IDLE MENU
    // =====================================================================

    private function handleIdle(BotSession $session, string $msg): OutboundReply
    {
        // Unknown user → start onboarding immediately on any message.
        if (!$session->getUser()) {
            return $this->startFlow($session, OnboardingFlow::class);
        }

        $lower = mb_strtolower($msg);

        if (in_array($lower, ['0', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text(
                "تم إلغاء العملية.\nأرسل *القائمة* للقائمة أو *مساعدة* للأوامر."
            );
        }

        $user       = $session->getUser();
        $roles      = $user->roles->pluck('role')->all();
        $isOwner    = in_array(RoleTypes::SPACE_OWNER, $roles, true);
        $isCustomer = in_array(RoleTypes::CUSTOMER, $roles, true)
                   || in_array(RoleTypes::USER, $roles, true);

        if ($isOwner) {
            $ownerMap = [
                '1'         => CarEntryFlow::class,
                '2'         => CarExitFlow::class,
                'enter car' => CarEntryFlow::class,
                'exit car'  => CarExitFlow::class,
            ];
            if (isset($ownerMap[$lower])) {
                return $this->startFlow($session, $ownerMap[$lower]);
            }

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

        if ($isCustomer) {
            $customerMap = [
                '4'         => NearbyParksFlow::class,
                'nearby'    => NearbyParksFlow::class,
                'find park' => NearbyParksFlow::class,
                '5'             => PreBookingFlow::class,
                'pre booking'   => PreBookingFlow::class,
                'pre-booking'   => PreBookingFlow::class,
                'حجز مسبق'      => PreBookingFlow::class,
            ];
            if (isset($customerMap[$lower])) {
                return $this->startFlow($session, $customerMap[$lower]);
            }
        }

        if (in_array($lower, ['hello', 'hi', 'مرحبا', 'هلو', 'menu', 'القائمة', 'start'], true)) {
            return OutboundReply::text($this->menu->for($user));
        }

        // Numeric shortcuts for the "useful commands" block of the menu.
        // Only resolved at idle so they never shadow a flow's numeric input.
        if ($lower === '6') {
            return OutboundReply::text($this->helpSheet($session));
        }

        if ($lower === '7') {
            return OutboundReply::text($this->userStatus($session));
        }

        if ($lower === '8') {
            $session->reset();
            return $this->startFlow($session->fresh(['user', 'user.roles']), OnboardingFlow::class);
        }

        return OutboundReply::text(
            "عذراً، لم أفهم رسالتك 😊\n"
            . "أرسل *مساعدة* لرؤية كل الأوامر، أو *القائمة* للقائمة."
        );
    }

    // =====================================================================
    // FLOW BOOTSTRAP
    // =====================================================================

    /**
     * @param class-string $flowClass
     */
    private function startFlow(BotSession $session, string $flowClass): OutboundReply
    {
        $session->update([
            'flow' => $flowClass::FLOW,
            'step' => 'idle',
            'data' => [],
        ]);

        $fresh = $session->fresh(['user']);

        return match ($flowClass) {
            OnboardingFlow::class   => $this->onboardingFlow->handle($fresh, ''),
            CarEntryFlow::class     => $this->carEntryFlow->handle($fresh, ''),
            CarExitFlow::class      => $this->carExitFlow->handle($fresh, ''),
            NearbyParksFlow::class  => $this->nearbyParksFlow->handle($fresh, ''),
            PreBookingFlow::class   => $this->preBookingFlow->handle($fresh, ''),
            ParkCreationFlow::class => $this->parkFlow->handle($fresh, ''),
        };
    }

    // =====================================================================
    // LOCATION SHORTCUT
    // =====================================================================

    /**
     * Bare location share — jump straight to nearest-park results. For an
     * unknown user we silently provision a CUSTOMER account first.
     *
     * The session is moved directly into `nearby_parks/ask_location` so
     * the coordinates are consumed by `NearbyParksFlow::showResults()` —
     * bypassing the `start()` prompt that would otherwise re-ask the user
     * to share their location.
     */
    private function locationShortcut(BotSession $session, string $latLng): OutboundReply
    {
        if (!$session->getUser()) {
            $user = $this->onboardingFlow->createCustomerSilently($session);
            $session->update(['user_id' => $user->id]);
            $session->setRelation('user', $user);
        }

        $session->update([
            'flow'       => NearbyParksFlow::FLOW,
            'step'       => 'ask_location',
            'data'       => [],
            'expires_at' => now()->addMinutes(10),
        ]);

        return $this->nearbyParksFlow->handle(
            $session->fresh(['user', 'user.roles']),
            $latLng,
        );
    }

    // =====================================================================
    // HELP / STATUS / RENAME / CANCEL / MY PARKS
    // =====================================================================

    /**
     * Issue a one-time code the owner types into the web dashboard to sign
     * in. Telegram accounts have no phone number, so the code is delivered
     * right here in the chat. Gated to the Telegram channel and to
     * dashboard-eligible roles (SPACE_OWNER / SUPER_ADMIN).
     */
    private function issueDashboardLoginCode(BotSession $session): OutboundReply
    {
        if ($session->getChannel() !== 'telegram') {
            return OutboundReply::text(
                "تسجيل الدخول إلى اللوحة عبر هذه الخطوة متاح لمستخدمي تيليجرام فقط."
            );
        }

        $user  = $session->getUser();
        $roles = $user->roles->pluck('role')->all();
        $eligible = in_array(RoleTypes::SPACE_OWNER, $roles, true)
                 || in_array(RoleTypes::SUPER_ADMIN, $roles, true);

        if (!$eligible) {
            return OutboundReply::text(
                "🔒 لوحة التحكم مخصّصة لأصحاب المواقف.\n"
                . "أرسل 8️⃣ لتفعيل وضع مالك الموقف أولاً."
            );
        }

        $login  = app(TelegramLoginService::class);
        $chatId = $session->getRecipient();

        if ($login->isOnCooldown($chatId)) {
            return OutboundReply::text(
                "⏳ لقد طلبت رمزاً للتو. انتظر دقيقة ثم حاول مرة أخرى."
            );
        }

        $code = $login->issue($chatId);
        $ttl  = (int) (TelegramLoginService::TTL_SECONDS / 60);

        return OutboundReply::text(
            "🔐 *رمز الدخول إلى لوحة ParkIQ:*\n\n"
            . "`{$code}`\n\n"
            . "أدخل هذا الرمز في صفحة \"تسجيل الدخول عبر تيليجرام\" على لوحة التحكم.\n"
            . "صالح لمدة {$ttl} دقائق. لا تشاركه مع أحد."
        );
    }

    private function helpSheet(BotSession $session): string
    {
        $user       = $session->getUser();
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
            $lines[] = "   *تسجيل الدخول*  رمز الدخول إلى لوحة التحكم على الويب";
            $lines[] = '';
        }

        if ($isCustomer) {
            $lines[] = "🚗 *سائق:*";
            $lines[] = "   *4*  البحث عن أقرب موقف";
            $lines[] = "   *5*  حجز موقف مسبق ودفع مسبق";
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
        $lines[] = "   *0*  أو *الغاء*  — إلغاء العملية الحالية";
        $lines[] = "   *00*  أو *رجوع*    — العودة للقائمة";
        $lines[] = "   *الحالة* أو *status*  — حسابي وحجزي الحالي";
        $lines[] = "   *مساعدة* أو *help*   — عرض هذا الدليل";

        return implode("\n", $lines);
    }

    private function userStatus(BotSession $session): string
    {
        $user  = $session->getUser();
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
        ];

        if ($user->phone_number) {
            $lines[] = "رقم الواتساب: *+{$user->phone_number}*";
        }
        if ($user->telegram_chat_id) {
            $lines[] = "تيليجرام: *{$user->telegram_chat_id}*";
        }
        $lines[] = "الأدوار: " . ($roles ?: '—');

        if (in_array(RoleTypes::SPACE_OWNER, $user->roles->pluck('role')->all(), true)) {
            $parkCount = $user->ownedParks()->count();
            $lines[] = "المواقف المسجلة: *{$parkCount}*";
        }

        $banner = $this->menu->activeReservationBanner($user);
        $lines[] = '';
        $lines[] = $banner ?? "_لا يوجد حجز فعّال حالياً._";

        $lines[] = '';
        $lines[] = "_لتعديل اسمك أرسل: *اسمي* ثم اسمك الجديد_";
        $lines[] = "_لرؤية كل الأوامر أرسل: *مساعدة*_";

        return implode("\n", $lines);
    }

    private function renameUser(BotSession $session, string $newName): string
    {
        $name = trim($newName);
        if ($name === '' || mb_strlen($name) > 100) {
            return "⚠️ اسم غير صالح. أرسل اسماً بين 1 و 100 حرف.";
        }

        $session->getUser()->update(['name' => $name]);

        return "✅ تم تحديث اسمك إلى: *{$name}*";
    }

    private function cancelActiveReservation(BotSession $session): string
    {
        $reserve = Reserve::where('user_id', $session->getUser()->id)
            ->where('status', Reserve::STATUS_START)
            ->latest('created_at')
            ->first();

        if (!$reserve) {
            $hasActive = Reserve::where('user_id', $session->getUser()->id)
                ->where('status', Reserve::STATUS_ACTIVE)
                ->exists();

            return $hasActive
                ? "🚗 سيارتك حالياً داخل الموقف — لا يمكن إلغاء الحجز بعد دخول السيارة."
                : "ℹ️ لا يوجد حجز فعّال لإلغائه.\nأرسل 'القائمة' لرؤية القائمة.";
        }

        $reserve = $this->reservations->cancel($reserve);
        $park    = $reserve->park;

        return "✅ تم إلغاء حجزك في *{$park->name}* وإعادة المكان إلى المتاح.";
    }

    /**
     * Owner park listing. Returns:
     *   • a cta_url payload (exactly one park, with a "Open in Maps" button), or
     *   • a plain text catalogue (zero parks, or many parks).
     */
    private function myParks(BotSession $session): OutboundReply
    {
        $user = $session->getUser();

        $isOwner = in_array(RoleTypes::SPACE_OWNER, $user->roles->pluck('role')->all(), true);
        if (!$isOwner) {
            return OutboundReply::text(
                "🚫 هذا الأمر متاح لمالكي المواقف فقط.\n"
                . "_لتفعيل وضع مالك موقف أرسل: *تسجيل*_"
            );
        }

        $parks = $user->ownedParks()->with('location')->latest('created_at')->get();

        if ($parks->isEmpty()) {
            return OutboundReply::text(
                "ℹ️ لم تقم بإنشاء أي موقف بعد.\n"
                . "أرسل *القائمة* ثم اختر *3* لإنشاء موقفك الأول."
            );
        }

        if ($parks->count() === 1) {
            /** @var Park $park */
            $park = $parks->first();
            $body = $this->parkInfo($park, withIndex: null, withMapUrl: false);

            return OutboundReply::ctaUrl(
                body:    "🅿️ *موقفك المسجّل:*\n\n" . $body,
                ctaText: '🗺️ عرض الموقع',
                url:     "https://www.google.com/maps?q={$park->lat},{$park->lng}",
            );
        }

        $lines = ["🅿️ *مواقفك المسجّلة* (" . $parks->count() . "):", ''];
        foreach ($parks as $i => $park) {
            $lines[] = $this->parkInfo($park, withIndex: $i + 1, withMapUrl: true);
            $lines[] = '';
        }

        return OutboundReply::text(rtrim(implode("\n", $lines)));
    }

    private function parkInfo(Park $park, ?int $withIndex, bool $withMapUrl): string
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
}
