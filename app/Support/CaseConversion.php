<?php

namespace App\Support;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Jobs\ClassifyConvertedCaseJob;
use App\Mail\CaseConvertedMail;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;
use App\Services\MailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * تحويل تذكرة استشارة مكتملة إلى قضية قانونية (يطابق cfConvert).
 * إجراء مشترك بين الموظف والمحامي.
 */
class CaseConversion
{
    /** هل يمكن تحويل هذه التذكرة الآن؟ */
    public static function isEligible(Ticket $ticket): bool
    {
        return in_array($ticket->status, [TicketStatus::ReadyForOutcome->value, TicketStatus::Completed->value], true) && ! $ticket->legalCase()->exists();
    }

    /**
     * الحارس الموحّد لأهليّة التحويل — نفس رسائل المتحكّمَين حرفياً وبنفس ترتيب الفحص
     * (اكتمال ← اعتماد ← ازدواج) حتى لا تتبدّل الرسالة الظاهرة في الحالات المركّبة.
     *
     * الوسيط المسمّى يُبقي **القاعدة الثلاثية** ظاهرة عند موضع النداء: الموظف وحده ينتظر
     * اعتماد المحامي للنتيجة؛ والمحامي هو المعتمِد، والإدارة العليا هي الاعتماد النهائي،
     * فكلاهما يمرّ بلا هذا الشرط. القاعدة مثبّتة باختبارات في CaseConversionTest.
     */
    public static function assertEligible(Ticket $ticket, bool $requireApprovedSummary): void
    {
        if (! in_array($ticket->status, [TicketStatus::ReadyForOutcome->value, TicketStatus::Completed->value], true)) {
            throw ValidationException::withMessages([
                'ticket' => 'لا يمكن تحويل التذكرة لقضية إلا بعد اكتمالها.',
            ]);
        }

        if ($requireApprovedSummary && ! $ticket->summary?->isApproved()) {
            throw ValidationException::withMessages([
                'ticket' => 'لا يمكن تحويل التذكرة لقضية إلا بعد اعتماد النتيجة من المستشار القانوني.',
            ]);
        }

        if ($ticket->legalCase()->exists()) {
            throw ValidationException::withMessages([
                'ticket' => 'تم تحويل هذه التذكرة لقضية مسبقاً.',
            ]);
        }
    }

    /**
     * محامي القضية — سلسلة احتياط ثمّ منع.
     *
     * لماذا: createCase كان ينسخ `$ticket->assigned_lawyer_id` كما هو، وTicketAssignment::assign
     * يُعيد null إن لم يوجد محامٍ نشط في وضع التوزيع التلقائي. ومسار الموظف لا يفحص الإسناد،
     * ومسار المحامي يفحصه بـguardAssigned لكنّه **يعفي الإدارة** — فتُنشأ قضية بـnull تختفي من
     * قائمة كل محامٍ (Lawyer\CaseController يرشّح بـassigned_lawyer_id) ومن عدّادات لوحته:
     * قضيّة يتيمة لا يعمل عليها أحد.
     *
     * الخطوة الثالثة يبلغها **مسار الإدارة وحده** — guardAssigned يرفض المحامي غير المسنَد قبلها.
     */
    private static function resolveLawyer(Ticket $ticket, User $actor): User
    {
        if ($ticket->assignedLawyer) {
            return $ticket->assignedLawyer;
        }

        // مُسنِد المشروع الحتمي (تخصّص ← أقلّ حملاً ← أقدم معرّفاً) — يعمل بلا AI
        if ($picked = TicketAssignment::pickLawyer($ticket)) {
            return $picked;
        }

        if ($actor->role === Role::Lawyer) {
            return $actor; // نظير `$case->assignedLawyer ?? $actor` في ExecutionCreation
        }

        throw ValidationException::withMessages([
            'ticket' => 'لا يمكن تحويل التذكرة لقضية بلا محامٍ مسند — أسند التذكرة لمحامٍ أولاً.',
        ]);
    }

    /**
     * إنشاء القضية مباشرة من التذكرة عند اعتماد مسار القضية (المسار الحوكمي الرسمي المعتمد).
     */
    public static function fromTicket(Ticket $ticket, User $actor, ?string $reason = null): LegalCase
    {
        if ($ticket->legalCase()->exists()) {
            throw ValidationException::withMessages([
                'ticket' => 'تم تحويل هذه التذكرة لقضية مسبقاً.',
            ]);
        }

        $analysis = LegalAiService::fallbackClassification($ticket);
        $lawyer = self::resolveLawyer($ticket, $actor);

        if ((int) $ticket->assigned_lawyer_id !== (int) $lawyer->id) {
            $ticket->forceFill(['assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id])->save();
        }

        try {
            $case = self::createCase($ticket, $analysis, $lawyer, $actor, 'case.open_from_outcome');
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'ticket' => 'تم تحويل هذه التذكرة لقضية مسبقاً.',
            ]);
        }

        ClassifyConvertedCaseJob::dispatch($case)->afterCommit();

        Audit::log(
            action: 'تحويل تذكرة إلى قضية',
            description: "حوّل {$actor->name} التذكرة {$ticket->number} إلى القضية {$case->number} وأُسندت للمحامي {$case->assigned_lawyer}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['التذكرة' => $ticket->number],
            afterState: ['القضية' => $case->number, 'المحامي' => $case->assigned_lawyer],
            user: $actor,
        );

        // بريد للعميل بتحويل التذكرة إلى قضية (أفضل-جهد — لا يعطّل التحويل إن فشل)
        $ticket->loadMissing('user');
        if ($ticket->user?->email) {
            $case->setRelation('user', $ticket->user);
            try {
                app(MailService::class)->send($ticket->user, new CaseConvertedMail($case, $ticket->number));
            } catch (\Throwable) {
                // أفضل جهد
            }
        }

        return $case;
    }

    /**
     * إنشاء القضية ورسالتَي تحليلها وتحويلها — يُنادى داخل المعاملة وتحت القفل.
     *
     * تُفتح القضيّة **داخل المحرّك** (`Workflow::open`): الحالة الأولى سطرُ فتحٍ في سجلّ الانتقالات
     * بالفاعل ومصدره، لا كتابةً مجهولة. و`$openedAs` يميّز المسارين: زرّ التحويل من التذكرة،
     * واعتماد مسار القضيّة من قرار المآل (`ApproveOutcomeTrack`).
     */
    private static function createCase(Ticket $ticket, array $analysis, User $lawyer, User $actor, string $openedAs): LegalCase
    {
        $number = ReferenceNumber::next(LegalCase::class, 'number', 'CASE');

        $case = Workflow::open($openedAs, fn () => LegalCase::create([
            'user_id' => $ticket->user_id,
            'ticket_id' => $ticket->id,
            'number' => $number,
            'type' => $analysis['type'],
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'department' => $analysis['department'],
            'status' => 'بانتظار اعتماد الأتعاب',
            'tone' => CaseJourney::toneFor('بانتظار اعتماد الأتعاب'),
            'update_text' => 'تم تحويل الاستشارة إلى قضية، بانتظار تحديد الإدارة للأتعاب',
            'fee_status' => 'none',
        ]), $actor, ['ticket' => $ticket->number]);

        $case->messages()->create([
            'who' => 'ai',
            'name' => 'المساعد القانوني',
            'role' => 'تحليل',
            // مصدر واحد للنصّ يشاركه ClassifyConvertedCaseJob عند التنقيح
            'body' => ClassifyConvertedCaseJob::analysisBody($ticket, $analysis),
            'time_label' => self::clock(),
        ]);
        $case->messages()->create([
            'who' => 'system',
            'name' => 'النظام',
            'role' => 'تحويل',
            'body' => "<p>تم تحويل طلب الاستشارة (التذكرة {$ticket->number}) إلى قضية قانونية. تتولّى الإدارة تحديد الأتعاب لتفعيل القضية.</p>",
            'time_label' => self::clock(),
        ]);

        return $case;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
