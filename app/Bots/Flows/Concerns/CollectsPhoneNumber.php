<?php

namespace App\Bots\Flows\Concerns;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Engine\ConversationEngine;
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

    /** Longest plausible phone length (E.164 caps national numbers at 15). */
    private const PHONE_MAX_DIGITS = 15;

    /**
     * Does this customer still need to share a phone number?
     *
     * True when there is no number yet, or when the stored value is not a
     * usable phone (e.g. a malformed record left by an older bug). This makes
     * the gate self-healing: a bad stored number is re-collected instead of
     * being silently reused, so the owner never sees garbage again.
     */
    protected function needsPhone(?User $user): bool
    {
        return $user !== null && !$this->isStoredPhoneUsable($user->phone_number);
    }

    /**
     * Whether a value already on the user record is a plausible phone.
     *
     * Lenient about formatting (a leading "+", spaces or dashes are ignored)
     * so legitimately stored international numbers still pass, but rejects
     * blanks and absurdly long values.
     */
    private function isStoredPhoneUsable(?string $phone): bool
    {
        if (blank($phone)) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) >= self::PHONE_MIN_DIGITS
            && strlen($digits) <= self::PHONE_MAX_DIGITS;
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
     * The phone number is *mandatory* and can only be supplied by the
     * customer sharing their OWN contact through the native share-contact
     * button (the channel parser tags such a payload with
     * {@see ConversationEngine::CONTACT_PAYLOAD_PREFIX}). Typed digits, a
     * friend's contact, an explicit "لا", or any unrelated message all
     * cancel the reservation — no reservation is ever made without a real,
     * self-shared number. No hold has been placed at this stage, so
     * cancelling simply resets the session.
     *
     * Returns either:
     *   - an {@see OutboundReply} → still collecting (share prompt) OR the
     *     reservation was cancelled (session already reset)
     *   - array<string, mixed> → number captured & stored; the value is the
     *     resume payload the caller passed to {@see self::startPhoneGate()}.
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

        // A genuine self-shared contact is the ONLY accepted input. Validate
        // its length so a malformed value can never be stored as a phone.
        if (str_starts_with($raw, ConversationEngine::CONTACT_PAYLOAD_PREFIX)) {
            $digits = substr($raw, strlen(ConversationEngine::CONTACT_PAYLOAD_PREFIX));

            if ($this->isPlausiblePhone($digits)) {
                $session->getUser()?->forceFill(['phone_number' => $digits])->save();

                $resume = $session->getData()['phone_resume'] ?? null;
                if (!is_array($resume)) {
                    $session->reset();
                    return OutboundReply::text("⚠️ انتهت الجلسة. ابدأ العملية من جديد.");
                }

                return $resume;
            }
        }

        // Anything else — tapping "لا", typing a number, sharing someone
        // else's contact, or ignoring the buttons — cancels the reservation.
        // A phone number is required and must be self-shared via the button.
        $session->reset();
        return OutboundReply::text(
            "❌ تم إلغاء الحجز.\n"
            . "لإتمام الحجز يجب الضغط على *نعم* ثم مشاركة رقمك عبر الزر."
        );
    }

    /**
     * A defensive sanity check on a digits-only phone: long enough to be a
     * real number, but not absurdly long (guards against malformed values).
     */
    private function isPlausiblePhone(string $digits): bool
    {
        return ctype_digit($digits)
            && strlen($digits) >= self::PHONE_MIN_DIGITS
            && strlen($digits) <= self::PHONE_MAX_DIGITS;
    }
}
