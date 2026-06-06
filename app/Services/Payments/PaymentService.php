<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatusTypes;
use App\Models\Payment;
use App\Models\Reserve;
use App\Services\WhatsApp\WhatsAppNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly QiCardClient $qicard,
        private readonly WhatsAppNotifier $notifier,
    ) {}

    public function ensureForReserve(Reserve $reserve): Payment
    {
        return DB::transaction(function () use ($reserve) {
            $existing = Payment::where('reserve_id', $reserve->id)
                ->whereIn('status', [PaymentStatusTypes::CREATED->value, PaymentStatusTypes::SUCCESS->value])
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

        $client = $this->qicard;

        $response = $client->createPayment(
            requestId: $payment->request_id,
            amount: (int) ($payment->amount),
            finishPaymentUrl: config('services.qicard.public_url') . '/payments/return',
            notificationUrl: config('services.qicard.public_url') . '/api/payments/qicard/webhook',        );

        $payment->update([
            'payment_id' => $response['paymentId'],
            'form_url'   => $response['formUrl'],
            'qi_status'  => $response['status'],
        ]);

        return $response['formUrl'];
    }

    public function reconcile(Payment $payment): Payment
    {
        $client = $this->qicard;
        $response = $client->getPaymentStatus($payment->payment_id);

        $internal = match (true) {
            ($response['canceled'] ?? false) === true            => PaymentStatusTypes::CANCELED,
            ($response['status'] ?? null) === 'SUCCESS'          => PaymentStatusTypes::SUCCESS,
            in_array($response['status'] ?? null, ['FAILED', 'AUTHENTICATION_FAILED'], true) => PaymentStatusTypes::FAILED,
            default                                              => PaymentStatusTypes::CREATED,
        };

        $wasPaid = $payment->isPaid();

        if (!$wasPaid) {
            // only mutate if not already paid
            $update = [
                'status'    => $internal,
                'qi_status' => $response['status'] ?? null,
            ];
            if ($internal === PaymentStatusTypes::SUCCESS) {
                $update['paid_at'] = now();
            }
            $payment->update($update);
        }

        $payment = $payment->fresh();

        // Fire customer + space-owner notifications on the pending→paid edge.
        // Guarded by $wasPaid so a retried webhook for an already-paid row
        // doesn't double-notify.
        if (!$wasPaid && $internal === PaymentStatusTypes::SUCCESS) {
            $this->notifyPaymentSuccess($payment);
        }

        return $payment;
    }

    /**
     * Send a WhatsApp confirmation to the customer who paid and to the
     * space owner whose park received the payment.
     *
     * Best-effort: each notification is sent independently and any failure
     * is logged. We never throw — a notification glitch must not poison
     * the reconcile path (the row has already been marked paid).
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
            if ($customer && $customer->phone_number) {
                $msg = "✅ تم استلام دفعتك بنجاح.\n\n"
                     . "🅿️ الموقف: {$park->name}\n"
                     . "💰 المبلغ: {$amount}\n"
                     . "🔖 رقم العملية: {$ref}\n\n"
                     . "شكراً لاستخدامك Smart Parking.";
                $this->notifier->send($customer->phone_number, $msg);
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
            if ($owner && $owner->phone_number) {
                $customerName = $reserve->user?->name ?? 'عميل';
                $msg = "💳 تم استلام دفعة جديدة في موقفك.\n\n"
                     . "🅿️ الموقف: {$park->name}\n"
                     . "👤 العميل: {$customerName}\n"
                     . "💰 المبلغ: {$amount}\n"
                     . "🔖 رقم العملية: {$ref}";
                $this->notifier->send($owner->phone_number, $msg);
            }
        } catch (\Throwable $e) {
            Log::error('Payment success notify (owner) failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
