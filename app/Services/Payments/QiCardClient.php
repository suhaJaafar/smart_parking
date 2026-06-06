<?php
namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class QiCardClient{
    private readonly string $baseUrl;
    private readonly string $username;
    private readonly string $password;
    private readonly string $terminalId;
    private readonly string $currency;

    public function __construct()
    {
        // Add a constructor that pulls config into properties.
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

    public function createPayment(string $requestId, int $amount, string $finishPaymentUrl, string $notificationUrl): array
    {
            $response = $this->request()->post('/payment',[
                'requestId' => $requestId,
                'amount' => $amount,
                'currency' => $this->currency,
                'finishPaymentUrl' => $finishPaymentUrl,
                'notificationUrl' => $notificationUrl,
            ])->throw();

            return $response->json();
        }

    public function getPaymentStatus(string $providerPaymentId): array
    {
            $response = $this->request()->get("/payment/{$providerPaymentId}/status")
            ->throw();
            return $response->json();

    }

}
