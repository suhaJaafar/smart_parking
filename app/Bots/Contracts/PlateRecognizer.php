<?php

namespace App\Bots\Contracts;

use App\Data\CarPlate;

/**
 * Extracts a vehicle plate from an image (ALPR/OCR).
 *
 * Implementations talk to an external recognition service. They MUST fail
 * soft: return null on any problem (no credentials, network error, image
 * unreadable, low confidence) so the caller can fall back to asking the
 * owner to type the plate manually instead of breaking the flow.
 */
interface PlateRecognizer
{
    /**
     * Recognise the plate in the image at $imageUrl.
     *
     * @param  string  $imageUrl  A directly downloadable image URL.
     * @return CarPlate|null       The detected plate, or null when it could
     *                             not be read with sufficient confidence.
     */
    public function recognize(string $imageUrl): ?CarPlate;
}
