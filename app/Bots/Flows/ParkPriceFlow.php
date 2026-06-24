<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\Prompt;
use App\Enums\RoleTypes;
use App\Models\Park;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lets a SPACE_OWNER (re)define the flat price charged once per reservation
 * at one of their parks.
 *
 * Steps:
 *   • a single park  → price → done.
 *   • multiple parks → pick → price → done.
 *
 * The price replaces the previous value outright; it is charged once when a
 * car is entered (no time-based accrual).
 */
class ParkPriceFlow
{
    public const FLOW = 'park_price';
    private const TTL_MINUTES = 10;

    public function handle(BotSession $session, string $message): OutboundReply
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        if (in_array(mb_strtolower(trim($message)), ['0', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'pick'  => $this->onPick($session, $message),
            'price' => $this->onPrice($session, $message),
            default => OutboundReply::empty(),
        };
    }

    private function start(BotSession $session): OutboundReply
    {
        $owner = $session->getUser();

        if (!$owner) {
            return OutboundReply::text("📱 حسابك غير مسجل في النظام.");
        }

        if (!$owner->roles()->where('role', RoleTypes::SPACE_OWNER->value)->exists()) {
            return OutboundReply::text("🚫 هذه العملية متاحة لمالكي المواقف فقط.");
        }

        /** @var \Illuminate\Support\Collection<int, Park> $parks */
        $parks = $owner->ownedParks()->orderBy('created_at')->get();

        if ($parks->isEmpty()) {
            return OutboundReply::text(
                "🚫 لا يوجد موقف مسجل باسمك.\nأرسل *القائمة* ثم اختر *3* لإنشاء موقفك الأول."
            );
        }

        if ($parks->count() === 1) {
            $park = $parks->first();
            $session->update([
                'flow'       => self::FLOW,
                'step'       => 'price',
                'data'       => ['park_id' => $park->id],
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            return OutboundReply::text($this->pricePrompt($park));
        }

        // Multiple parks: present a numbered list and remember the mapping.
        $map   = [];
        $lines = ["💰 *تحديد سعر الحجز*", "اختر الموقف بإرسال رقمه:", ''];
        foreach ($parks as $i => $park) {
            $index        = $i + 1;
            $map[$index]  = $park->id;
            $current      = number_format((float) $park->price, 0) . ' ' . config('services.qicard.currency');
            $lines[]      = "*{$index}.* {$park->name} — السعر الحالي: {$current}";
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'pick',
            'data'       => ['map' => $map],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(Prompt::ask(implode("\n", $lines)));
    }

    private function onPick(BotSession $session, string $message): OutboundReply
    {
        $map    = $session->getData()['map'] ?? [];
        $choice = trim(DigitNormalizer::toAscii($message));

        if (!ctype_digit($choice) || !isset($map[(int) $choice])) {
            return OutboundReply::text(Prompt::ask("⚠️ اختر رقماً صحيحاً من القائمة أعلاه."));
        }

        $park = Park::find($map[(int) $choice]);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ لم يعد هذا الموقف متاحاً.");
        }

        $session->update([
            'step'       => 'price',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text($this->pricePrompt($park));
    }

    private function onPrice(BotSession $session, string $message): OutboundReply
    {
        $msg = trim(DigitNormalizer::toAscii($message));
        if (!ctype_digit($msg) || (int) $msg < 1) {
            return OutboundReply::text(
                Prompt::ask("⚠️ أرسل سعراً صحيحاً موجباً بالدينار (مثال: 2000).")
            );
        }

        $parkId = $session->getData()['park_id'] ?? null;
        $park   = $parkId ? Park::find($parkId) : null;

        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ تعذّر العثور على الموقف. ابدأ من جديد.");
        }

        $price = (int) $msg;

        try {
            $park->update(['price' => $price]);
        } catch (Throwable $e) {
            Log::error('Bot park price update failed', [
                'park_id' => $park->id,
                'error'   => $e->getMessage(),
            ]);
            $session->reset();
            return OutboundReply::text("❌ تعذّر تحديث السعر. حاول لاحقاً.");
        }

        $session->reset();

        $formatted = number_format((float) $park->fresh()->price, 0)
                   . ' ' . config('services.qicard.currency');

        return OutboundReply::text(
            "✅ تم تحديث سعر الحجز في *{$park->name}*\n"
            . "💰 السعر الجديد: *{$formatted}* (يُخصم مرة واحدة عند دخول السيارة)."
        );
    }

    private function pricePrompt(Park $park): string
    {
        $current = number_format((float) $park->price, 0) . ' ' . config('services.qicard.currency');

        return Prompt::ask(
            "💰 *{$park->name}*\n"
            . "السعر الحالي: {$current}\n\n"
            . "أرسل السعر الجديد بالدينار (مثال: 2000). يُخصم مرة واحدة عند دخول السيارة."
        );
    }
}
