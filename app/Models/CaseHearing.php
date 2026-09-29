<?php

namespace App\Models;

use App\Domain\Journey\Enums\HearingStatus;
use App\Support\MeetingTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * جلسة قضية — يجدولها المحامي ويسجّل نتيجتها، ويتابعها العميل.
 */
class CaseHearing extends Model
{
    protected $fillable = [
        'case_id', 'postponed_from_id', 'title', 'day', 'time', 'court', 'status', 'outcome',
        'starts_at', 'duration_min', 'reminder_24h_sent_at', 'reminder_1h_sent_at',
    ];

    /**
     * **حدود المدّة المتوقّعة بالدقائق** (قرار المالك 2026-09-26) — من جلسةٍ قصيرة للنطق بقرار
     * إلى يوم عملٍ كامل. خارجها خطأ إدخال لا جلسة: ٦٠٠٠ دقيقة تعني أن أحداً كتب رقماً زائداً.
     */
    public const DURATION_MIN = 5;

    public const DURATION_MAX = 600;

    protected $casts = [
        'starts_at' => 'datetime',
        'duration_min' => 'integer',
        'reminder_24h_sent_at' => 'datetime',
        'reminder_1h_sent_at' => 'datetime',
    ];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /**
     * الجلسة التي أُجّلت إلى هذه. التأجيل لا يمحو الجلسة السابقة: تبقى «مؤجلة» بسببها،
     * وهذه صفٌّ جديد يشير إليها — فتُقرأ سلسلة التأجيلات كما جرت في المحكمة.
     */
    public function postponedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'postponed_from_id');
    }

    /** الجلسة التي أُجّلت هذه إليها — وجودها يعني أن موعد هذه حُسم فلا يُحرَّك ثانيةً. */
    public function postponedTo(): HasOne
    {
        return $this->hasOne(self::class, 'postponed_from_id');
    }

    public function statusEnum(): ?HearingStatus
    {
        return HearingStatus::of($this->status);
    }

    /**
     * **لون الجلسة الحيّ** — الفائتة حمراء وإن بقيت «مجدولة» مخزَّنة، وغيرها بلون حالتها
     * (`HearingStatus::tone`). المصدر الواحد لشاشة جلسات الإدارة والتقويم الزمنيّ.
     */
    public function liveTone(): string
    {
        return ($this->isLapsed() ? HearingStatus::Lapsed : ($this->statusEnum() ?? HearingStatus::Scheduled))->tone();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CaseDocument::class, 'hearing_id');
    }

    /** صياغة الموعد الموحّدة (اليوم · الوقت) — من starts_at الحقيقي وإلا النصوص المخزّنة */
    public function label(): string
    {
        $day = $this->starts_at?->locale('ar')->translatedFormat('l d F Y') ?: (string) $this->day;

        return trim($day.($this->time ? ' · '.$this->time : ''));
    }

    /**
     * **قاعدة تحقّق المدّة المتوقّعة** — مصدرٌ واحد لكلّ نموذج يُنشئ جلسة أو يعدّلها أو يؤجّلها
     * (ومنه رسائلها العربيّة عبر `lang/ar/validation.php`). اختياريّة: تركُها فارغةً قرارٌ صادق.
     *
     * @return array<int, string>
     */
    public static function durationRule(): array
    {
        return ['nullable', 'integer', 'min:'.self::DURATION_MIN, 'max:'.self::DURATION_MAX];
    }

    /**
     * **بداية الجلسة** — `starts_at` الحقيقيّ، وإلا نصّا اليوم والوقت للجلسات القديمة قبل العمود.
     * مصدرٌ واحد لما يضعه التقويم والاشتراك بدايةً، فلا تختلف لحظة الجلسة بين شاشةٍ وأخرى.
     */
    public function startMoment(): ?CarbonInterface
    {
        return $this->starts_at ?: MeetingTime::parse($this->day, $this->time);
    }

    /**
     * **نهاية الجلسة المتوقّعة — من المدّة المُدخلة وحدها** (قرار المالك 2026-09-26).
     *
     * كانت النهاية ستّين دقيقة منقوشة في التقويم: رقمٌ لا يعرفه المكتب ولا المحكمة. فالآن إن أدخل
     * الطاقم مدّةً فالنهاية البداية + المدّة، وإلا **null** — لا نهاية مختلَقة. ولا يقرؤها منطق
     * «انعقدت/فاتت»: ذلك من الحالة المسجّلة (`HearingStatus`) لا من الساعة.
     */
    public function endsAt(): ?CarbonInterface
    {
        $start = $this->startMoment();

        return ($start && $this->duration_min) ? $start->copy()->addMinutes($this->duration_min) : null;
    }

    /** جلسة مجدولة فات موعدها ولم تُسجَّل نتيجتها — الحالة المخزّنة «مجدولة» لا تتحدّث بمرور الوقت */
    public function isLapsed(): bool
    {
        // الموسومة فائتةً من المجدول فائتة بالتخزين لا بالاشتقاق
        if ($this->statusEnum() === HearingStatus::Lapsed) {
            return true;
        }

        return $this->statusEnum() === HearingStatus::Scheduled
            && $this->starts_at !== null
            && $this->starts_at->isPast();
    }

    public function toData(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            // يوم/وقت بصياغة عربية من starts_at الحقيقي عند وجوده، وإلا النصوص المخزّنة
            'day' => $this->starts_at?->locale('ar')->translatedFormat('l d F Y') ?: $this->day,
            'time' => $this->starts_at?->locale('ar')->translatedFormat('h:i A') ?: $this->time,
            'court' => $this->court,
            'status' => $this->status,
            'lapsed' => $this->isLapsed(), // للواجهة: شارة «فائتة — بانتظار النتيجة» بدل «مجدولة» الكاذبة
            'outcome' => $this->outcome,
            'startsAt' => $this->starts_at?->toIso8601String(), // لتعبئة نموذج التعديل في الواجهة
            // المدّة المتوقّعة بالدقائق — null ⇒ لا تعرض الواجهة نهايةً ولا مدّة (لا رقمَ مختلَق)
            'durationMin' => $this->duration_min,
            'endsAt' => $this->endsAt()?->toIso8601String(),
            // سلسلة التأجيل: الواجهة تجد السابقة في القائمة نفسها بمعرّفها — فلا استعلام لكلّ جلسة،
            // وتعرف منها أيضاً أيّ الجلسات لها تالية («مؤجّلة إلى …») فلا يُحرَّك موعدها
            'postponedFromId' => $this->postponed_from_id,
            // ما يجوز من الخادم لا من مقارنة نصوص الحالة في الواجهة — الحرّاس نفسها في ManagesCourtProceedings
            'canRecord' => (bool) $this->statusEnum()?->awaitsOutcome(),
            'canEdit' => $this->statusEnum()?->isFinal() === false,
            'canCancel' => (bool) $this->statusEnum()?->awaitsOutcome(),
        ];
    }
}
