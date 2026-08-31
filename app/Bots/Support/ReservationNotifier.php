<?php

namespace App\Bots\Support;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Dto\OutboundReply;
use App\Models\Park;
use App\Models\Reserve;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reservation lifecycle notifications shared by the bot and the Mini App.
 *
 * Extracted so a customer or owner receives the same wording whichever surface
 * triggered the event — a reservation made in the Mini App must look identical
 * to one made in chat.
 *
 * Every method is best-effort: the state change has already been committed by
 * the time we notify, so a transport failure is logged and swallowed rather
 * than surfaced to the caller.
 */
class ReservationNotifier
{
    public function __construct(
        private readonly BotNotifier $notifier,
    ) {}

    /**
     * Tell the garage owner a new reservation just landed.
     */
    public function notifyOwnerOfNewReservation(Reserve $reserve, Park $park): void
    {
        $this->safely('owner new-reservation', $reserve, function () use ($reserve, $park) {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $customer = $reserve->user;
            $name = $customer?->name ?: 'سائق';
            $phoneLine = $customer?->phone_number
                ? "📱 هاتف الزبون: *+{$customer->phone_number}*\n"
                : '';

            $expires = $reserve->expires_at
                ? $reserve->expires_at->setTimezone(config('app.timezone'))->format('h:i A')
                : '—';

            $kind = $reserve->is_pre_booking ? '💳 *حجز مسبق جديد*' : '🔔 *حجز جديد في موقفك*';

            $this->notifier->notify($owner, OutboundReply::text(
                "{$kind}\n\n"
                . "الموقف: *{$park->name}*\n"
                . "الزبون: *{$name}*\n"
                . "🔢 رمز الحجز: *{$reserve->booking_code}*\n"
                . $phoneLine
                . "صالح حتّى الساعة: {$expires}\n"
                . "الأماكن المتبقية: *{$park->free_spaces}*\n\n"
                . "_سيظهر الزبون في قائمة السيارات الواصلة عند وصوله._"
            ));
        });
    }

    /**
     * Tell the customer their stay is closed, and whether anything is owed.
     *
     * This is the one lifecycle edge that previously notified nobody — the car
     * left and the customer heard nothing.
     */
    public function notifyCustomerOfExit(Reserve $reserve, Park $park, bool $isPaid): void
    {
        $this->safely('customer exit', $reserve, function () use ($reserve, $park, $isPaid) {
            $customer = $reserve->user;
            if (!$customer) {
                return;
            }

            $time = now()->setTimezone(config('app.timezone'))->format('Y-m-d h:i A');

            // The Latin brand must end the line: a trailing "." after it is a
            // bidi-neutral character and jumps to the far left of an RTL line.
            $tail = $isPaid
                ? "✅ تم استلام المبلغ.\nشكراً لاستخدامك خدمة Smart Parking"
                : "💵 يُرجى تسديد المبلغ لصاحب الموقف عند الخروج إن لم تكن قد دفعت.";

            $this->notifier->notify($customer, OutboundReply::text(
                "🚗 *تم إخراج سيارتك*\n\n"
                . "📍 الموقف: *{$park->name}*\n"
                . "🕒 وقت الخروج: {$time}\n\n"
                . $tail
            ));
        });
    }

    /**
     * Tell the owner a car left their garage, with the freed-space count.
     */
    public function notifyOwnerOfExit(Reserve $reserve, Park $park): void
    {
        $this->safely('owner exit', $reserve, function () use ($reserve, $park) {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $name = $reserve->user?->name ?: 'زبون';
            $fresh = $park->fresh();

            $this->notifier->notify($owner, OutboundReply::text(
                "🚗 *غادرت سيارة موقفك*\n\n"
                . "الموقف: *{$park->name}*\n"
                . "الزبون: *{$name}*\n"
                . "الأماكن المتاحة الآن: *{$fresh?->free_spaces}*"
            ));
        });
    }

    /**
     * Tell the owner a customer chose to settle in cash, so they know to
     * collect it before releasing the car.
     */
    public function notifyOwnerOfCashChoice(Reserve $reserve, Park $park, string $amount): void
    {
        $this->safely('owner cash-choice', $reserve, function () use ($reserve, $park, $amount) {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $name = $reserve->user?->name ?: 'زبون';

            $this->notifier->notify($owner, OutboundReply::text(
                "💵 *الزبون اختار الدفع نقداً*\n\n"
                . "الموقف: *{$park->name}*\n"
                . "الزبون: *{$name}*\n"
                . "🔢 رمز الحجز: *{$reserve->booking_code}*\n"
                . "المبلغ: *{$amount}*\n\n"
                . "_يُرجى استلام المبلغ قبل إخراج السيارة._"
            ));
        });
    }

    /**
     * @param  callable():void  $send
     */
    private function safely(string $what, Reserve $reserve, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning("Reservation notification failed ({$what})", [
                'reserve_id' => $reserve->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
