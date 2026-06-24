<?php

namespace App\Bots\Flows\Concerns;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\DigitNormalizer;
use App\Models\User;

/**
 * Collects the customer's phone number once, before a reservation can be
 * completed, so the space owner can reach them. The number is captured via
 * the channel's native "share contact" affordance (no manual typing) and is
 * required — the flow cannot proceed until it is shared.
 *
 * Reused by the customer reservation flows ({@see \App\Bots\Flows\NearbyParksFlow},
 * {@see \App\Bots\Flows\PreBookingFlow}). WhatsApp users already carry their
 * phone (their wa_id), so {@see self::needsPhone()} is false for them and the
 * gate is skipped entirely.
 */
trait CollectsPhoneNumber
{
    /** Session step used while collecting the phone number. */
    private const PHONE_STEP = 'ask_phone';

    /** Callback ids for the share-permission prompt. */
    private const PHONE_YES = 'phone:yes';
    private const PHONE_NO  = 'phone:no';

    /** Shortest plausible phone length (digits only) we will accept. */
    private const PHONE_MIN_DIGITS = 7;

    /**
     * Does this customer still need to share a phone number?
     */
    protected function needsPhone(?User $user): bool
    {
        return $user !== null && blank($user->phone_number);
    }

    /**
     * Enter the phone-collection step, stashing the parsed coordinates so the
     * park search can resume once the number arrives. Returns the yes/no
     * permission prompt.
     */
    protected function startPhoneGate(BotSession $session, float $lat, float $lng, int $ttlMinutes): OutboundReply
    {
        $data = $session->getData();
        $data['pending_coords'] = ['lat' => $lat, 'lng' => $lng];

        $session->update([
            'step'       => self::PHONE_STEP,
            'data'       => $data,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $this->phonePermissionPrompt();
    }

    /**
     * The two-option (yes / no) share-permission prompt.
     */
    protected function phonePermissionPrompt(): OutboundReply
    {
        return OutboundReply::buttons(
            body: "📱 لإتمام الحجز نحتاج رقم هاتفك ليتمكن صاحب الموقف من التواصل معك عند الحاجة.\n\n"
                . "هل تسمح بمشاركة رقمك؟",
            options: [
                ['id' => self::PHONE_YES, 'title' => '✅ نعم، مشاركة رقمي'],
                ['id' => self::PHONE_NO,  'title' => '❌ لا'],
            ],
        );
    }

    /**
     * Handle one inbound message while in the phone step.
     *
     * Returns either:
     *   - an {@see OutboundReply} → still collecting (re-prompt / request contact)
     *   - array{lat: float, lng: float} → number captured & stored; the caller
     *     should resume the park search with these coordinates.
     *
     * @return OutboundReply|array{lat: float, lng: float}
     */
    protected function handlePhoneStep(BotSession $session, string $message): OutboundReply|array
    {
        $raw = trim($message);

        // Customer agreed — surface the native "share contact" button.
        if ($raw === self::PHONE_YES) {
            return OutboundReply::requestContact(
                body: "اضغط الزر بالأسفل لمشاركة رقمك تلقائياً 👇",
                buttonLabel: '📱 مشاركة رقمي',
            );
        }

        // Customer declined — the number is required, so we re-ask.
        if ($raw === self::PHONE_NO) {
            return $this->phonePermissionPrompt()->withAppendedBody(
                "\n\n_رقم الهاتف مطلوب لإتمام الحجز._"
            );
        }

        // Otherwise: did a phone number arrive (shared contact or typed)?
        $digits = preg_replace('/\D/', '', DigitNormalizer::toAscii($raw));

        if (is_string($digits) && strlen($digits) >= self::PHONE_MIN_DIGITS) {
            $session->getUser()?->forceFill(['phone_number' => $digits])->save();

            $coords = $session->getData()['pending_coords'] ?? null;
            if (!is_array($coords) || !isset($coords['lat'], $coords['lng'])) {
                $session->reset();
                return OutboundReply::text("📍 شارك موقعك من جديد لإكمال الحجز.");
            }

            return ['lat' => (float) $coords['lat'], 'lng' => (float) $coords['lng']];
        }

        // Unrecognised input while waiting for the number — re-prompt.
        return $this->phonePermissionPrompt();
    }
}
