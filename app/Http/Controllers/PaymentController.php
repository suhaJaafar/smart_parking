<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        // If $payment->isPaid() → render payments.success view with the payment (don't redirect them back to Qi for a session that's already paid).
        if($payment->isPaid()) {
            return view('payments.success', ['payment' => $payment]);
        }
        // Otherwise → call $this->payments->provisionFormUrl($payment) to get the Qi formUrl, then return redirect()->away($url).
        // Wrap the provisionFormUrl call in try/catch. If Qi throws, log it and render payments.error with a "couldn't open payment page, try again later" message. Do not let the exception bubble to the user as a stack trace.
        try {
            $url = $this->payments->provisionFormUrl($payment);
            return redirect()->away($url);
        } catch (\Throwable $e) {
            Log::error('Error provisioning payment form URL: ' . $e->getMessage(), ['payment_id' => $payment->id]);
            return view('payments.error', ['message' => 'Could not open payment page, please try again later.']);
        }

    }

    public function return(Request $request)
    {
        // Qi redirects the customer back here after the payment attempt.
        // The query may or may not include `paymentId` depending on the
        // integration mode. If absent, render a generic page — the webhook
        // will still reconcile the row server-side.
        $paymentId = $request->query('paymentId');

        if (!$paymentId) {
            return view('payments.return_generic');
        }

        $payment = Payment::where('payment_id', $paymentId)->first();

        if (!$payment) {
            return view('payments.return_generic');
        }

        // Re-fetch authoritative status from Qi (don't trust the query string).
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

    public function webhook(Request $request)
    {
        // Qi POSTs the payment object here. We never trust the body — we
        // look up the row by paymentId (or requestId as fallback) and ask
        // the service to re-fetch authoritative status from Qi.
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

        // Always 200 — we acknowledge receipt regardless. Qi doesn't read
        // our response body.
        return response()->json(['ok' => true]);
    }
}
