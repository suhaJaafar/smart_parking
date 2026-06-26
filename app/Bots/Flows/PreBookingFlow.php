<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Flows\Concerns\CollectsPhoneNumber;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\Prompt;
use App\Models\Park;
use App\Models\Reserve;
use App\Repositories\Contracts\ParkRepositoryInterface;
use App\Services\Payments\PaymentService;
use App\Services\ReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Customer flow: pre-book a parking space remotely and pay in advance.
 *
 * Unlike {@see NearbyParksFlow} (an on-site reservation that the space owner
 * settles when the car physically enters), a pre-booking lets the customer
 * reserve a space for a place they are *not* at yet (e.g. reserving near work
 * while still at home) and pay the price up-front. Because it is paid in
 * advance, the owner does not need to "enter the car" to trigger payment.
 *
 * Steps:
 *   ask_location → user shares the location of the place they want to park at
 *   choose_park  → user replies with the number of the park to reserve
 *   ask_time     → user picks (or types) the time they will arrive
 *   confirm      → user sends "تم الحجز" to confirm and receive the pay link
 */
class PreBookingFlow
{
    use CollectsPhoneNumber;

    public const FLOW = 'pre_booking';
    private const TTL_MINUTES = 15;
    private const RADIUS_METERS = 5000;
    private const LIMIT = 5;

    /** Callback payload prefix for a tapped park choice (carries its list number). */
    private const PARK_PREFIX = 'park:';

    /** How far ahead a pre-booking may be scheduled. */
    private const MAX_SCHEDULE_DAYS = 7;

    /** Words that confirm the booking and move the customer to payment. */
    private const CONFIRM_WORDS = ['تم الحجز', 'تم', 'تأكيد', 'confirm', 'دفع', 'pay'];

    public function __construct(
        private readonly ParkRepositoryInterface $parks,
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
        private readonly BotNotifier $notifier,
    ) {}

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
            'ask_location' => $this->showResults($session, $message),
            'ask_phone'    => $this->onPhoneStep($session, $message),
            'choose_park'  => $this->onParkChosen($session, $message),
            'ask_time'     => $this->onTimeChosen($session, $message),
            'confirm'      => $this->confirmAndPay($session, $message),
            default        => OutboundReply::empty(),
        };
    }

    private function start(BotSession $session): OutboundReply
    {
        if (!$session->getUser()) {
            return OutboundReply::text("📱 يجب تسجيل الدخول أولاً.");
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'ask_location',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            Prompt::ask("📍 شارك *موقع المكان الذي تريد الحجز فيه* (مثال: العمل) عبر زر الموقع في تطبيقك،\n")
        );
    }

    private function showResults(BotSession $session, string $message): OutboundReply
    {
        [$lat, $lng] = $this->parseCoords($message);
        if ($lat === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ موقع غير صالح. شارك الموقع أو أرسل: lat,lng")
            );
        }

        // Collect the customer's phone once before showing parks so the
        // owner can reach them (skipped if already known).
        if ($this->needsPhone($session->getUser())) {
            return $this->startPhoneGate($session, ['lat' => $lat, 'lng' => $lng], self::TTL_MINUTES);
        }

        return $this->showParks($session, $lat, $lng);
    }

    /**
     * Resume after the phone step: still collecting → return its reply;
     * captured → continue the park search with the stashed coordinates.
     */
    private function onPhoneStep(BotSession $session, string $message): OutboundReply
    {
        $result = $this->handlePhoneStep($session, $message);
        if ($result instanceof OutboundReply) {
            return $result;
        }

        return $this->showParks($session, $result['lat'], $result['lng']);
    }

    private function showParks(BotSession $session, float $lat, float $lng): OutboundReply
    {
        $parks = $this->parks->nearby(
            latitude:     $lat,
            longitude:    $lng,
            radiusMeters: self::RADIUS_METERS,
            limit:        self::LIMIT,
        );

        if ($parks->isEmpty()) {
            $session->reset();
            $km = self::RADIUS_METERS / 1000;
            return OutboundReply::text("😔 لا توجد مواقف فارغة ضمن {$km} كم من المكان المطلوب.");
        }

        // Persist the result list keyed by 1..N so a tapped button (or a typed
        // number, as a fallback) resolves back to a concrete park. The parks
        // are shown *only* as tappable choices — never duplicated in the body.
        $catalog = [];
        $options = [];

        foreach ($parks as $i => $park) {
            $n        = $i + 1;
            $distance = $this->formatDistance((int) round($park->distance_meters));

            $catalog[$n] = [
                'id'          => $park->id,
                'name'        => $park->name,
                'lat'         => (float) $park->lat,
                'lng'         => (float) $park->lng,
                'free_spaces' => (int)   $park->free_spaces,
            ];

            $options[] = [
                'id'          => self::PARK_PREFIX . $n,
                'title'       => $this->parkChoiceLabel($park->name, $distance, (int) $park->free_spaces),
                'description' => $this->parkChoiceDetail($park->name, $distance, (int) $park->free_spaces),
            ];
        }

        $session->update([
            'step'       => 'choose_park',
            'data'       => ['parks' => $catalog],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $body = "📍 أقرب المواقف الفارغة للمكان المطلوب:\n\n"
              . "اختر الموقف الذي تريد الحجز فيه، أو أرسل *0* للإلغاء.";

        return OutboundReply::buttons(
            body:       $body,
            options:    $options,
            listButton: 'اختر الموقف',
            linkButton: [
                'title' => '🗺️ عرض على الخريطة',
                'url'   => $this->buildAllOnMapUrl($parks, $lat, $lng),
            ],
        );
    }

    /**
     * Park chosen — validate the pick and ask *when* the customer will
     * arrive before holding a slot. The reservation itself is created in
     * {@see self::onTimeChosen()} once we know the time.
     */
    private function onParkChosen(BotSession $session, string $message): OutboundReply
    {
        $msg = trim(DigitNormalizer::toAscii($message));
        if (Str::startsWith($msg, self::PARK_PREFIX)) {
            $msg = substr($msg, strlen(self::PARK_PREFIX));
        }
        if (!ctype_digit($msg)) {
            return OutboundReply::text(Prompt::ask("⚠️ اختر الموقف من القائمة، أو أرسل رقمه (مثال: 1)."));
        }

        $catalog = $session->getData()['parks'] ?? [];
        $choice  = $catalog[(int) $msg] ?? null;

        if (!$choice) {
            return OutboundReply::text(
                Prompt::ask("⚠️ رقم غير صالح. اختر من الأرقام في القائمة أعلاه.")
            );
        }

        $park = Park::find($choice['id']);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ لم يعد هذا الموقف متاحاً.");
        }

        $session->update([
            'step'       => 'ask_time',
            'data'       => ['park' => $choice],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $this->askTime($park);
    }

    /**
     * Ask the customer for their arrival time. They type a 12-hour time
     * (e.g. "٣ العصر" or "٨ صباحاً غداً"); {@see self::parseSchedule()}
     * resolves it to a concrete future instant.
     */
    private function askTime(Park $park): OutboundReply
    {
        return OutboundReply::text(Prompt::ask(
            "📍 *{$park->name}*\n\n"
            . "🕒 متى ستصل إلى الموقف؟\n"
            . "اكتب وقت وصولك في رسالة.\n"
            . "_أمثلة: ٣ العصر • ٣:٣٠ العصر • ٨ صباحاً غداً_\n\n"
            . "⏳ مهلة الوصول ١٠ دقائق بعد الوقت المحدد، وبعدها يُلغى الحجز تلقائياً ويمكنك إعادته."
        ));
    }

    /**
     * Arrival time chosen — now place the hold for that time, notify the
     * owner, and move the customer to the confirm/pay step.
     */
    private function onTimeChosen(BotSession $session, string $message): OutboundReply
    {
        $choice = $session->getData()['park'] ?? null;
        if (!$choice) {
            $session->reset();
            return OutboundReply::text("❌ انتهت الجلسة. ابدأ من جديد.");
        }

        $scheduledAt = $this->parseSchedule($message);
        if ($scheduledAt === null) {
            return OutboundReply::text(Prompt::ask(
                "⚠️ وقت غير صالح. اختر وقتاً من القائمة، أو اكتبه:\n"
                . "_٣ العصر او ٣:٣٠ العصر او  ٨ صباحاً غداً_ (خلال ٧ أيام)"
            ));
        }

        $park = Park::find($choice['id']);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ لم يعد هذا الموقف متاحاً.");
        }

        try {
            $reserve = $this->reservations->reserve(
                $session->getUser(),
                $park,
                preBooking: true,
                scheduledAt: $scheduledAt,
            );
        } catch (RuntimeException $e) {
            $session->reset();
            return OutboundReply::text(
                $e->getMessage() === 'PARK_FULL'
                    ? " للأسف لم يعد هناك أماكن فارغة في هذا الموقف. حاول واحداً آخر."
                    : "⚠️ تعذر الحجز. حاول مرة أخرى."
            );
        }

        // notify the park owner about the new pre-booking.
        $this->notifyOwner($reserve, $park);

        // Move to the confirm/pay step, remembering which reserve to settle.
        $session->update([
            'step'       => 'confirm',
            'data'       => ['reserve_id' => $reserve->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $schedule = $this->formatSchedule($reserve->scheduled_at ?? $scheduledAt);
        $mapsUrl  = sprintf('https://www.google.com/maps?q=%F,%F', $choice['lat'], $choice['lng']);

        return OutboundReply::text(
            "✅ تم حجز مكان لك مسبقاً في *{$choice['name']}*\n\n"
            . "🕒 موعد وصولك: *{$schedule}*\n"
            . "🗺️ للاتجاهات: [اضغط هنا]({$mapsUrl})\n\n"
            . "💳 لإتمام الحجز، ادفع الآن بإرسال *تم الحجز*\n"
            . "_أو أرسل *0* للإلغاء._"
        );
    }

    private function confirmAndPay(BotSession $session, string $message): OutboundReply
    {
        if (!in_array(mb_strtolower(trim($message)), self::CONFIRM_WORDS, true)) {
            return OutboundReply::text(
                Prompt::ask("✳️ أرسل *تم الحجز* لتأكيد الحجز والانتقال للدفع المسبق، أو *0* للإلغاء.")
            );
        }

        $reserveId = $session->getData()['reserve_id'] ?? null;
        $reserve   = $reserveId ? Reserve::with('park')->find($reserveId) : null;

        if (!$reserve) {
            $session->reset();
            return OutboundReply::text("❌ تعذّر العثور على الحجز. ابدأ من جديد.");
        }

        try {
            $payment = $this->payments->ensureForReserve($reserve);
        } catch (Throwable $e) {
            Log::error('Pre-booking payment provisioning failed', [
                'reserve_id' => $reserve->id,
                'error'      => $e->getMessage(),
            ]);
            $session->reset();
            return OutboundReply::text("⚠️ تعذّر تجهيز الدفع. حاول لاحقاً.");
        }

        $session->reset();

        $url      = route('payments.redirect', $payment->token);
        $amount   = number_format((float) $payment->amount, 0) . ' ' . $payment->currency;
        $schedule = $reserve->scheduled_at
            ? $this->formatSchedule($reserve->scheduled_at)
            : null;

        return OutboundReply::text(
            "💳 *الدفع المسبق لحجزك*\n\n"
            . "📍 الموقف: *{$reserve->park->name}*\n"
            . ($schedule ? "🕒 موعد الوصول: *{$schedule}*\n" : '')
            . "💰 المبلغ: *{$amount}*\n\n"
            . "💳 لإتمام عملية الدفع: [اضغط هنا]({$url})\n\n"
            . "_بعد الدفع، سيؤكّد صاحب الموقف دخول سيارتك عند وصولك._"
        );
    }

    /**
     * Notify the park owner across every channel they're enrolled in.
     * Failures must not affect the customer's success response.
     */
    private function notifyOwner(Reserve $reserve, Park $park): void
    {
        try {
            $owner = $park->owner;
            if (!$owner) {
                return;
            }

            $customer     = $reserve->user;
            $customerName = $customer?->name ?: 'سائق';
            $customerPhone = $customer?->phone_number;
            $contactLine  = $customerPhone ? "📱 هاتف الزبون: *+{$customerPhone}*\n" : '';

            $schedule = $reserve->scheduled_at
                ? $this->formatSchedule($reserve->scheduled_at)
                : ($reserve->expires_at
                    ? $this->formatSchedule($reserve->expires_at)
                    : '—');

            $message = "🔔 *حجز مسبق جديد في موقفك*\n\n"
                     . "الموقف: *{$park->name}*\n"
                     . "الزبون: *{$customerName}*\n"
                     . $contactLine
                     . "🕒 موعد الوصول: *{$schedule}*\n"
                     . "الأماكن المتبقية: *{$park->free_spaces}*\n\n"
                     . "_حجز مسبق مدفوع — لا حاجة لإدخال السيارة لتفعيل الدفع._";

            $this->notifier->notify($owner, OutboundReply::text($message));
        } catch (Throwable $e) {
            Log::warning('Owner pre-booking notification failed', [
                'reserve_id' => $reserve->id,
                'park_id'    => $park->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function formatDistance(int $meters): string
    {
        $value = $meters >= 1000
            ? rtrim(rtrim(number_format($meters / 1000, 1), '0'), '.') . ' كم'
            : "{$meters} متر";

        return DigitNormalizer::toArabic($value);
    }

    /**
     * Tappable label for a park choice, e.g.
     *   "كراج المروج ، يبعد مسافة ١٠٠ متر ، ٢٠ مكان متاح متوفر".
     * Arabic-named parks read "يبعد مسافة", others read "يبعد".
     */
    private function parkChoiceLabel(string $name, string $distance, int $freeSpaces): string
    {
        return trim($name) . ' ، ' . $this->parkChoiceDetail($name, $distance, $freeSpaces);
    }

    /**
     * The distance + availability portion of a park choice, used as the
     * label suffix and as the WhatsApp list-row description.
     */
    private function parkChoiceDetail(string $name, string $distance, int $freeSpaces): string
    {
        $verb = preg_match('/\p{Arabic}/u', $name) ? 'يبعد مسافة' : 'يبعد';
        $free = DigitNormalizer::toArabic((string) $freeSpaces);

        return "{$verb} {$distance} ، {$free} مكان متاح متوفر";
    }

    /**
     * Resolve the user's reply into a concrete future arrival time.
     *
     * Accepts a typed 12-hour time. The hour is 1–12 and the period is an
     * Arabic/English word: "٣ العصر" → 3 PM, "٣ الصبح" → 3 AM, "٨:٣٠ مساءً غداً" → 20:30
     * tomorrow. When no period is given, the next future occurrence of that
     * hour (AM or PM) is chosen. Returns null when it can't be understood
     * or falls outside the allowed window.
     */
    private function parseSchedule(string $message): ?Carbon
    {
        $raw = trim($message);

        $text = trim(mb_strtolower(DigitNormalizer::toAscii($raw)));

        // A "tomorrow" word may sit at either end ("غداً ٨ صباحاً" / "٨ صباحاً غداً").
        $tomorrow = false;
        foreach (['غداً', 'غدًا', 'غدا', 'بكرة', 'بكره', 'tomorrow'] as $word) {
            if (str_starts_with($text, $word)) {
                $tomorrow = true;
                $text     = trim(mb_substr($text, mb_strlen($word)));
                break;
            }
            if (str_ends_with($text, $word)) {
                $tomorrow = true;
                $text     = trim(mb_substr($text, 0, -mb_strlen($word)));
                break;
            }
        }

        if (!preg_match('/^(\d{1,2})(?:[:.]\s*(\d{2}))?\s*(.*)$/u', $text, $m)) {
            return null;
        }

        $hour   = (int) $m[1];
        $minute = ($m[2] ?? '') !== '' ? (int) $m[2] : 0;
        $period = trim($m[3] ?? '');

        if ($minute > 59) {
            return null;
        }

        $meridiem = $this->detectMeridiem($period);
        if ($meridiem === null) {
            return null; // unrecognised trailing text
        }

        // Explicit AM/PM → 12-hour clock (hour must be 1–12).
        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $h24    = $meridiem === 'am' ? $hour % 12 : ($hour % 12) + 12;
            $target = ($tomorrow ? now()->addDay() : now())->setTime($h24, $minute, 0);
            if (!$tomorrow && $target->lte(now())) {
                $target->addDay();
            }
            return $this->validateSchedule($target);
        }

        // No period word. A 13–23 value is an unambiguous 24-hour time.
        if ($hour > 12) {
            if ($hour > 23) {
                return null;
            }
            $target = ($tomorrow ? now()->addDay() : now())->setTime($hour, $minute, 0);
            if (!$tomorrow && $target->lte(now())) {
                $target->addDay();
            }
            return $this->validateSchedule($target);
        }

        // Ambiguous 12-hour hour (1–12) → next future occurrence.
        return $this->nextOccurrence($hour, $minute, $tomorrow);
    }

    /**
     * Classify a trailing period word as morning or evening.
     *
     * @return 'am'|'pm'|''|null  '' when no word was given, null when the
     *         word is present but unrecognised.
     */
    private function detectMeridiem(string $period): ?string
    {
        if ($period === '') {
            return '';
        }

        $am = ['ص', 'صباح', 'صباحا', 'صباحاً', 'الصبح', 'صبحا', 'فجر', 'فجرا', 'فجراً', 'الفجر', 'am', 'a'];
        $pm = ['م', 'مساء', 'مساءا', 'مساءً', 'المساء', 'عصر', 'عصرا', 'عصراً', 'العصر',
               'ظهر', 'ظهرا', 'ظهراً', 'الظهر', 'بعد الظهر', 'الليل', 'ليلا', 'ليلاً',
               'مغرب', 'المغرب', 'pm', 'p'];

        if (in_array($period, $am, true)) {
            return 'am';
        }
        if (in_array($period, $pm, true)) {
            return 'pm';
        }
        return null;
    }

    /**
     * Earliest future instant matching a 12-hour hour with no stated
     * period — considers both the AM and PM reading (today, then tomorrow,
     * or only tomorrow when the user said "غداً").
     */
    private function nextOccurrence(int $hour, int $minute, bool $tomorrow): ?Carbon
    {
        $base = $hour % 12; // 12 → 0
        $best = null;

        foreach (($tomorrow ? [1] : [0, 1]) as $dayOffset) {
            foreach ([$base, $base + 12] as $h24) {
                $candidate = now()->addDays($dayOffset)->setTime($h24, $minute, 0);
                if ($candidate->isFuture() && ($best === null || $candidate->lt($best))) {
                    $best = $candidate;
                }
            }
        }

        return $best ? $this->validateSchedule($best) : null;
    }

    /**
     * Guard the arrival time to a sane window: strictly in the future and
     * no further than {@see self::MAX_SCHEDULE_DAYS} ahead.
     */
    private function validateSchedule(Carbon $target): ?Carbon
    {
        if ($target->lte(now()) || $target->gt(now()->addDays(self::MAX_SCHEDULE_DAYS))) {
            return null;
        }

        return $target;
    }

    /**
     * Human-friendly arrival label in the app timezone, e.g.
     * "اليوم ١٧:٣٠", "غداً ٠٩:٠٠", or "٢٠٢٦/٠٦/٢٥ ١٤:٠٠".
     */
    private function formatSchedule(\DateTimeInterface $when): string
    {
        $when = Carbon::instance($when)->setTimezone(config('app.timezone'));
        $time = $when->format('h:i A');

        $label = match (true) {
            $when->isToday()    => "اليوم {$time}",
            $when->isTomorrow() => "غداً {$time}",
            default             => $when->format('Y/m/d') . " {$time}",
        };

        return DigitNormalizer::toArabic($label);
    }

    /**
     * Build a public URL pointing at our /parks/map page with the entire
     * result list packed into a single short token. The page is stateless
     * and reads everything from the URL.
     */
    private function buildAllOnMapUrl(Collection $parks, float $userLat, float $userLng): string
    {
        $rows = $parks->map(fn ($p) => sprintf(
            '%F,%F,%d,%s',
            $p->lat,
            $p->lng,
            (int) $p->free_spaces,
            str_replace([',', '|'], ' ', mb_substr((string) $p->name, 0, 40)),
        ))->implode('|');

        $token = rtrim(strtr(base64_encode($rows), '+/', '-_'), '=');

        return route('parks.map', [
            'p'   => $token,
            'lat' => $userLat,
            'lng' => $userLng,
        ]);
    }

    /**
     * take the location from msg in whatsapp bot/ telegram bot
     *  and convert it into lat,lng
     * @return array{0: ?float, 1: ?float}
     */
    private function parseCoords(string $message): array
    {
        $parts = array_map('trim', explode(',', $message));
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return [null, null];
        }

        $lat = (float) $parts[0];
        $lng = (float) $parts[1];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return [null, null];
        }

        return [$lat, $lng];
    }
}
