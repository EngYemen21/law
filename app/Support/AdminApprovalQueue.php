<?php

namespace App\Support;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\TicketStatus;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\TicketSummary;
use Illuminate\Database\Eloquent\Builder;

/**
 * **ما ينتظر اعتماد الإدارة العليا — تعريفٌ واحد لكلّ من يعدّه أو يعرضه.**
 *
 * كان «ما ينتظر الإدارة» يُعرَّف في ثلاثة مواضع بثلاثة تعريفات: مركز الاعتمادات يعرض أربع قوائم،
 * ورادار اللوحة يعدّ الملخّصات وحدها (فيقول «ممتاز! لا توجد طلبات بانتظار الاعتماد» ومقترحُ مسارٍ
 * ومحضرُ جلسةٍ وموعدٌ ينتظرون)، وتبويب «بانتظار الاعتماد» في التذاكر ينسى مقترحات المسار. فكلّ شاشةٍ
 * تقول رقماً غير الأخرى عن الشيء نفسه. هنا تُعرَّف القوائم الأربع مرّةً، ويقرؤها الثلاثة.
 */
final class AdminApprovalQueue
{
    /** حالة ملخّص الملفّ حين يعتمده المحامي ويرفعه للإدارة (المرحلة الثانية). */
    public const SUMMARY_AWAITING_ADMIN = 'awaiting_admin';

    /**
     * مقترحات مسار المآل التي لم تُعتمد — والمجمَّدة خارجها (حُسم مسارها فلا اعتماد ثانياً).
     *
     * @return Builder<Ticket>
     */
    public static function trackProposals(): Builder
    {
        return Ticket::query()->where(fn (Builder $q) => self::whereTrackProposal($q));
    }

    /**
     * ملخّصات الملفّات التي اعتمدها المحامي وتنتظر الاعتماد النهائيّ.
     *
     * @return Builder<TicketSummary>
     */
    public static function ticketSummaries(): Builder
    {
        return TicketSummary::query()->where('status', self::SUMMARY_AWAITING_ADMIN)->whereHas('ticket');
    }

    /**
     * محاضر الجلسات التي اعتمدها المحامي ولم تعتمدها الإدارة.
     *
     * @return Builder<Consult>
     */
    public static function sessionSummaries(): Builder
    {
        return Consult::query()->where(fn (Builder $q) => self::whereSessionSummaryPending($q));
    }

    /**
     * مواعيد اقترحها موظّفٌ وتنتظر اعتماد الإدارة قبل نشرها للعميل.
     *
     * @return Builder<Consult>
     */
    public static function appointments(): Builder
    {
        return Consult::query()->where('status', ConsultStatus::AwaitingAppointmentApproval->value);
    }

    /**
     * العدّادات الأربعة ومجموعها — الأرقام نفسها التي تعرضها قوائم مركز الاعتمادات.
     *
     * @return array{proposals: int, summaries: int, sessions: int, appointments: int, totalPending: int}
     */
    public static function counts(): array
    {
        $counts = [
            'proposals' => self::trackProposals()->count(),
            'summaries' => self::ticketSummaries()->count(),
            'sessions' => self::sessionSummaries()->count(),
            'appointments' => self::appointments()->count(),
        ];

        return $counts + ['totalPending' => array_sum($counts)];
    }

    /**
     * **تذاكر «بانتظار الاعتماد»** (تبويب التذاكر وعدّاده): تذكرةٌ فيها ما ينتظر الإدارة —
     * ملخّص ملفّ، أو محضر جلسة، أو مقترح مسار. الحالتان الانتظاريّتان من الكتالوج تُقرأ معها احتياطاً:
     * تذكرةٌ حالتها تقول «تنتظر الإدارة» لا تغيب عن التبويب لأنّ صفّها التابع لم يُكتب بعد.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function ticketsAwaitingAdmin(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('status', [
                TicketStatus::AwaitingAdminSummaryApproval->value,
                TicketStatus::AwaitingAdminOutcomeApproval->value,
            ])
                ->orWhereHas('summary', fn (Builder $sq) => $sq->where('status', self::SUMMARY_AWAITING_ADMIN))
                ->orWhereHas('consults', fn (Builder $cq) => self::whereSessionSummaryPending($cq))
                ->orWhere(fn (Builder $tq) => self::whereTrackProposal($tq));
        });
    }

    private static function whereTrackProposal(Builder $q): void
    {
        $q->whereNotNull('proposed_track')->whereNull('approved_track')->where('is_frozen', false);
    }

    private static function whereSessionSummaryPending(Builder $q): void
    {
        $q->whereNotNull('summary_lawyer_approved_at')->whereNull('summary_approved_at');
    }
}
