<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Three public endpoints:
 *
 *  • GET  /pay/{token}              — entry point we hand to the customer
 *                                     in their post-entry bot message.
 *                                     Lazily provisions a Qi formUrl on
 *                                     first hit and redirects there.
 *  • GET  /payments/return          — Qi sends the customer back here
 *                                     after the hosted form. We reconcile
 *                                     and render success/failed views.
 *  • POST /api/payments/qicard/webhook — server-to-server notification
 *                                     from Qi. We re-fetch status (never
 *                                     trust the body) and ACK with 200.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function redirect(string $token)
    {
        $payment = Payment::where('token', $token)->first();
        if (!$payment) {
            return view('payments.error', ['message' => 'Payment not found.']);
        }

        // Already paid → show the success page instead of opening a new
        // Qi session for a settled invoice.
        if ($payment->isPaid()) {
            return view('payments.success', ['payment' => $payment]);
        }

        try {
            $url = $this->payments->provisionFormUrl($payment);
            return redirect()->away($url);
        } catch (\Throwable $e) {
            Log::error('Error provisioning payment form URL: ' . $e->getMessage(), [
                'payment_id' => $payment->id,
            ]);
            return view('payments.error', [
                'message' => 'Could not open payment page, please try again later.',
            ]);
        }
    }

    public function return(Request $request)
    {
        // Qi redirects the customer back here. The query may or may not
        // include `paymentId` depending on integration mode. If absent,
        // render a generic page — the webhook will still reconcile.
        $paymentId = $request->query('paymentId');

        if (!$paymentId) {
            return view('payments.return_generic');
        }

        $payment = Payment::where('payment_id', $paymentId)->first();

        if (!$payment) {
            return view('payments.return_generic');
        }

        try {
            $payment = $this->payments->reconcile($payment);
        } catch (\Throwable $e) {
            Log::error('Reconcile on return failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
            // Fall through and show whatever state we have locally.
        }

        return $payment->isPaid()
            ? view('payments.success', ['payment' => $payment])
            : view('payments.failed',  ['payment' => $payment]);
    }

    /**
     * Printable receipt for a settled payment. The customer reaches this
     * from the "تحميل وصل الدفع" button in their success notification. The
     * unguessable `token` is the access key — no auth required, mirroring
     * the existing `/pay/{token}` flow. A receipt only exists for a payment
     * that actually went through, so unpaid rows get a friendly notice.
     */
    public function receipt(string $token)
    {
        $payment = Payment::where('token', $token)
            ->with(['reserve.park', 'reserve.user'])
            ->first();

        if (!$payment) {
            return view('payments.error', ['message' => 'وصل الدفع غير موجود.']);
        }

        if (!$payment->isPaid()) {
            return view('payments.error', [
                'message' => 'لا يتوفر وصل دفع لهذه العملية بعد. يصبح الوصل متاحاً بعد إتمام الدفع.',
            ]);
        }

        return view('payments.receipt', ['payment' => $payment]);
    }

    public function webhook(Request $request)
    {
        // Qi POSTs the payment object here. We never trust the body — we
        // look up the row by paymentId (or requestId as fallback) and
        // ask the service to re-fetch authoritative status from Qi.
        $body = $request->all();
        Log::info('QiCard webhook received', ['body' => $body]);

        $paymentId = $body['paymentId'] ?? null;
        $requestId = $body['requestId'] ?? null;

        $payment = null;
        if ($paymentId) {
            $payment = Payment::where('payment_id', $paymentId)->first();
        }
        if (!$payment && $requestId) {
            $payment = Payment::where('request_id', $requestId)->first();
        }

        if ($payment) {
            $this->payments->reconcile($payment);
        } else {
            Log::warning('QiCard webhook: payment not found', [
                'paymentId' => $paymentId,
                'requestId' => $requestId,
            ]);
        }

        // Always 200 — Qi doesn't read our response body.
        return response()->json(['ok' => true]);
    }
}
