<?php

namespace App\Bots\Support;

use App\Bots\Contracts\PlateRecognizer;
use App\Data\CarPlate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * {@see PlateRecognizer} backed by the Plate Recognizer Snapshot Cloud API
 * (https://platerecognizer.com). The image is downloaded from the channel's
 * file URL and forwarded to the recognition endpoint as a multipart upload.
 *
 * Configuration lives under `config/services.php` → `platerecognizer`:
 *   - token     : API token from the Plate Recognizer dashboard.
 *   - endpoint  : plate-reader endpoint (defaults to the cloud API).
 *   - regions   : optional region hint (e.g. "iq") to bias recognition.
 *   - min_score : reject reads below this confidence (0..1).
 *
 * Every failure path returns null so the calling flow can gracefully ask the
 * owner to type the plate by hand. When no token is configured the service
 * short-circuits to null, leaving manual entry as the only path.
 */
class PlateRecognizerClient implements PlateRecognizer
{
    public function recognize(string $imageUrl): ?CarPlate
    {
        $token = config('services.platerecognizer.token');

        if (!is_string($token) || $token === '' || trim($imageUrl) === '') {
            return null;
        }

        try {
            $image = Http::timeout(20)->get($imageUrl);

            if ($image->failed() || $image->body() === '') {
                Log::warning('PlateRecognizer: image download failed', [
                    'status' => $image->status(),
                ]);
                return null;
            }

            $endpoint = (string) config(
                'services.platerecognizer.endpoint',
                'https://api.platerecognizer.com/v1/plate-reader/',
            );
            $regions = config('services.platerecognizer.regions');

            $request = Http::withToken($token, 'Token')
                ->timeout(25)
                ->attach('upload', $image->body(), 'plate.jpg');

            if (is_string($regions) && $regions !== '') {
                $request = $request->attach('regions', $regions);
            }

            $response = $request->post($endpoint);

            if ($response->failed()) {
                Log::warning('PlateRecognizer: API error', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 300),
                ]);
                return null;
            }

            return $this->parse($response->json());
        } catch (Throwable $e) {
            Log::warning('PlateRecognizer: exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Pick the highest-confidence result and turn it into a {@see CarPlate},
     * rejecting reads below the configured minimum score.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function parse(?array $payload): ?CarPlate
    {
        $results = $payload['results'] ?? null;
        if (!is_array($results) || $results === []) {
            return null;
        }

        usort(
            $results,
            static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0),
        );

        $best  = $results[0];
        $plate = is_string($best['plate'] ?? null) ? $best['plate'] : '';
        $score = (float) ($best['score'] ?? 0);

        $minScore = (float) config('services.platerecognizer.min_score', 0.5);

        if ($plate === '' || $score < $minScore) {
            return null;
        }

        return CarPlate::fromString($plate);
    }
}
