<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
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
 *   confirm   → "We'll create an account with <recipient>. Confirm? نعم/لا"
 *     • نعم → create User with the chosen role(s) → done.
 *     • لا  → abort.
 *
 * The user is never asked for their name — the channel-native identifier
 * (phone for WhatsApp, chat_id for Telegram) is the only thing we need.
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

        if (in_array(mb_strtolower(trim($message)), ['cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'ask_role' => $this->handleRole($session, $message),
            'confirm'  => $this->handleConfirm($session, $message),
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
            . "كيف يمكنني خدمتك؟ أرسل رقماً:\n\n"
            . "1️⃣  أبحث عن موقف لسيارتي 🚗\n"
            . "2️⃣  أملك موقفاً وأريد تسجيله 🅿️\n\n"
            . "_يمكنك تغيير دورك (صاحب كراج/زبون) لاحقاً بإرسال *تسجيل* في أي وقت._"
        );
    }

    private function handleRole(BotSession $session, string $message): OutboundReply
    {
        $msg = trim($message);

        if ($msg !== '1' && $msg !== '2') {
            return OutboundReply::text("⚠️ الرجاء إرسال 1 أو 2.");
        }

        // Already registered — just toggle the role and bounce back to the menu.
        if ($session->getUser()) {
            return $this->grantRoleToExistingUser($session, $msg === '2');
        }

        // Brand-new user — show a confirmation prompt before creating the account.
        $session->update([
            'step'       => 'confirm',
            'data'       => ['role_choice' => $msg],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            "لإنشاء حسابك سنستخدم {$this->channelLabel($session)}:\n"
            . "*{$this->displayRecipient($session)}*\n\n"
            . "هل توافق على إنشاء الحساب بهذه البيانات؟\n"
            . "أرسل *نعم* للتأكيد أو *لا* للإلغاء."
        );
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

    private function handleConfirm(BotSession $session, string $message): OutboundReply
    {
        $msg     = mb_strtolower(trim($message));
        $confirm = ['نعم', 'yes', 'y', 'ok', 'موافق', 'موافقة'];
        $deny    = ['لا', 'no', 'n', 'إلغاء'];

        if (in_array($msg, $deny, true)) {
            $session->reset();
            return OutboundReply::text(
                "تم إلغاء إنشاء الحساب.\nأرسل *هلو* للبدء من جديد."
            );
        }

        if (!in_array($msg, $confirm, true)) {
            return OutboundReply::text(
                "⚠️ لم أفهم الإجابة.\n"
                . "سيتم إنشاء حساب باستخدام *{$this->displayRecipient($session)}*.\n"
                . "أرسل *نعم* للتأكيد أو *لا* للإلغاء."
            );
        }

        return $this->createAccount($session);
    }

    /**
     * Create the account using the channel-native identifier as the sole
     * identifier. Grants exactly one role — SPACE_OWNER for option 2,
     * CUSTOMER otherwise.
     */
    private function createAccount(BotSession $session): OutboundReply
    {
        $asOwner = ($session->getData()['role_choice'] ?? null) === '2';
        $role    = $asOwner ? RoleTypes::SPACE_OWNER : RoleTypes::CUSTOMER;

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
            'name'     => $this->generateDefaultName($recipient),
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

    private function channelLabel(BotSession $session): string
    {
        return $session->getChannel() === 'telegram'
            ? 'حساب تيليجرام الحالي'
            : 'رقم واتساب الحالي';
    }

    private function displayRecipient(BotSession $session): string
    {
        $recipient = $session->getRecipient();

        return $session->getChannel() === 'telegram'
            ? "Telegram: {$recipient}"
            : '📱 +' . ltrim($recipient, '+');
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
}
