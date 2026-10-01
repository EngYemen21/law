<?php

namespace App\Models;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Domain\Journey\Transitions\Meeting\CancelMeeting;
use App\Domain\Journey\Transitions\Meeting\EndMeeting;
use App\Domain\Journey\Transitions\Meeting\StartMeeting;
use App\Enums\Role;
use App\Models\Concerns\TracksRevisions;
use App\Support\ArabicCount;
use App\Support\LawyerName;
use App\Support\MeetingTime;
use App\Support\RecordingArchive;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use App\Support\ZoomSummaryText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * الاجتماع الكامل (FullMeeting): قبل/أثناء/بعد + محضر وملخص + اعتماد الإدارة + جلسة Zoom.
 */
class Meeting extends Model
{
    use TracksRevisions;

    /**
     * نصّا العمودين `approve` و`conf` كما يُخزَّنان — الموضع الواحد لهما (قرار المالك 2026-10-01).
     * المنطق يقرأ `isApproved()` / `isConfidential()` والواجهة علَمَي `approved` / `confidential`، لا النصّ.
     */
    public const APPROVED = 'معتمد';

    public const CONF_SECRET = 'سري';

    protected $fillable = [
        'user_id', 'ref', 'title', 'type', 'client_name', 'when_label', 'starts_at', 'reminder_sent_at',
        'reminder_near_sent_at', 'link_released_at',
        // `dur`: عمودٌ تاريخيّ — ما حُفظ فيه يبقى مسافةً محجوزة على تقويم المحامي (`LawyerAvailability`)،
        // ولا يُكتب جديداً ولا يُنهي الاجتماع (قرار المالك 2026-09-26).
        'status', 'priority', 'conf', 'attend', 'dur', 'approve',
        'summary', 'sum_approved', 'minutes', 'participants', 'case_ref',
        'decisions', 'tasks_created', 'suggested_tasks',
        'meet_id', 'meet_link', 'host_link', 'meet_password', 'created_by',
        'reschedule_requested_at', 'reschedule_count',
        'assigned_lawyer_id',
        // «القادم» يُشتقّ حيّاً من liveState/isUpcoming (عمود is_up المهجور حُذف 2026-10-01)
        'has_link', 'has_minutes', 'has_summary',
        'zoom_summary', 'zoom_summary_at',
        'recording_url', 'transcript_path', 'join_time', 'leave_time', 'duration_sec',
        'zoom_uuid', 'zoom_share_url', 'zoom_audio_url', 'zoom_participants_log', 'zoom_ai_next_steps',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'reschedule_requested_at' => 'datetime',
        'reschedule_count' => 'integer',
        'reminder_sent_at' => 'datetime',
        'reminder_near_sent_at' => 'datetime',
        'link_released_at' => 'datetime',
        'zoom_summary_at' => 'datetime',
        'join_time' => 'datetime',
        'leave_time' => 'datetime',
        'has_link' => 'boolean',
        'has_minutes' => 'boolean',
        'has_summary' => 'boolean',
        'sum_approved' => 'boolean',
        'tasks_created' => 'boolean',
        'decisions' => 'array',
        'suggested_tasks' => 'array',
        'zoom_participants_log' => 'array',
        'zoom_ai_next_steps' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // المحامي المسند بالمعرّف (لعزل الرؤية والبثّ)
    /** @return BelongsTo<User, $this> */
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    /**
     * **المشاركون من الكادر — حسابات** (`meeting_participants`، قرار المالك 2026-09-28). عمود `participants`
     * النصّيّ بقي لاجتماعاتٍ قديمة لم يُطابَق فيها اسمٌ بحساب — يُعرض ملاحظةً ولا يُقرأ للصلاحيّة.
     */
    public function participantUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants')->withTimestamps();
    }

    /**
     * **ما يراه المحامي: المسنَد إليه، أو ما هو مشاركٌ فيه** — التعريف الواحد لقوائمه وتقويمه واشتراكه
     * وغرفته (`involves`). كانت كلّها `assigned_lawyer_id` وحده، فيُشعَر المشارك «متاح في لوحتك» ولا يجده.
     *
     * @param  Builder<Meeting>  $query
     */
    public function scopeVisibleToLawyer(Builder $query, int $lawyerId): void
    {
        $query->where(fn (Builder $q) => $q->where('assigned_lawyer_id', $lawyerId)
            ->orWhereHas('participantUsers', fn (Builder $p) => $p->whereKey($lawyerId)));
    }

    /** هل للمستخدم من الكادر صلةٌ بالاجتماع (مسؤولٌ أو مشارك)؟ — نظير `scopeVisibleToLawyer` للسجلّ الواحد. */
    public function involves(User $user): bool
    {
        return (int) $this->assigned_lawyer_id === (int) $user->id
            || $this->participantUsers()->whereKey($user->id)->exists();
    }

    /**
     * **أهل الاجتماع من الكادر:** المسؤول والمشاركون — مَن يُحجب وقته به (`LawyerAvailability`)،
     * فيفحصهم حارس إعادة الجدولة وشبكتها.
     *
     * @return array<int,int>
     */
    public function staffIds(): array
    {
        return array_values(array_unique(array_filter([
            (int) $this->assigned_lawyer_id,
            ...$this->participantUsers()->pluck('users.id')->map('intval')->all(),
        ])));
    }

    /** أسماء المشاركين للعرض: الحسابات، وإلا النصّ القديم لاجتماعٍ لم يُربط. */
    public function participantsLabel(): ?string
    {
        $names = $this->participantUsers->pluck('name')->all();

        return $names !== [] ? implode('، ', $names) : ($this->participants ?: null);
    }

    /** موعد البدء: starts_at الحقيقي، أو تحليل دفاعي لـ when_label («اليوم · 09:00 ص») */
    public function startsAtResolved(): ?Carbon
    {
        if ($this->starts_at) {
            return Carbon::instance($this->starts_at);
        }

        $parts = array_map('trim', explode('·', (string) $this->when_label));

        return MeetingTime::parse($parts[0] ?? null, $parts[1] ?? null);
    }

    /**
     * **فات دون أن يبدأ؟** — مهلة الفوات من البداية (`SessionWindow`)، لا «البداية + المدّة».
     * تعذُّر تحليل الموعد ⇒ لم يفُت (سلوك آمن).
     */
    public function isMissed(): bool
    {
        return SessionWindow::isMissed($this->startsAtResolved());
    }

    /**
     * **بدأ فعلاً؟** — حالته «جارٍ»، أو سجّل Zoom دخول أحد أطرافه وإن فات حدثُ البدء.
     * يُقرأ هنا وفي شبكة النسيان (`sessions:close-stale` · `AlertStaleMeeting`) — تعريفٌ واحد لـ«بدأ ولم يُنهَ».
     */
    public function hasStarted(): bool
    {
        return $this->status === MeetingStatus::Live->value || $this->join_time !== null;
    }

    /**
     * الحالة الحيّة المشتقّة [when, status, tone] — الحالة المخزّنة لا تتحدّث بمرور الوقت
     * (نظير Appointment::liveState): «قادم» فات دون أن يبدأ يُعرض «لم ينعقد» فوراً دون انتظار
     * المجدول، و«بانتظار التأكيد»/«مؤجل» يبقيان في القادمة.
     *
     * **والجاري جارٍ حتى يُنهى** (قرار المالك 2026-09-26): كان «جارٍ» يُعرض «منتهٍ» بعد «المدة +
     * 120د»، و«قادمٌ» دخله أحدٌ يُعرض «منتهٍ» بمجرّد فوات موعده — نهايتان تقرّرهما الساعة. الآن
     * النهاية حدث `EndMeeting` (زرّ الطاقم أو ويبهوك Zoom أو شبكة النسيان بعد مهلتها — `sessions:close-stale`).
     * النغمات تطابق meetStatusTone في resources/js/lib/meeting-ui.tsx (المصدر الموحّد).
     *
     * @return array{0:string,1:string,2:string}
     */
    public function liveState(): array
    {
        $status = MeetingStatus::tryFrom((string) $this->status);

        return match (true) {
            $status === MeetingStatus::Cancelled => ['past', MeetingStatus::Cancelled->value, 'b-red'],
            $status === MeetingStatus::Ended => ['past', MeetingStatus::Ended->value, 'b-green'],
            $status === MeetingStatus::Missed => ['past', MeetingStatus::Missed->value, 'b-grey'],
            $this->hasStarted() => ['up', MeetingStatus::Live->value, 'b-amber'],
            // قادم / مؤجل / بانتظار التأكيد — قادمة ما لم يفت موعدها دون أن تبدأ
            // («بانتظار التأكيد» حالة تاريخية: لا يكتبها مسار حيّ منذ إلغاء تأكيد العميل — الفرع دفاع عن سجلّات قديمة)
            ! $this->isMissed() => ['up', (string) $this->status !== '' ? (string) $this->status : MeetingStatus::Upcoming->value, match ($status) {
                MeetingStatus::Postponed => 'b-grey',
                MeetingStatus::AwaitingConfirmation => 'b-amber',
                default => 'b-blue',
            }],
            default => ['past', MeetingStatus::Missed->value, 'b-grey'],
        };
    }

    /**
     * **هل انعقد الاجتماع فعلاً؟** — بما يشهد به Zoom، لا بما كُتب في عمود الحالة.
     *
     * الحالة المخزّنة تتأخّر عن الحقيقة: اجتماعٌ دخله أطرافه وسُجّل ما زال «قادماً» حتى يمرّ
     * المجدول أو يضغط أحدٌ «إنهاء». وكانت إعادة جدولته في تلك الفجوة تمحو `recording_url`
     * و`join_time` — أي تمحو الدليل الوحيد على جلسةٍ وقعت. فالشاهد هنا: دخولٌ مسجَّل، أو
     * تسجيلٌ محفوظ، أو حالةٌ تقول «منتهٍ» (لا تُشتقّ من الساعة — الاجتماع ينتهي حين يُنهى).
     */
    public function wasHeld(): bool
    {
        return $this->join_time !== null
            || $this->recording_url !== null
            || $this->liveState()[1] === MeetingStatus::Ended->value;
    }

    /**
     * الحضور من Zoom لا من إدخالٍ بشريّ.
     *
     * العمود `attend` لم يُكتب من Zoom قطّ — يُملأ يدوياً أو يُصفَّر، ثمّ يُعرض «حضور 0%»
     * فيقرأه القارئ «لم يحضر أحد» والحقيقة «لم يُقَس». وسجلّ Zoom المفصّل مخزّنٌ فعلاً في
     * `zoom_participants_log` (اسم/بريد/دخول/خروج/مدة لكلّ مشارك) ولم يكن أحدٌ يقرؤه.
     *
     * الثلاثة تُرجع null عند تعذّر القياس — لا صفراً. والصفر رقمٌ يدّعي قياساً.
     */

    /** عدد من دخل الجلسة فعلاً — موحَّداً بالبريد ثمّ الاسم (Zoom يكرّر الصفّ عند انقطاع الشبكة). */
    public function attendedCount(): ?int
    {
        $log = $this->zoom_participants_log;
        if (! is_array($log) || $log === []) {
            return null;
        }

        $seen = [];
        foreach ($log as $p) {
            if (empty($p['join_time'])) {
                continue; // مدعوٌّ ظهر في السجلّ ولم يدخل
            }
            $key = mb_strtolower(trim((string) ($p['email'] ?? ''))) ?: mb_strtolower(trim((string) ($p['name'] ?? '')));
            if ($key !== '') {
                $seen[$key] = true;
            }
        }

        return count($seen);
    }

    /**
     * عدد المدعوّين: العميل + المحامي المسند + المشاركون (حساباتهم، أو النصّ القديم)، موحَّدةً.
     */
    public function invitedCount(): ?int
    {
        $names = [];

        if ($this->user_id && $this->user) {
            $names[] = $this->user->name;
        }
        if ($this->assigned_lawyer_id && $this->assignedLawyer) {
            $names[] = $this->assignedLawyer->name;
        }
        $linked = $this->participantUsers->pluck('name')->all();
        // النصّ القديم لاجتماعٍ لم يُربط مشاركوه بحسابات وحده
        foreach ($linked !== [] ? $linked : (preg_split('/[،,]/u', (string) $this->participants, -1, PREG_SPLIT_NO_EMPTY) ?: []) as $raw) {
            $names[] = (string) preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($raw));
        }

        $uniq = [];
        foreach ($names as $n) {
            $n = mb_strtolower(trim($n));
            if ($n !== '') {
                $uniq[$n] = true;
            }
        }

        return $uniq === [] ? null : count($uniq);
    }

    /** متوسّط بقاء الحاضرين من مدّة الجلسة الفعلية (٪) — null إن غابت المدّة أو السجلّ. */
    public function presenceRate(): ?int
    {
        $log = $this->zoom_participants_log;
        $total = (int) ($this->duration_sec ?? 0);
        if (! is_array($log) || $log === [] || $total <= 0) {
            return null;
        }

        // المدد تُجمَع لكلّ مشارك (الانقطاع يولّد صفوفاً متعدّدة للشخص نفسه)
        $per = [];
        foreach ($log as $p) {
            if (empty($p['join_time'])) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($p['email'] ?? ''))) ?: mb_strtolower(trim((string) ($p['name'] ?? '')));
            if ($key === '') {
                continue;
            }
            $per[$key] = ($per[$key] ?? 0) + (int) ($p['duration_sec'] ?? 0);
        }

        if ($per === []) {
            return null;
        }

        return min(100, (int) round(array_sum($per) / count($per) / $total * 100));
    }

    public function isUpcoming(): bool
    {
        return $this->liveState()[0] === 'up';
    }

    /** اعتمدت الإدارة المحضر والملخص؟ — الموضع الوحيد الذي يقرأ نصّ العمود `approve`. */
    public function isApproved(): bool
    {
        return $this->approve === self::APPROVED;
    }

    /** اجتماعٌ سرّيّ؟ — يقرؤه إنشاء جلسة Zoom (`createMeeting`) وعلَم `confidential` في البطاقة. */
    public function isConfidential(): bool
    {
        return self::isConfidentialLevel($this->conf);
    }

    /** مستوى السرّيّة المُرسَل (قبل وجود الاجتماع) سرّيّ؟ — المقارنة الواحدة بالثابت. */
    public static function isConfidentialLevel(?string $conf): bool
    {
        return $conf === self::CONF_SECRET;
    }

    /**
     * **مخرجاتٌ حقيقيّة** (ملخّص Zoom أو تدوينٌ يدويّ) — لا النصوص القالبيّة («بانتظار ملخص الجلسة
     * من Zoom») المملوءة تقنيّاً بلا مضمون؛ اعتمادها كان يعرض للعميل محضراً رسميّاً فارغاً (حادثة M-26753).
     */
    public function hasRealOutput(): bool
    {
        return (filled($this->summary) && ! ZoomSummaryText::isPlaceholderSummary($this->summary))
            || (filled($this->minutes) && ! ZoomSummaryText::isPlaceholderMinutes($this->minutes));
    }

    /**
     * **لماذا لا يُعتمد الآن — `null` = يُعتمد.** القاعدة الواحدة لحارس `approve` في المتحكّم ولعلَم
     * `canApprove` في البطاقة والبثّ: كانت الواجهة تشرط الزرّ بـ«النصّ غير فارغ» والخادم يرفض القالبيّ
     * بـ٤٢٢ — زرٌّ يُعرض ثمّ يُردّ.
     */
    public function approvalBlocker(): ?string
    {
        if ($this->isApproved()) {
            return 'الاجتماع معتمد نهائيًّا.';
        }

        return $this->status === MeetingStatus::Ended->value && $this->hasRealOutput()
            ? null
            : 'الاعتماد متاح بعد انتهاء الاجتماع ووصول ملخص Zoom أو تدوين المحضر يدوياً.';
    }

    public function canApprove(): bool
    {
        return $this->approvalBlocker() === null;
    }

    /**
     * **عدد ما ينتظر الاعتماد فعلاً** — منتهٍ غير معتمد، ثمّ حكم `canApprove` نفسه (المخرجات الحقيقيّة
     * لا تُقرَّر في SQL: القوالب الفارغة نصٌّ غير فارغ). كان رادار اللوحة يعدّ كلّ منتهٍ غير معتمد،
     * فيعرض «محضراً بانتظار الاعتماد» لا زرّ اعتمادٍ له (قرار المالك 2026-10-01).
     */
    public static function awaitingApprovalCount(): int
    {
        return static::query()
            ->where('status', MeetingStatus::Ended->value)
            // المعتمد لا يُجلب أصلاً — العمود غير قابلٍ لـnull فالمقارنة لا تُسقط صفّاً
            ->where('approve', '!=', self::APPROVED)
            ->get(['id', 'status', 'approve', 'summary', 'minutes'])
            ->filter(fn (Meeting $m) => $m->canApprove())
            ->count();
    }

    /**
     * هل يُفعَّل زر «الدخول للاجتماع»؟
     *
     * - **جارٍ** ⇒ مفتوح حتى يُنهى — لا سقف «المدة + 30د» يُغلق غرفةً منعقدة.
     * - **لم يبدأ** ⇒ من فتح الدخول قبل الموعد (`session_join_opens_minutes`) حتى يفوت دون أن يبدأ (`liveState` تُخرجه من «القادمة»
     *   حينها) — فالنافذة مغلقة الطرفين ولا يبقى الزرّ فعّالاً للأبد بعد فوات الموعد.
     * بلا موعدٍ قابل للتحليل ⇒ يُحسم بالحالة وحدها (السلوك السابق).
     */
    public function canJoin(): bool
    {
        return $this->joinBlocker() === null;
    }

    /**
     * **لماذا لا يُدخَل إلى الغرفة الآن — `null` = يُدخَل.** نظير `Consult::joinBlocker` بالنصوص
     * نفسها (`SessionWindow::REFUSE_*`) — مصدرُ `canJoin()` ونصُّ الرفض في الغرف ونقطة التوقيع.
     */
    public function joinBlocker(): ?string
    {
        [, $status] = $this->liveState();

        return match ($status) {
            MeetingStatus::Cancelled->value => SessionWindow::REFUSE_CANCELLED,
            MeetingStatus::Ended->value => SessionWindow::REFUSE_ENDED,
            MeetingStatus::Missed->value => SessionWindow::REFUSE_MISSED,
            MeetingStatus::Live->value => null,
            default => SessionWindow::joinOpened($this->startsAtResolved()) ? null : SessionWindow::refuseNotOpen(),
        };
    }

    /**
     * **جارٍ الآن؟** — بدأ (حالته «جارٍ» أو سجّل Zoom دخولاً) ولم يُختم ولم يُحسم «لم ينعقد».
     * القاعدة الواحدة لـ«يجوز إنهاؤه» من الطاقم أو شبكة النسيان (`EndMeeting::guard`) ولعلَم
     * `live` في عقد الغرفة — نظيرُها `Consult::isLive()`.
     */
    public function isLive(): bool
    {
        return $this->liveState()[1] === MeetingStatus::Live->value;
    }

    /**
     * رابط دخول الجلسة المضمّنة داخل المنصّة حصراً (لا روابط خارجية تخرج المستخدم عن المنصة).
     */
    public function joinLink(?User $user = null): string
    {
        $ref = $this->ref ?: 'M-'.$this->id;

        if ($user) {
            return match ($user->role) {
                Role::Client => url('/meetingroom?ref='.$ref),
                Role::Lawyer => url('/lawyer/meetingroom?ref='.$ref),
                Role::Employee => url('/employee/meetingroom?ref='.$ref),
                Role::Admin => url('/admin/meetingroom?ref='.$ref),
                default => url('/meetingroom?ref='.$ref),
            };
        }

        return url('/meetingroom?ref='.$ref);
    }

    /**
     * رابط تبويب الاجتماعات/الدعوات بحسب دور المستخدم (لتوجيهه عند الدخول للمنصة).
     */
    public function portalUrlFor(?User $user = null): string
    {
        if (! $user) {
            return url('/meetreqs');
        }

        return match ($user->role) {
            Role::Client => url('/meetreqs'),
            Role::Lawyer => url('/lawyer/meetreqs'),
            Role::Employee => url('/employee/meetreqs'),
            Role::Admin => url('/admin/meetmgmt'),
            // وجهة العميل المباشرة — /meetreqs صار تحويلة إلى /meetings فلا داعي للقفزة
            default => url('/meetings'),
        };
    }

    /** افتراضا طبقتي التذكير (`meeting_reminder_lead` · `meeting_reminder_near_minutes`) — بريدٌ ثمّ إشعارٌ ورسالة للعميل. */
    public const REMINDER_FAR_MINUTES = 60;

    public const REMINDER_NEAR_MINUTES = 30;

    /** سقف إعادة جدولة الاجتماع الافتراضيّ — ما بعده للإدارة العليا وحدها (`meeting_reschedule_limit`). */
    public const RESCHEDULE_LIMIT = 2;

    public static function rescheduleLimit(): int
    {
        return SettingsRegistry::int('meeting_reschedule_limit');
    }

    /** بلغ الاجتماع سقف إعادة الجدولة — فلا يطلب العميل تغييره ولا يعيد جدولته غير الإدارة العليا. */
    public function reachedRescheduleLimit(): bool
    {
        return (int) $this->reschedule_count >= self::rescheduleLimit();
    }

    /**
     * **لماذا لا يطلب العميل تغيير الموعد الآن؟** — `null` = يطلب. الطلب القائم لا يتكرّر (كان كلّ ضغطٍ
     * يُرسل إشعاراً جديداً للمحامي والإدارة)، وبعد السقف يتواصل مع المكتب (قرار المالك 2026-09-29).
     */
    public function changeRequestBlocker(): ?string
    {
        // **مهلة الطلب كالاستشارة** (`consult_reschedule_notice_minutes`، قرار المالك 2026-10-01) — كان العميل
        // يطلب تغيير اجتماعٍ يبدأ بعد دقائق، والإعداد يقول إنّ ذلك للمكتب مباشرةً.
        $notice = SettingsRegistry::int('consult_reschedule_notice_minutes');
        $noticeEdge = now()->addMinutes($notice);

        return match (true) {
            ! $this->isAwaitingSession() => 'طلب تغيير الموعد متاح للاجتماعات القادمة فقط.',
            $this->reschedule_requested_at !== null => 'طلبك السابق قيد المراجعة — سيتواصل معك المكتب.',
            $this->reachedRescheduleLimit() => 'بلغ الاجتماع الحدّ الأقصى لتغيير الموعد — تواصل مع المكتب مباشرةً.',
            $this->starts_at !== null && $this->starts_at->lt($noticeEdge) => 'موعد الاجتماع خلال أقلّ من '.ArabicCount::duration($notice).' — لتغييره تواصل مع المكتب مباشرةً.',
            default => null,
        };
    }

    /** قادمٌ أو مؤجّل — الحالتان اللتان يُطلب فيهما تغيير الموعد ويُعرض سبب منعه. */
    private function isAwaitingSession(): bool
    {
        return in_array(MeetingStatus::tryFrom((string) $this->status), [MeetingStatus::Upcoming, MeetingStatus::Postponed], true);
    }

    // بطاقة العميل (يطابق DATA.meetings + viewMeetings) — المحضر/الملخص بعد اعتماد الإدارة فقط
    public function toCard(): array
    {
        $approved = $this->isApproved();
        $canJoin = $this->canJoin();
        [$when, $status, $tone] = $this->liveState();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'when' => $this->when_label,
            'up' => $when === 'up',
            // شارة الحالة الحيّة — كان العميل بلا أي شارة فلا يفرّق منتهياً عن ملغى عن لم ينعقد
            'status' => $status,
            'tone' => $tone,
            'canJoin' => $canJoin,
            // طلب تغيير الموعد: متاحٌ أم لا، وسبب المنع حين يعني العميلَ (طلبٌ قائم أو سقفٌ بُلغ) — لا للمنتهي
            'canRequestChange' => $this->changeRequestBlocker() === null,
            'changeRequestNote' => $this->isAwaitingSession() ? $this->changeRequestBlocker() : null,
            // شارة الاعتماد للعميل — يعرف أنّ المحضر/الملخص الظاهرين معتمدان من الإدارة
            'approved' => $approved,
            'ref' => $this->ref ?: 'M-'.$this->id,
            'link' => $canJoin ? $this->joinLink() : '',
            'minutes' => $approved ? $this->minutes : null,
            'summary' => $approved ? $this->summary : null,
            // لا `dur` («60 دقيقة» مختلقة): الاجتماع ينتهي حين يُنهى، والمدّة الفعليّة بعده من Zoom
            'lawyer' => LawyerName::forClient($this->assignedLawyer, $this->assignedLawyer?->name, 'مستشار المكتب'),
            'caseRef' => $this->case_ref,
            'type' => $this->type ?: 'اجتماع مرئي',
            'decisions' => $approved ? ($this->decisions ?? []) : [],
            'startsAt' => $this->starts_at?->toIso8601String(),
        ];
    }

    /**
     * **أيّ أفعال دورة الحياة يقبلها الخادم الآن؟** — حراس الانتقالات أنفسها بمصدر الطاقم، فلا يُعرض
     * زرٌّ يُردّ بـ٤٢٢ (إنهاء «قادم»، إلغاء «جارٍ»). تقرؤه البطاقة وبثّ الحالة معاً.
     *
     * @return array{start: bool, end: bool, cancel: bool}
     */
    public function lifecycleActions(): array
    {
        return [
            'start' => (new StartMeeting)->guard($this, []) === null,
            'end' => (new EndMeeting)->guard($this, []) === null,
            'cancel' => (new CancelMeeting)->guard($this, []) === null,
        ];
    }

    // بطاقة المكتب (تطابق واجهة FullMeeting في lawyer-data/admin-data)
    public function toFullCard(): array
    {
        return [
            'id' => $this->ref ?: 'M-'.$this->id,
            'dbId' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'client' => $this->client_name ?: 'داخلي',
            'lawyer' => $this->assignedLawyer?->name ?: '—',
            'lawyerId' => $this->assigned_lawyer_id,
            'when' => $this->when_label,
            'approve' => $this->approve,
            // الحالة الحيّة المشتقّة (لا المخزّنة) — «قادم» الفائت يظهر «لم ينعقد» فوراً
            'status' => $this->liveState()[1],
            // مفتاحها اللاتينيّ للمنطق (`MeetingStatus::key`) — النصّ للعرض وحده
            'statusKey' => MeetingStatus::keyOf($this->liveState()[1]),
            'approved' => $this->isApproved(),
            // حكم حارس الاعتماد نفسه (`approvalBlocker`) — لا «النصّ غير فارغ» في الواجهة
            'canApprove' => $this->canApprove(),
            // شقّا الحالة الآخران — كي لا تُعاد كتابة قاعدتَي «قادم/ماضٍ» و«نافذة الدخول»
            // في JS: الشاشة تفرز بالأولى وتشرط زرّ الدخول بالثانية، كما تفعل بطاقة العميل.
            'up' => $this->liveState()[0] === 'up',
            'canJoin' => $this->canJoin(),
            // حكم الخادم نفسه (`MeetingController::reschedule`) — كي لا يُعرض زرٌّ يُردّ بـ٤٢٢:
            // اجتماعٌ انعقد وحالته ما زالت «قادم» يُنهى، لا يُعاد جدولته.
            'reschedulable' => ! MeetingStatus::isFinalValue($this->status) && ! $this->wasHeld(),
            // **أزرار دورة الحياة بحراس الانتقالات نفسها** — كانت الواجهة تشتقّها من قوائم نصّية
            // (`['قادم','جارٍ'].includes(status)`) فيُعرض «إنهاء» لقادمٍ و«إلغاء» لجارٍ يرفضهما الخادم.
            'actions' => $this->lifecycleActions(),
            'priority' => $this->priority,
            'conf' => $this->conf,
            // علَمٌ لا نصّ: الواجهة كانت تقارن `conf === 'سري'` لتختار الأيقونة/الشارة
            'confidential' => $this->isConfidential(),
            // المُدخَل يدوياً — يبقى للتعبئة المسبقة في نافذة الإنهاء ولعرضه موسوماً
            // «مُدخَل يدوياً» حين لا سجلّ Zoom. ليس قياساً ولا يُعرض كأنّه قياس.
            'attend' => $this->attend,
            // القياس الحقيقي من سجلّ Zoom — null تعني «لم يُقَس» لا صفراً
            'attendedCount' => $this->attendedCount(),
            'invitedCount' => $this->invitedCount(),
            'presenceRate' => $this->presenceRate(),
            'link' => $this->case_ref ?: ($this->client_name ?: '—'),
            'meetId' => $this->meet_id ?: ($this->ref ?: 'M-'.$this->id),
            'meetLink' => $this->joinLink(),
            // لا `hostLink`: رابط المضيف (`start_url`) لا يغادر الخادم — الدخول من غرفة المنصّة وحدها (قرار المالك 2026-09-29)
            // لا `dur`: الاجتماع بلا مدّةٍ ثابتة (قرار المالك 2026-09-26) — المقيس بعده `durationSec`
            'summary' => $this->summary,
            'zoomSummary' => $this->zoom_summary,
            // هل للاجتماع تسجيلٌ مرئيّ؟ علَمٌ لا رابط — الرابط السحابيّ لا يغادر الخادم
            'recording' => filled($this->recording_url),
            'transcript' => (bool) $this->transcript_path,
            // مخرجات الجلسة للتشغيل والتنزيل عبر مسارات المكتب الداخليّة (نظير `Consult::toCard`)
            'media' => RecordingArchive::availability($this),
            // بيانات جلسة Zoom الفعلية (تُملأ عبر الويبهوك) — للإدارة العليا
            'startsAt' => $this->starts_at?->toIso8601String(),
            'joinTime' => $this->join_time?->format('Y-m-d H:i'),
            'leaveTime' => $this->leave_time?->format('Y-m-d H:i'),
            'durationSec' => $this->duration_sec !== null ? (int) $this->duration_sec : null,
            'zoomSummaryAt' => $this->zoom_summary_at?->format('Y-m-d H:i'),
            'reminderSentAt' => $this->reminder_sent_at?->format('Y-m-d H:i'),
            'createdBy' => $this->created_by,
            'sumApproved' => (bool) $this->sum_approved,
            'minutes' => $this->minutes,
            'participants' => $this->participantsLabel(),
            'caseRef' => $this->case_ref,
            'decisions' => $this->decisions ?? [],
            'tasksCreated' => (bool) $this->tasks_created,
            'zoomUuid' => $this->zoom_uuid,
            'zoomParticipantsLog' => $this->zoom_participants_log ?? [],
            'zoomAiNextSteps' => $this->zoom_ai_next_steps ?? [],
        ];
    }

    /** الملخّص والمحضر — نسخٌ على الاجتماع نفسه (`ContentRevisions`). */
    public function revisionKinds(): array
    {
        return ['meeting_summary' => ['summary'], 'meeting_minutes' => ['minutes']];
    }

    public function revisionOwner(): ?Model
    {
        return $this;
    }
}
