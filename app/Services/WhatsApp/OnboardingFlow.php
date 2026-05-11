<?php

namespace App\Services\WhatsApp;

use App\Enums\RoleTypes;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppSession;

/**
 * First-contact flow for unknown phone numbers.
 *
 * Flow:
 *   ask_role  → "Are you a (1) Driver / (2) Park Owner?"
 *   confirm   → "We'll create an account with +<phone>. Confirm? نعم/لا"
 *     • نعم → create User with the chosen role(s) → done.
 *     • لا  → abort.
 *
 * The user is never asked for their name — the WhatsApp phone number is
 * the only identifier we need. The `name` column on `users` is nullable.
 */
class OnboardingFlow
{
    public const FLOW = 'onboarding';
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly MenuRenderer $menu,
    ) {}

    public function handle(WhatsAppSession $session, string $message): ?string
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
            'ask_role' => $this->handleRole($session, $message),
            'confirm'  => $this->handleConfirm($session, $message),
            default    => null,
        };
    }

    private function start(WhatsAppSession $session): string
    {
        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'ask_role',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return "👋 أهلاً بك في *ParkIQ*!\n\n"
             . "كيف يمكنني خدمتك؟ أرسل رقماً:\n\n"
             . "1️⃣  أبحث عن موقف لسيارتي 🚗\n"
             . "2️⃣  أملك موقفاً وأريد تسجيله 🅿️\n\n"
             . "_يمكنك تغيير دورك (صاحب كراج/زبون) لاحقاً بإرسال *تسجيل* في أي وقت._";
    }

    private function handleRole(WhatsAppSession $session, string $message): string
    {
        $msg = trim($message);

        if ($msg !== '1' && $msg !== '2') {
            return "⚠️ الرجاء إرسال 1 أو 2.";
        }

        // Already registered — just toggle the role and bounce back to the menu.
        // No account creation, no confirmation step needed.
        if ($session->user) {
            return $this->grantRoleToExistingUser($session, $msg === '2');
        }

        // New phone — show a confirmation prompt before creating the account.
        $session->update([
            'step'       => 'confirm',
            'data'       => ['role_choice' => $msg],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $phone = $this->displayPhone($session->phone);

        return "لإنشاء حسابك سنستخدم رقم واتساب الحالي:\n"
             . "📱 *{$phone}*\n\n"
             . "هل توافق على إنشاء الحساب بهذا الرقم؟\n"
             . "أرسل *نعم* للتأكيد أو *لا* للإلغاء.";
    }

    /**
     * Existing user re-runs onboarding to switch / add a role.
    /**
     * Existing user re-runs onboarding to switch their role.
     * Roles are exclusive: switching to one detaches the other so the menu
     * never mixes owner/customer options.
     */
    private function grantRoleToExistingUser(WhatsAppSession $session, bool $asOwner): string
    {
        $ownerRole    = Role::firstOrCreate(['role' => RoleTypes::SPACE_OWNER->value]);
        $customerRole = Role::firstOrCreate(['role' => RoleTypes::CUSTOMER->value]);

        // sync() replaces the user's role set entirely, guaranteeing exclusivity.
        $session->user->roles()->sync([
            $asOwner ? $ownerRole->id : $customerRole->id,
        ]);

        $hasParks = $asOwner && $session->user->ownedParks()->exists();
        $session->reset();

        $user = $session->user->fresh(['roles']);

        $header = $asOwner
            ? ($hasParks
                ? "🅿️ تم تفعيل وضع مالك الموقف. لديك مواقف مسجلة بالفعل.\n\n"
                : "🅿️ ممتاز! تم تفعيل وضع مالك الموقف.\n\n")
            : "✅ تم تفعيل وضع السائق.\n\n";

        return $header . $this->menu->for($user);
    }

    private function handleConfirm(WhatsAppSession $session, string $message): string
    {
        $msg     = mb_strtolower(trim($message));
        $confirm = ['نعم', 'yes', 'y', 'ok', 'موافق', 'موافقة'];
        $deny    = ['لا', 'no', 'n', 'إلغاء'];

        if (in_array($msg, $deny, true)) {
            $session->reset();
            return "تم إلغاء إنشاء الحساب.\n"
                 . "أرسل *هلو* للبدء من جديد.";
        }

        if (!in_array($msg, $confirm, true)) {
            $phone = $this->displayPhone($session->phone);
            return "⚠️ لم أفهم الإجابة.\n"
                 . "سيتم إنشاء حساب برقم *{$phone}*.\n"
                 . "أرسل *نعم* للتأكيد أو *لا* للإلغاء.";
        }

        return $this->createAccount($session);
    }

    /**
     * Create the account using the WhatsApp phone as the sole identifier.
     * Grants exactly one role — SPACE_OWNER if the user picked option 2 at
     * the role-picker step, CUSTOMER otherwise. Roles are mutually exclusive
     * so the menu only shows the options relevant to the current role.
     */
    private function createAccount(WhatsAppSession $session): string
    {
        $asOwner = ($session->data['role_choice'] ?? null) === '2';
        $role    = $asOwner ? RoleTypes::SPACE_OWNER : RoleTypes::CUSTOMER;

        $user = $this->createUserWithRole($session->phone, $role);

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

        return $header . $this->menu->for($user);
    }

    /**
     * Silently provision a CUSTOMER account from a WhatsApp phone — no
     * prompts, no role question, no menu emission. Used by shortcut paths
     * (e.g. unknown phone shares their location and we want to jump straight
     * to nearest-park results without any onboarding back-and-forth).
     *
     * Returns the freshly-created User with roles loaded.
     */
    public function createCustomerSilently(string $phone): User
    {
        return $this->createUserWithRole($phone, RoleTypes::CUSTOMER);
    }

    /**
     * Shared user-provisioning primitive. Single source of truth so the
     * confirm-flow and the silent shortcut produce identical user rows.
     */
    private function createUserWithRole(string $phone, RoleTypes $role): User
    {
        $user = User::create([
            'name'         => $this->generateDefaultName($phone),
            'email'        => "{$phone}@whatsapp.parkiq.local",
            'password'     => bcrypt(str()->random(32)),
            'phone_number' => $phone,
        ]);

        $roleRow = Role::firstOrCreate(['role' => $role->value]);
        $user->roles()->sync([$roleRow->id]);

        return $user->load('roles');
    }

    /**
     * Format a raw WhatsApp phone (digits only, country code prefixed) as +xxxx.
     */
    private function displayPhone(string $phone): string
    {
        return '+' . ltrim($phone, '+');
    }

    /**
     * Generate a friendly placeholder display name from the user's phone.
     * Users can rename themselves later via a future *اسمي* command.
     * Example: phone "9647775926512" → "سائق 6512".
     */
    private function generateDefaultName(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        $tail   = mb_substr($digits, -4);
        return $tail !== '' ? "سائق {$tail}" : 'سائق';
    }
}
