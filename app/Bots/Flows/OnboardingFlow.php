<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
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

    public function __construct(
        private readonly MenuRenderer $menu,
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
            'ask_role' => $this->handleRole($session, $message),
            'ask_name' => $this->handleName($session, $message),
            default    => OutboundReply::empty(),
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
            . "2️⃣  أملك موقفاً وأريد تسجيله 🅿️\n\n"
            . "_يمكنك تغيير دورك (صاحب كراج/زبون) لاحقاً بإرسال 8️⃣ في أي وقت._"
        );
    }

    private function handleRole(BotSession $session, string $message): OutboundReply
    {
        // Accept "١"/"٢" (Arabic) and "۱"/"۲" (Persian) too.
        $msg = trim(DigitNormalizer::toAscii($message));

        if ($msg !== '1' && $msg !== '2') {
            return OutboundReply::text("⚠️ الرجاء إرسال 1 أو 2.");
        }

        // Already registered — just toggle the role and bounce back to the menu.
        if ($session->getUser()) {
            return $this->grantRoleToExistingUser($session, $msg === '2');
        }

        // Brand-new user — create the account automatically using the
        // channel-native identifier. No confirmation prompt.
        return $this->createAccount($session, $msg === '2');
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
                ? "🅿️ تم تفعيل وضع مالك الموقف. لديك مواقف مسجلة بالفعل.\n\n"
                : "🅿️ ممتاز! تم تفعيل وضع مالك الموقف.\n\n")
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

        $user = User::create($attrs);

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
