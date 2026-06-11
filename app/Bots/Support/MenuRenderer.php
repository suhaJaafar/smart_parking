<?php

namespace App\Bots\Support;

use App\Enums\RoleTypes;
use App\Models\Reserve;
use App\Models\User;

/**
 * Builds the bot's role-aware main menu and its active-reservation banner.
 *
 * Channel-agnostic — flows for both WhatsApp and Telegram render through
 * the same Markdown-flavoured text. Each transport is responsible for any
 * channel-specific escaping.
 */
class MenuRenderer
{
    /**
     * Full menu, including a top banner if the user has an active reservation.
     * `$user` may be null (unknown phone/chat) — in that case we render a
     * generic welcome with no role-specific options.
     */
    public function for(?User $user): string
    {
        $isOwner    = false;
        $isCustomer = false;

        if ($user) {
            $roles      = $user->roles->pluck('role')->all();
            $isOwner    = in_array(RoleTypes::SPACE_OWNER, $roles, true);
            $isCustomer = in_array(RoleTypes::CUSTOMER, $roles, true)
                       || in_array(RoleTypes::USER, $roles, true);
        }

        $lines = [];

        if ($user) {
            $banner = $this->activeReservationBanner($user);
            if ($banner !== null) {
                $lines[] = $banner;
                $lines[] = '';
            }
        }

        $lines[] = "مرحباً بك في *ParkIQ* 🚗";
        $lines[] = "ماذا تريد أن تفعل؟ أرسل رقماً:";
        $lines[] = '';

        if ($isOwner) {
            $ownsAtLeastOnePark = $user && $user->ownedParks()->exists();

            $lines[] = " *اذا كنت مالك موقف:*";
            if ($ownsAtLeastOnePark) {
                $lines[] = "1️⃣  دخول سيارة للكراج";
                $lines[] = "2️⃣  خروج سيارة من الكراج";
                $lines[] = "3️⃣  إنشاء موقف إضافي";
                $lines[] = "_لعرض مواقفك أرسل: *موقفي*_";
            } else {
                $lines[] = "_لم تنشئ أي موقف بعد. ابدأ بـ:_";
                $lines[] = "3️⃣  إنشاء موقفك الأول";
            }
            $lines[] = '';
        }

        if ($isCustomer) {
            $lines[] = "*اذا كنت سائق:*";
            $lines[] = "4️⃣  أقرب موقف لي";
            $lines[] = '';
        }

        $lines[] = "ـــــــــــــــــــــــــــــــ";
        $lines[] = "💡 أوامر مفيدة في أي وقت:";
        $lines[] = "•  *مساعدة*  — كل الأوامر";
        $lines[] = "•  *الحالة*  — حسابي وحجزي";
        $lines[] = "•  *تسجيل*  — تغيير الدور";
        $lines[] = "•  *الغاء*   — إلغاء العملية الحالية";

        return implode("\n", $lines);
    }

    /**
     * One-line summary of the user's active reservation, or null if none.
     */
    public function activeReservationBanner(User $user): ?string
    {
        // "Open" reservations from the customer's POV are pending holds
        // (START) and accepted-but-not-yet-exited stays (ACTIVE). Both
        // should surface in the menu banner.
        $reserve = Reserve::where('user_id', $user->id)
            ->whereIn('status', [Reserve::STATUS_START, Reserve::STATUS_ACTIVE])
            ->with('park')
            ->latest('created_at')
            ->first();

        if (!$reserve || !$reserve->park) {
            return null;
        }

        $expires = $reserve->expires_at
            ? $reserve->expires_at->setTimezone(config('app.timezone'))->format('H:i')
            : '—';

        return "🅿️ *حجز فعّال:* {$reserve->park->name}\n"
             . "⏰ صالح حتى الساعة {$expires}\n"
             . "_لإلغاء الحجز أرسل: *الغاء حجزي*_";
    }
}
