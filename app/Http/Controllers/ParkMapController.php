<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Written BY AI
 * Renders the nearby-parks map for a customer who tapped the link sent on WhatsApp.
 *
 * The bot encodes the result list into a single short token in the URL so this
 * endpoint stays stateless: no DB lookup, no auth, nothing to expire on the
 * server side. If the link is shared, anyone can view the parks but cannot
 * reserve anything (reservations only happen via the bot).
 */
class ParkMapController extends Controller
{
    public function show(Request $request): View
    {
        $parks       = $this->decode((string) $request->query('p', ''));
        $userLat     = $request->query('lat');
        $userLng     = $request->query('lng');

        $userLocation = (is_numeric($userLat) && is_numeric($userLng))
            ? ['lat' => (float) $userLat, 'lng' => (float) $userLng]
            : null;

        return view('parks.map', [
            'parks'        => $parks,
            'userLocation' => $userLocation,
        ]);
    }

    /**
     * Decode "lat,lng,free,name|lat,lng,free,name|..." with the whole string
     * urlsafe-base64'd. The bot encodes; we decode here.
     *
     * @return array<int, array{lat: float, lng: float, free_spaces: int, name: string}>
     */
    private function decode(string $token): array
    {
        if ($token === '') {
            return [];
        }

        $padded  = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $raw     = base64_decode($padded, true);
        if ($raw === false) {
            return [];
        }

        $parks = [];
        foreach (explode('|', $raw) as $line) {
            $parts = explode(',', $line, 4);
            if (count($parts) !== 4) {
                continue;
            }

            [$lat, $lng, $free, $name] = $parts;
            if (!is_numeric($lat) || !is_numeric($lng) || !is_numeric($free)) {
                continue;
            }

            $parks[] = [
                'lat'         => (float) $lat,
                'lng'         => (float) $lng,
                'free_spaces' => (int)   $free,
                // 60-char cap to keep URLs short and prevent any pathological input.
                'name'        => mb_substr($name, 0, 60),
            ];
        }

        return $parks;
    }
}
