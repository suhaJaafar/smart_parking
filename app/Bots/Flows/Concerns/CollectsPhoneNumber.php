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
     * Enter the phone-collection step, stashing an arbitrary resume payload
     * so the caller can continue exactly where it left off once the number
     * arrives. An optional $notice line is appended to the prompt (e.g. a
     * warning that the reservation cannot proceed without a number).
     *
     * @param array<string, mixed> $resume Opaque payload handed back to the
     *        caller once the number is captured.
     */
    protected function startPhoneGate(BotSession $session, array $resume, int $ttlMinutes, ?string $notice = null): OutboundReply
    {
        $data = $session->getData();
        $data['phone_resume'] = $resume;

        $session->update([
            'step'       => self::PHONE_STEP,
            'data'       => $data,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $this->phonePermissionPrompt($notice);
    }

    /**
     * The two-option (yes / no) share-permission prompt. An optional $notice
     * line is appended below the question.
     */
    protected function phonePermissionPrompt(?string $notice = null): OutboundReply
    {
        $body = "📱 لإتمام الحجز نحتاج رقم هاتفك ليتمكن صاحب الموقف من التواصل معك عند الحاجة.\n\n"
              . "هل تسمح بمشاركة رقمك؟";

        if ($notice !== null && $notice !== '') {
            $body .= "\n\n" . $notice;
        }

        return OutboundReply::buttons(
            body: $body,
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
     *   - array<string, mixed> → number captured & stored; the value is the
     *     resume payload the caller passed to {@see self::startPhoneGate()},
     *     so it can continue where it left off.
     *
     * @return OutboundReply|array<string, mixed>
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

            $resume = $session->getData()['phone_resume'] ?? null;
            if (!is_array($resume)) {
                $session->reset();
                return OutboundReply::text("⚠️ انتهت الجلسة. ابدأ العملية من جديد.");
            }

            return $resume;
        }

        // Unrecognised input while waiting for the number — re-prompt.
        return $this->phonePermissionPrompt();
    }
}
