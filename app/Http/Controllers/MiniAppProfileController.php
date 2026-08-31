<?php

namespace App\Http\Controllers;

use App\Enums\RoleTypes;
use App\Http\Requests\SwitchRoleRequest;
use App\Http\Resources\RegisterResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

/**
 * Account-level actions the Mini App needs for the signed-in user.
 *
 * Role switching mirrors the bot's onboarding exactly: roles are
 * **exclusive**, so becoming an owner detaches the driver role and vice
 * versa. `sync()` (not `attach()`) is what guarantees that, and it is the same
 * call {@see \App\Bots\Flows\OnboardingFlow::grantRoleToExistingUser} makes —
 * a user who switches in chat and a user who switches in the Mini App end up
 * with identical rows.
 */
class MiniAppProfileController extends Controller
{
    /**
     * Switch the caller between driver (CUSTOMER) and garage owner
     * (SPACE_OWNER).
     *
     * A garage owner must be reachable, so a phone number is required before
     * the owner role is granted — the same gate the bot applies before it lets
     * anyone register a park. The Mini App collects it as text because a
     * WebView cannot trigger Telegram's share-contact keyboard.
     */
    public function switchRole(SwitchRoleRequest $request): JsonResponse
    {
        $user = $request->user();
        $asOwner = $request->validated('role') === 'owner';

        if ($asOwner) {
            $phone = $request->normalizedPhone() ?? $user->phone_number;

            if (! $this->isPhoneUsable($phone)) {
                return response()->json([
                    'message' => 'رقم الهاتف مطلوب لتسجيلك كمالك موقف.',
                    'errors'  => ['phone_number' => ['أدخل رقم هاتف صحيح (7 إلى 15 رقماً).']],
                ], 422);
            }

            $user->forceFill(['phone_number' => $phone])->save();
        }

        $role = Role::firstOrCreate([
            'role' => ($asOwner ? RoleTypes::SPACE_OWNER : RoleTypes::CUSTOMER)->value,
        ]);

        // sync() replaces the whole set — this is what keeps the two roles
        // mutually exclusive, exactly as the bot does it.
        $user->roles()->sync([$role->id]);

        return response()->json([
            'message' => $asOwner
                ? 'تم تفعيل وضع مالك الموقف.'
                : 'تم تفعيل وضع السائق.',
            'data'    => new RegisterResource($user->fresh('roles')),
        ]);
    }

    /**
     * A phone is usable when it carries a plausible number of digits. Mirrors
     * the bot's `isStoredPhoneUsable` so a value accepted in one surface is
     * never rejected by the other.
     */
    private function isPhoneUsable(?string $phone): bool
    {
        if (! is_string($phone) || $phone === '') {
            return false;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }
}
