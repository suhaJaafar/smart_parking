<?php

namespace App\Bots\Support;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Dto\OutboundReply;
use App\Enums\PaymentStatusTypes;
use App\Models\Car;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;

/**
 * Tells a customer their car is now inside the park, and how to pay.
 *
 * Extracted so the Telegram car-entry flow and the Mini App owner dashboard
 * send byte-identical messages — a customer must not be able to tell which
 * surface the owner used to admit them.
 */
class CarEntryNotifier
{
    public function __construct(
        private readonly BotNotifier $notifier,
    ) {}

    /**
     * @param  bool  $fulfilledReservation  True when the entry closed a hold
     *                                      the customer had made in advance.
     */
    public function notifyEntered(
        User $customer,
        Park $park,
        Car $car,
        bool $fulfilledReservation = true,
        ?Reserve $reserve = null,
    ): void {
        $headline = $fulfilledReservation
            ? "✅ تم تأكيد حجزك! دخلت سيارتك إلى الموقف."
            : "✅ تم تسجيل دخول سيارتك إلى الموقف.";

        $body = $headline . "\n\n"
              . "📍 الموقف: {$park->name}\n"
              . "🚗 اللوحة: {$car->plate_prefix}-{$car->car_number}\n"
              . "🕒 وقت الدخول: " . now()->setTimezone(config('app.timezone'))->format('Y-m-d h:i A');

        $payLink = $reserve ? $this->payLinkFor($reserve) : null;
        if ($payLink !== null) {
            $body .= "\n\n💳 لإتمام عملية الدفع إلكترونياً: [اضغط هنا]({$payLink})"
                   . "\n\nأو يمكنك الدفع نقداً عند الخروج.";
        }

        $this->notifier->notify($customer, OutboundReply::text($body));
    }

    /**
     * Pay link for the reservation's outstanding payment, or null when there
     * is nothing left to settle.
     */
    private function payLinkFor(Reserve $reserve): ?string
    {
        $payment = $reserve->payments()
            ->whereIn('status', [
                PaymentStatusTypes::CREATED->value,
                PaymentStatusTypes::SUCCESS->value,
            ])
            ->latest()
            ->first();

        if (!$payment || $payment->isPaid()) {
            return null;
        }

        return route('payments.redirect', $payment->token);
    }
}
