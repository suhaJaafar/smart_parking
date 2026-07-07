<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Engine\ConversationEngine;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\MenuRenderer;
use App\Enums\RoleTypes;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First-contact flow for unknown users (no `User` row linked to this
 * channel session yet).
 *
 * Flow:
 *   ask_role  → "Are you a (1) Driver / (2) Park Owner?"
 *     • The account is then created automatically using the channel-native
 *       identifier (phone for WhatsApp, chat_id for Telegram).
 *   ask_name  → only reached when the channel reported no display name;
 *     asks the user once for their name (or *تخطي* to skip).
 *
 * Naming is tiered: adopt the channel-reported display name (Telegram
 * first/last name, WhatsApp profile name) when present; otherwise ask the
 * user once; otherwise fall back to a generated placeholder.
 */
class OnboardingFlow
{
    public const FLOW = 'onboarding';
    private const TTL_MINUTES = 10;

    /** Session step used while collecting a space owner's phone number. */
    private const PHONE_STEP = 'ask_phone';

    /** Callback ids for the share-permission prompt. */
    private const PHONE_YES = 'phone:yes';
    private const PHONE_NO  = 'phone:no';

    /** Shortest / longest plausible phone length (digits only). */
    private const PHONE_MIN_DIGITS = 7;
    private const PHONE_MAX_DIGITS = 15;

    public function __construct(
        private readonly MenuRenderer $menu,
        private readonly CoOwnerRequestFlow $coOwnerRequest,
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
            'ask_role'       => $this->handleRole($session, $message),
            self::PHONE_STEP => $this->handleOwnerPhone($session, $message),
            'ask_name'       => $this->handleName($session, $message),
            default          => OutboundReply::empty(),
        };
    }

    private function start(BotSession $session): OutboundReply
    {
        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'ask_role',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            "👋 أهلاً بك في *ParkIQ*!\n\n"
            . "كيف يمكنني خدمتك؟\n"
            . "أرسل موقعك الحالي لأستكشاف الكراجات القريبة منك\n"
            . "او أرسل رقماً:\n\n"
            . "1️⃣  أبحث عن موقف لسيارتي 🚗\n"
            . "2️⃣  أملك موقفاً وأريد تسجيله 📍\n"
            . "3️⃣  تسجيل دخول لكراج آخر؟ 🔑\n\n"
            . "_يمكنك تغيير دورك (صاحب كراج/زبون) لاحقاً بإرسال 8️⃣ في أي وقت._"
        );
    }

    private function handleRole(BotSession $session, string $message): OutboundReply
    {
        // Accept "١"/"٢" (Arabic) and "۱"/"۲" (Persian) too.
        $msg = trim(DigitNormalizer::toAscii($message));

        // Option 3 — ask to co-manage an existing garage. Hands off to a
        // dedicated flow that collects the requester's details and target
        // garage, then waits for the owner's approval from the dashboard.
        if ($msg === '3') {
            return $this->coOwnerRequest->begin($session);
        }

        if ($msg !== '1' && $msg !== '2') {
            return OutboundReply::text("⚠️ الرجاء إرسال 1 أو 2 أو 3.");
        }

        // Owner path (option 2) collects a phone number first — a space owner
        // must be reachable — then continues to account creation / role switch
        // and on to registering their park. The channel-native chat id is
        // always preserved.
        if ($msg === '2') {
            return $this->beginOwnerOnboarding($session);
        }

        // Driver path (option 1). Already registered — just toggle the role
        // and bounce back to the menu; otherwise create the account using the
        // channel-native identifier. No confirmation prompt.
        if ($session->getUser()) {
            return $this->grantRoleToExistingUser($session, asOwner: false);
        }

        return $this->createAccount($session, asOwner: false);
    }

    /**
     * Existing user re-runs onboarding to switch their role. Roles are
     * exclusive: switching to one detaches the other so the menu never
     * mixes owner/customer options.
     */
    private function grantRoleToExistingUser(BotSession $session, bool $asOwner): OutboundReply
    {
        $ownerRole    = Role::firstOrCreate(['role' => RoleTypes::SPACE_OWNER->value]);
        $customerRole = Role::firstOrCreate(['role' => RoleTypes::CUSTOMER->value]);

        $user = $session->getUser();

        // sync() replaces the user's role set entirely, guaranteeing exclusivity.
        $user->roles()->sync([
            $asOwner ? $ownerRole->id : $customerRole->id,
        ]);

        $hasParks = $asOwner && $user->ownedParks()->exists();
        $session->reset();

        $user = $user->fresh(['roles']);

        $header = $asOwner
            ? ($hasParks
                ? "📍 تم تفعيل وضع مالك الموقف. لديك مواقف مسجلة بالفعل.\n\n"
                : "📍 ممتاز! تم تفعيل وضع مالك الموقف.\n\n")
            : "✅ تم تفعيل وضع السائق.\n\n";

        return OutboundReply::text($header . $this->menu->for($user));
    }

    /**
     * Create the account using the channel-native identifier as the sole
     * identifier. Grants exactly one role — SPACE_OWNER when `$asOwner`,
     * CUSTOMER otherwise.
     */
    private function createAccount(BotSession $session, bool $asOwner): OutboundReply
    {
        // Tier 1: the channel already told us a real name → use it, no prompt.
        if ($this->resolveDisplayName($session) !== null) {
            return $this->finalizeAccount($session, $asOwner);
        }

        // Tier 2: no platform name → ask once, then remember it on the User.
        $session->update([
            'step'       => 'ask_name',
            'data'       => ['as_owner' => $asOwner],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            "📝 ما اسمك؟ سيظهر هذا الاسم لمالك الموقف عند وصولك.\n"
            . "_أرسل اسمك، أو أرسل *تخطي* لاستخدام اسم افتراضي._"
        );
    }

    /**
     * Entry point for the space-owner path (option 2).
     *
     * A park owner must be reachable, so we collect a phone number before
     * anything else. WhatsApp already carries the number (the wa_id), so the
     * gate is skipped there; on Telegram — where the account is keyed by
     * chat id and has no phone — we ask the owner to share their contact.
     * Once a usable number is on file we continue exactly as before: brand-new
     * owners create an account, existing users just switch role, and either
     * way they land on the owner menu to register their park.
     */
    private function beginOwnerOnboarding(BotSession $session): OutboundReply
    {
        if ($this->ownerPhoneRequired($session)) {
            $data = $session->getData();
            $data['as_owner'] = true;

            $session->update([
                'step'       => self::PHONE_STEP,
                'data'       => $data,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            return $this->ownerPhonePrompt();
        }

        // Phone already on file (or native to the channel) — proceed straight
        // to the normal owner path.
        return $session->getUser()
            ? $this->grantRoleToExistingUser($session, asOwner: true)
            : $this->createAccount($session, asOwner: true);
    }

    /**
     * Whether the owner path still needs a phone number.
     *
     * Existing users need one only when their stored number isn't usable.
     * A brand-new WhatsApp user carries their number as the wa_id, so only
     * brand-new Telegram (chat-id-only) accounts must share it.
     */
    private function ownerPhoneRequired(BotSession $session): bool
    {
        $user = $session->getUser();

        if ($user !== null) {
            return !$this->isPhoneUsable($user->phone_number);
        }

        if ($session->getChannel() === 'whatsapp'
            && $this->isPhoneUsable($session->getRecipient())) {
            return false;
        }

        return true;
    }

    /**
     * The yes / no share-permission prompt shown to a registering owner.
     */
    private function ownerPhonePrompt(): OutboundReply
    {
        return OutboundReply::buttons(
            body: "📱 لتسجيل موقفك نحتاج رقم هاتفك ليتمكن الزبائن وفريق الدعم من التواصل معك.\n\n"
                . "هل تسمح بمشاركة رقمك؟",
            options: [
                ['id' => self::PHONE_YES, 'title' => '✅ نعم، مشاركة رقمي'],
                ['id' => self::PHONE_NO,  'title' => '❌ لا'],
            ],
        );
    }

    /**
     * Handle one inbound message while collecting the owner's phone.
     *
     * The number is mandatory and can only be supplied by the owner sharing
     * their OWN contact via the native button (tagged by the parser with
     * {@see ConversationEngine::CONTACT_PAYLOAD_PREFIX}). Tapping "no", typing
     * digits, sharing someone else's contact, or any unrelated message
     * cancels registration — no owner account is ever created without a real,
     * self-shared number.
     */
    private function handleOwnerPhone(BotSession $session, string $message): OutboundReply
    {
        $raw = trim($message);

        // Owner agreed — surface the native "share contact" button.
        if ($raw === self::PHONE_YES) {
            return OutboundReply::requestContact(
                body: "اضغط الزر بالأسفل لمشاركة رقمك تلقائياً 👇",
                buttonLabel: '📱 مشاركة رقمي',
            );
        }

        // A genuine self-shared contact is the ONLY accepted input.
        if (str_starts_with($raw, ConversationEngine::CONTACT_PAYLOAD_PREFIX)) {
            $digits = substr($raw, strlen(ConversationEngine::CONTACT_PAYLOAD_PREFIX));

            if ($this->isPlausiblePhone($digits)) {
                return $this->onOwnerPhoneCaptured($session, $digits);
            }
        }

        // Anything else cancels registration — the number is required and
        // must be self-shared via the button. No account has been created or
        // role changed at this stage, so cancelling simply resets the session.
        $session->reset();

        return OutboundReply::text(
            "❌ تم إلغاء التسجيل.\n"
            . "لتسجيل موقفك يجب الضغط على *نعم* ثم مشاركة رقمك عبر الزر."
        );
    }

    /**
     * A usable phone has arrived. Store it, then continue the owner path:
     * an existing user just switches role; a brand-new owner stashes the
     * number so account creation persists it, then names / finalizes.
     */
    private function onOwnerPhoneCaptured(BotSession $session, string $digits): OutboundReply
    {
        $user = $session->getUser();

        if ($user !== null) {
            $user->forceFill(['phone_number' => $digits])->save();

            return $this->grantRoleToExistingUser($session, asOwner: true);
        }

        $data                 = $session->getData();
        $data['shared_phone'] = $digits;
        $data['as_owner']     = true;

        $session->update([
            'data'       => $data,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        // Reuse the same name-or-finalize tail the driver path uses, but with
        // the phone already captured so the created account carries it.
        if ($this->resolveDisplayName($session) !== null) {
            return $this->finalizeAccount($session, asOwner: true);
        }

        $session->update([
            'step'       => 'ask_name',
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            "📝 ما اسمك؟ سيظهر هذا الاسم عند إدارة موقفك.\n"
            . "_أرسل اسمك، أو أرسل *تخطي* لاستخدام اسم افتراضي._"
        );
    }

    /**
     * Whether a value already on the user record is a plausible phone.
     * Lenient about formatting (a leading "+", spaces or dashes are ignored).
     */
    private function isPhoneUsable(?string $phone): bool
    {
        if (blank($phone)) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return strlen((string) $digits) >= self::PHONE_MIN_DIGITS
            && strlen((string) $digits) <= self::PHONE_MAX_DIGITS;
    }

    /**
     * A digits-only sanity check on a freshly shared contact.
     */
    private function isPlausiblePhone(string $digits): bool
    {
        return ctype_digit($digits)
            && strlen($digits) >= self::PHONE_MIN_DIGITS
            && strlen($digits) <= self::PHONE_MAX_DIGITS;
    }

    /**
     * Second onboarding step — reached only when the channel reported no
     * display name. Adopt the typed name (or a generated default on
     * "skip"), then create the account. The name is fed through the same
     * carrier the channel uses so account creation stays single-path.
     */
    private function handleName(BotSession $session, string $message): OutboundReply
    {
        $asOwner = (bool) ($session->getData()['as_owner'] ?? false);
        $raw     = trim($message);

        // Anything but an explicit skip is treated as the chosen name.
        if (!in_array(mb_strtolower($raw), ['تخطي', 'skip'], true)) {
            $name = $this->sanitizeName($raw);
            if ($name === null) {
                return OutboundReply::text(
                    "⚠️ اسم غير صالح. أرسل اسماً بين 1 و 100 حرف، أو *تخطي*."
                );
            }
            $session->setProfileName($name);
        }

        return $this->finalizeAccount($session, $asOwner);
    }

    /**
     * Persist the account + role, link the session, and emit the menu.
     * Shared tail of both the immediate (named) and ask-once paths.
     */
    private function finalizeAccount(BotSession $session, bool $asOwner): OutboundReply
    {
        $role = $asOwner ? RoleTypes::SPACE_OWNER : RoleTypes::CUSTOMER;

        $user = $this->createUserForSession($session, $role);

        $session->update([
            'user_id'    => $user->id,
            'flow'       => null,
            'step'       => 'idle',
            'data'       => [],
            'expires_at' => null,
        ]);

        $header = $asOwner
            ? "✅ تم إنشاء حسابك! تم تفعيل وضع مالك الموقف.\n\n"
            : "✅ تم إنشاء حسابك بنجاح!\n\n";

        return OutboundReply::text($header . $this->menu->for($user));
    }

    /**
     * Silently provision a CUSTOMER account from a session — no prompts,
     * no role question, no menu emission. Used by shortcut paths (e.g.
     * unknown user shares their location and we want to jump straight to
     * nearest-park results without any onboarding back-and-forth).
     *
     * Returns the freshly-created User with roles loaded.
     */
    public function createCustomerSilently(BotSession $session): User
    {
        return $this->createUserForSession($session, RoleTypes::CUSTOMER);
    }

    /**
     * Shared user-provisioning primitive — single source of truth so the
     * confirm-flow and the silent shortcut produce identical user rows.
     *
     * The session's channel decides which identifier column the
     * recipient goes into (`phone_number` for WhatsApp, `telegram_chat_id`
     * for Telegram). The other column stays NULL.
     */
    private function createUserForSession(BotSession $session, RoleTypes $role): User
    {
        $recipient = $session->getRecipient();

        $attrs = [
            'name'     => $this->resolveDisplayName($session) ?? $this->generateDefaultName($recipient),
            'email'    => "{$recipient}@{$session->getChannel()}.parkiq.local",
            'password' => $this->unusablePassword(),
        ];

        if ($session->getChannel() === 'telegram') {
            $attrs['telegram_chat_id'] = $recipient;
        } else {
            $attrs['phone_number'] = $recipient;
        }

        // A phone shared during owner onboarding is stored regardless of
        // channel, so a Telegram owner gets a real phone_number while still
        // keeping their chat id as the primary identifier.
        $sharedPhone = $session->getData()['shared_phone'] ?? null;
        if (is_string($sharedPhone) && $sharedPhone !== '') {
            $attrs['phone_number'] = $sharedPhone;
        }

        $user = User::create($attrs);

        if ($session->getChannel() === 'telegram') {
            // Record the creating chat as the primary linked device so a
            // second phone can later be attached to the same account.
            $user->telegramAccounts()->create([
                'chat_id'    => $recipient,
                'is_primary' => true,
            ]);
        }

        $roleRow = Role::firstOrCreate(['role' => $role->value]);
        $user->roles()->sync([$roleRow->id]);

        return $user->load('roles');
    }

    /**
     * Bot-provisioned accounts have no usable password — their auth path
     * is the channel itself. We still hash a CSPRNG secret so the column
     * is never empty and constant-time comparisons in any future password
     * check cannot succeed by accident.
     */
    private function unusablePassword(): string
    {
        return Hash::make(Str::password(40));
    }

    /**
     * Friendly placeholder display name built from the identifier tail.
     * Users can rename themselves later via the *اسمي* command.
     */
    private function generateDefaultName(string $recipient): string
    {
        $digits = preg_replace('/\D/', '', $recipient);
        $tail   = mb_substr($digits, -4);
        return $tail !== '' ? "سائق {$tail}" : 'سائق';
    }

    /**
     * The channel-reported display name (Telegram first/last name,
     * WhatsApp profile name), cleaned for storage. Null when the channel
     * sent nothing usable.
     */
    private function resolveDisplayName(BotSession $session): ?string
    {
        return $this->sanitizeName($session->getProfileName());
    }

    /**
     * Normalise a free-form name: strip control characters, collapse
     * inner whitespace, and bound the length. Returns null when nothing
     * usable remains.
     */
    private function sanitizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $clean = preg_replace('/\p{C}+/u', '', $name);
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $clean));

        return $clean !== '' ? mb_substr($clean, 0, 100) : null;
    }
}
