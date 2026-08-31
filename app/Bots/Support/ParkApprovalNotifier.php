<?php

namespace App\Bots\Support;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Dto\OutboundReply;
use App\Models\Park;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Garage review notifications, shared by the bot, the Mini App and the
 * dashboard.
 *
 * Registering a garage is the one flow where the user submits something and
 * then hears nothing for hours, so these messages carry the whole experience:
 * what was received, what happens next, and when.
 *
 * Best-effort like {@see ReservationNotifier} — the decision is already
 * committed by the time we notify, so a transport failure is logged rather
 * than surfaced to the admin who clicked approve.
 */
class ParkApprovalNotifier
{
    public function __construct(
        private readonly BotNotifier $notifier,
    ) {}

    /**
     * Acknowledge a submission, and set the expectation of a wait.
     */
    public function notifyOwnerOfSubmission(Park $park): void
    {
        $this->safely('submission', $park, function () use ($park) {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $this->notifier->notify($owner, OutboundReply::text(
                "📝 *تم استلام طلب تسجيل موقفك*\n\n"
                . "الموقف: *{$park->name}*\n"
                . "عدد الأماكن: *{$park->capacity}*\n\n"
                . "طلبك الآن قيد المراجعة من قبل الإدارة، وسيتم الرد خلال 24 ساعة.\n"
                . "_سنرسل لك إشعاراً فور الموافقة._"
            ));
        });
    }

    /**
     * Tell the owner their garage is live, and what they can do now.
     */
    public function notifyOwnerOfApproval(Park $park): void
    {
        $this->safely('approved', $park, function () use ($park) {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $this->notifier->notify($owner, OutboundReply::text(
                "✅ *تمت الموافقة على موقفك*\n\n"
                . "الموقف: *{$park->name}*\n"
                . "عدد الأماكن: *{$park->capacity}*\n\n"
                . "أصبح موقفك ظاهراً للسائقين الآن، ويمكنك فتحه ومتابعة تفاصيله وحجوزاته من التطبيق.\n"
                . "_افتح التطبيق واختر «مواقفي»._"
            ));
        });
    }

    /**
     * Refuse clearly. A rejection with no reason just generates a support
     * message, so the admin's note is passed straight through when given.
     */
    public function notifyOwnerOfRejection(Park $park, ?string $reason = null): void
    {
        $this->safely('rejected', $park, function () use ($park, $reason) {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $note = $reason
                ? "السبب: _{$reason}_\n\n"
                : '';

            $this->notifier->notify($owner, OutboundReply::text(
                "❌ *لم تتم الموافقة على موقفك*\n\n"
                . "الموقف: *{$park->name}*\n\n"
                . $note
                . "يمكنك تعديل البيانات وإعادة التقديم، أو التواصل مع الإدارة للمزيد من التفاصيل."
            ));
        });
    }

    /**
     * @param  callable():void  $send
     */
    private function safely(string $what, Park $park, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning("Park approval notification failed ({$what})", [
                'park_id' => $park->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
