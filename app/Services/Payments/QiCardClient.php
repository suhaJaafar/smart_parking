<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the QiCard 3DS REST API.
 *
 * Auth: HTTP Basic + a per-merchant Terminal-Id header. All config lives
 * in `services.qicard.*` and is sourced from QICARD_* env vars.
 */
class QiCardClient
{
    private readonly string $baseUrl;
    private readonly string $username;
    private readonly string $password;
    private readonly string $terminalId;
    private readonly string $currency;

    public function __construct()
    {
        $this->baseUrl    = config('services.qicard.base_url');
        $this->username   = config('services.qicard.username');
        $this->password   = config('services.qicard.password');
        $this->terminalId = config('services.qicard.terminal_id');
        $this->currency   = config('services.qicard.currency');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->username, $this->password)
            ->withHeaders([
                'X-Terminal-Id' => $this->terminalId,
                'Accept'        => 'application/json',
            ])
            ->timeout(20);
    }

    /**
     * Open a new payment session at Qi and return its `paymentId` + hosted `formUrl`.
     */
    public function createPayment(
        string $requestId,
        int $amount,
        string $finishPaymentUrl,
        string $notificationUrl,
    ): array {
        $response = $this->request()->post('/payment', [
            'requestId'        => $requestId,
            'amount'           => $amount,
            'currency'         => $this->currency,
            'finishPaymentUrl' => $finishPaymentUrl,
            'notificationUrl'  => $notificationUrl,
        ])->throw();

        return $response->json();
    }

    /**
     * Re-fetch authoritative status for a Qi paymentId. We never trust the
     * webhook body — we always confirm via this call before mutating state.
     */
    public function getPaymentStatus(string $providerPaymentId): array
    {
        $response = $this->request()
            ->get("/payment/{$providerPaymentId}/status")
            ->throw();

        return $response->json();
    }
}
