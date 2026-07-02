<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\DigitNormalizer;
use App\Models\CoOwnerRequest;
use App\Models\Park;
use App\Models\TelegramAccount;
use Illuminate\Support\Str;

/**
 * "تسجيل دخول لكراج آخر" — a person asks to co-manage an existing garage.
 *
 * Reached from the onboarding menu (option 3). The requester introduces
 * themselves and picks the target garage; we store a pending
 * {@see CoOwnerRequest} that the garage's owner approves from the dashboard.
 *
 * Steps:
 *   ask_phone → share phone (native contact button, or typed digits)
 *   ask_name  → their display name (so the owner recognises them)
 *   ask_park  → type part of the garage name → pick the exact one from a list
 *
 * No `User` row is created here — approval later links this Telegram chat to
 * the owner's account, so the requester never needs their own identity.
 */
class CoOwnerRequestFlow
{
    public const FLOW = 'coowner_request';

    private const TTL_MINUTES = 10;

    /** Callback prefix for a tapped garage row in the search results. */
    private const PARK_PREFIX = 'coowner_park:';

    /** Shortest plausible phone length (digits only) we will accept. */
    private const PHONE_MIN_DIGITS = 7;

    /** Shortest garage-name query we will search on. */
    private const SEARCH_MIN_CHARS = 2;

    /** Max garage rows shown per search. */
    private const SEARCH_LIMIT = 8;

    public function handle(BotSession $session, string $message): OutboundReply
    {
        if ($session->isExpired()) {
            $session->reset();
            return OutboundReply::text(
                "⏳ انتهت مهلة الطلب. أرسل *ابدأ* للمحاولة من جديد."
            );
        }

        if (in_array(mb_strtolower(trim($message)), ['0', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        return match ($session->getStep()) {
            'idle'     => $this->begin($session),
            'ask_phone' => $this->handlePhone($session, $message),
            'ask_name'  => $this->handleName($session, $message),
            'ask_park'  => $this->handlePark($session, $message),
            default     => OutboundReply::empty(),
        };
    }

    /**
     * Enter the flow. Called from onboarding (option 3) and on a bare start.
     */
    public function begin(BotSession $session): OutboundReply
    {
        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'ask_phone',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $this->phonePrompt();
    }

    private function phonePrompt(): OutboundReply
    {
        return OutboundReply::requestContact(
            body: "🔑 *تسجيل دخول لكراج آخر*\n\n"
                . "لتقديم طلبك إلى صاحب الكراج، شارك رقم هاتفك أولاً.\n"
                . "اضغط الزر بالأسفل لمشاركة رقمك تلقائياً 👇",
            buttonLabel: '📱 مشاركة رقمي',
        );
    }

    /**
     * Capture the shared/typed phone, then ask for the name.
     */
    private function handlePhone(BotSession $session, string $message): OutboundReply
    {
        $digits = preg_replace('/\D/', '', DigitNormalizer::toAscii(trim($message)));

        if (!is_string($digits) || strlen($digits) < self::PHONE_MIN_DIGITS) {
            return $this->phonePrompt()->withAppendedBody(
                "\n\n_لم نتلقَّ رقماً صحيحاً. استخدم الزر بالأعلى._"
            );
        }

        $data = $session->getData();
        $data['phone'] = $digits;

        $session->update([
            'step'       => 'ask_name',
            'data'       => $data,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            "📝 ما اسمك الكامل؟\nسيظهر لصاحب الكراج ليتعرّف عليك عند مراجعة طلبك."
        );
    }

    /**
     * Capture the name, then move on to picking the garage.
     */
    private function handleName(BotSession $session, string $message): OutboundReply
    {
        $name = $this->sanitizeName($message);

        if ($name === null) {
            return OutboundReply::text(
                "⚠️ اسم غير صالح. أرسل اسماً بين 1 و 100 حرف."
            );
        }

        $data = $session->getData();
        $data['name'] = $name;

        $session->update([
            'step'       => 'ask_park',
            'data'       => $data,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $this->searchPrompt();
    }

    private function searchPrompt(): OutboundReply
    {
        return OutboundReply::text(
            "🏢 اكتب اسم الكراج الذي تريد الانضمام إليه للبحث عنه."
        );
    }

    /**
     * Either a tapped garage row (finalise the request) or a search query.
     */
    private function handlePark(BotSession $session, string $message): OutboundReply
    {
        $raw = trim($message);

        // Tapped a result → submit the request for that garage.
        if (str_starts_with($raw, self::PARK_PREFIX)) {
            return $this->submit($session, Str::after($raw, self::PARK_PREFIX));
        }

        if (mb_strlen($raw) < self::SEARCH_MIN_CHARS) {
            return $this->searchPrompt()->withAppendedBody(
                "\n\n_اكتب حرفين على الأقل._"
            );
        }

        $parks = Park::query()
            ->where('name', 'ilike', '%' . $raw . '%')
            ->with(['owner:id,name', 'location:id,city'])
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        if ($parks->isEmpty()) {
            return OutboundReply::text(
                "🔍 لا يوجد كراج بهذا الاسم.\nحاول كتابة الاسم بشكل مختلف."
            );
        }

        $options = $parks->map(fn (Park $park) => [
            'id'          => self::PARK_PREFIX . $park->id,
            'title'       => "📍 {$park->name}",
            'description' => trim(
                ($park->location?->city ? $park->location->city . ' • ' : '')
                . 'المالك: ' . ($park->owner?->name ?? '—')
            ),
        ])->all();

        return OutboundReply::buttons(
            body:       "اختر الكراج الصحيح من القائمة:",
            options:    $options,
            listButton: 'عرض الكراجات',
        );
    }

    /**
     * Persist the pending request for the chosen garage.
     */
    private function submit(BotSession $session, string $parkId): OutboundReply
    {
        if (!Str::isUuid($parkId)) {
            return OutboundReply::text("❌ اختيار غير صالح. اكتب اسم الكراج للبحث من جديد.");
        }

        $park = Park::with('owner')->find($parkId);
        if (!$park) {
            return OutboundReply::text("❌ لم يعد هذا الكراج متاحاً. اكتب اسم كراج آخر.");
        }

        $chatId = $session->getRecipient();
        $data   = $session->getData();

        // Already operating this owner's account — nothing to request.
        $alreadyLinked = TelegramAccount::where('chat_id', $chatId)
            ->where('user_id', $park->user_id)
            ->exists();

        if ($alreadyLinked) {
            $session->reset();
            return OutboundReply::text(
                "✅ أنت مرتبط بالفعل بحساب هذا الكراج.\nأرسل *ابدأ* للمتابعة."
            );
        }

        // Don't stack duplicate pending requests for the same garage.
        $pendingExists = CoOwnerRequest::where('telegram_chat_id', $chatId)
            ->where('park_id', $park->id)
            ->where('status', CoOwnerRequest::STATUS_PENDING)
            ->exists();

        if ($pendingExists) {
            $session->reset();
            return OutboundReply::text(
                "⏳ لديك طلب قيد المراجعة بالفعل لكراج *{$park->name}*.\n"
                . "سنعلمك فور الموافقة عليه."
            );
        }

        CoOwnerRequest::create([
            'park_id'          => $park->id,
            'owner_id'         => $park->user_id,
            'requester_name'   => (string) ($data['name'] ?? 'مستخدم'),
            'requester_phone'  => (string) ($data['phone'] ?? ''),
            'telegram_chat_id' => $chatId,
            'channel'          => $session->getChannel(),
            'status'           => CoOwnerRequest::STATUS_PENDING,
        ]);

        $session->reset();

        return OutboundReply::text(
            "✅ تم استلام طلبك!\n"
            . "🏢 الكراج: *{$park->name}*\n\n"
            . "⏳ يرجى الانتظار، طلبك قيد المراجعة من قبل صاحب الكراج.\n"
            . "سنرسل لك إشعاراً فور الموافقة عليه."
        );
    }

    /**
     * Normalise a free-form name: strip control chars, collapse whitespace,
     * bound the length. Returns null when nothing usable remains.
     */
    private function sanitizeName(string $name): ?string
    {
        $clean = preg_replace('/\p{C}+/u', '', $name);
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $clean));

        return $clean !== '' ? mb_substr($clean, 0, 100) : null;
    }
}
