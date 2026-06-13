<?php

namespace App\Services\Payments;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Dto\OutboundReply;
use App\Enums\PaymentStatusTypes;
use App\Models\Payment;
use App\Models\Reserve;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lifecycle owner for {@see Payment} rows.
 *
 *  • ensureForReserve()    — idempotently provisions a CREATED row for a
 *                            reservation right after it goes ACTIVE.
 *  • provisionFormUrl()    — lazily exchanges the row's `request_id` for a
 *                            Qi `formUrl` on first hit to /pay/{token}.
 *  • reconcile()           — re-fetches authoritative status from Qi and
 *                            transitions the row; fires customer + owner
 *                            confirmation notifications on the
 *                            pending → SUCCESS edge exactly once.
 *
 * Channel-agnostic: success notifications are delivered through
 * {@see BotNotifier}, which fans out to every channel the recipient is
 * enrolled in (WhatsApp, Telegram, …).
 */
class PaymentService
{
    public function __construct(
        private readonly QiCardClient $qicard,
        private readonly BotNotifier $notifier,
    ) {}

    public function ensureForReserve(Reserve $reserve): Payment
    {
        return DB::transaction(function () use ($reserve) {
            $existing = Payment::where('reserve_id', $reserve->id)
                ->whereIn('status', [
                    PaymentStatusTypes::CREATED->value,
                    PaymentStatusTypes::SUCCESS->value,
                ])
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if ($existing) {
                return $existing;
            }

            return Payment::create([
                'reserve_id' => $reserve->id,
                'user_id'    => $reserve->user_id,
                'amount'     => 1000,
                'currency'   => config('services.qicard.currency'),
                'status'     => PaymentStatusTypes::CREATED->value,
                'request_id' => 'sp_' . Str::random(24),
                'token'      => Str::random(40),
            ]);
        });
    }

    public function provisionFormUrl(Payment $payment): string
    {
        if ($payment->form_url && $payment->isPending()) {
            return $payment->form_url;
        }

        $response = $this->qicard->createPayment(
            requestId:        $payment->request_id,
            amount:           (int) $payment->amount,
            finishPaymentUrl: config('services.qicard.public_url') . '/payments/return',
            notificationUrl:  config('services.qicard.public_url') . '/api/payments/qicard/webhook',
        );

        return DB::transaction(function () use ($payment, $response) {
            $fresh = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($fresh->form_url && $fresh->isPending()) {
                return $fresh->form_url;
            }

            $fresh->update([
                'payment_id' => $response['paymentId'],
                'form_url'   => $response['formUrl'],
                'qi_status'  => $response['status'],
            ]);

            return $response['formUrl'];
        });
    }

    public function reconcile(Payment $payment): Payment
    {
        $response = $this->qicard->getPaymentStatus($payment->payment_id);

        $internal = match (true) {
            ($response['canceled'] ?? false) === true             => PaymentStatusTypes::CANCELED,
            ($response['status']   ?? null) === 'SUCCESS'         => PaymentStatusTypes::SUCCESS,
            in_array($response['status'] ?? null, ['FAILED', 'AUTHENTICATION_FAILED'], true)
                                                                   => PaymentStatusTypes::FAILED,
            default                                                => PaymentStatusTypes::CREATED,
        };

        [$payment, $justPaid] = DB::transaction(function () use ($payment, $internal, $response) {
            $fresh   = Payment::whereKey($payment->id)->lockForUpdate()->first();
            $wasPaid = $fresh->isPaid();

            if (!$wasPaid) {
                $update = [
                    'status'    => $internal,
                    'qi_status' => $response['status'] ?? null,
                ];
                if ($internal === PaymentStatusTypes::SUCCESS) {
                    $update['paid_at'] = now();
                }
                $fresh->update($update);
            }

            return [$fresh->fresh(), !$wasPaid && $internal === PaymentStatusTypes::SUCCESS];
        });

        if ($justPaid) {
            $this->notifyPaymentSuccess($payment);
        }

        return $payment;
    }

    /**
     * Notify both the paying customer and the park owner about a successful
     * payment. Each notification is routed through {@see BotNotifier} so it
     * reaches them on every channel they're enrolled in.
     *
     * Best-effort: failures are logged but never re-thrown — the payment
     * row has already been marked paid by this point, and a notification
     * glitch must not poison the reconcile path (or trigger a webhook
     * retry storm from Qi).
     */
    private function notifyPaymentSuccess(Payment $payment): void
    {
        $reserve = $payment->reserve()->with(['user', 'park.owner'])->first();
        if (!$reserve) {
            return;
        }

        $amount = number_format((float) $payment->amount, 0) . ' ' . $payment->currency;
        $ref    = $payment->payment_id ?? $payment->request_id;
        $park   = $reserve->park;

        // Customer
        try {
            $customer = $reserve->user;
            if ($customer && $park) {
                $msg = "✅ تم استلام دفعتك بنجاح.\n\n"
                     . "🅿️ الموقف: {$park->name}\n"
                     . "💰 المبلغ: {$amount}\n"
                     . "🔖 رقم العملية: {$ref}\n\n"
                     . "شكراً لاستخدامك Smart Parking.";
                $this->notifier->notify($customer, OutboundReply::text($msg));
            }
        } catch (\Throwable $e) {
            Log::error('Payment success notify (customer) failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Space owner
        try {
            $owner = $park?->owner;
            if ($owner) {
                $customerName = $reserve->user?->name ?? 'عميل';
                $msg = "💳 تم استلام دفعة جديدة في موقفك.\n\n"
                     . "🅿️ الموقف: {$park->name}\n"
                     . "👤 العميل: {$customerName}\n"
                     . "💰 المبلغ: {$amount}\n"
                     . "🔖 رقم العملية: {$ref}";
                $this->notifier->notify($owner, OutboundReply::text($msg));
            }
        } catch (\Throwable $e) {
            Log::error('Payment success notify (owner) failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
