<?php

namespace App\Support;

use App\Domain\Journey\Transitions\LegalCase\DecideCaseExecutionRequest;
use App\Domain\Journey\Transitions\LegalCase\RequestCaseExecution;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * **فتح تنفيذ الحكم بقرار الإدارة العليا** (قرار المالك 2026-09-29) — نظير حوكمة مسار المآل في التذكرة:
 * المحامي المسنَد أو الموظّف يرفع الطلب بسببه، والإدارة تعتمده (فيُفتح ملفّ التنفيذ) أو ترفضه بسبب.
 * والإدارة نفسها تفتحه مباشرةً (`Admin\CaseController::execute`) — هي صاحبة القرار.
 *
 * رسائل الطلب والقرار ملاحظاتٌ داخليّة في محادثة القضيّة (`who = note`) — لا تصل العميل قبل أن يُفتح
 * الملفّ فعلاً، وحينها يُبلَّغ بإشعار الفتح نفسه (`ExecutionCreation::fromCase`).
 */
final class CaseExecutionRequest
{
    public static function request(LegalCase $case, User $actor, string $reason, int $amount, string $defendant): void
    {
        abort_unless(ExecutionCreation::isEligible($case), 422, 'لا يُطلب تنفيذٌ لهذه القضية: يلزم أن يصدر الحكم، وألّا يكون لها طلب تنفيذٍ قائم.');
        $reason = trim($reason);
        $defendant = trim($defendant);

        Workflow::run(new RequestCaseExecution, $case, $actor, ['reason' => $reason, 'amount' => $amount, 'defendant' => $defendant]);

        self::note($case, $actor, "رفع {$actor->name} طلب فتح تنفيذ الحكم للإدارة العليا بمبلغ ".number_format($amount)." ريال على {$defendant} — السبب: ".$reason);
        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send((int) $adminId, 'exec', 't-amber', "طلب فتح تنفيذ الحكم في القضية {$case->number} بانتظار اعتمادك — رفعه {$actor->name}.");
        }
        Audit::log(
            action: 'طلب فتح تنفيذ',
            description: "رفع {$actor->name} طلب فتح تنفيذ الحكم في القضية {$case->number}: {$reason}",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الطلب' => 'مرفوعٌ للإدارة العليا', 'المبلغ المحكوم به' => $amount, 'المنفَّذ ضده' => $defendant, 'السبب' => $reason],
        );
    }

    /** اعتمادٌ يفتح الملفّ — والمحامي المسنَد لقضيّةٍ بلا محامٍ هو من رفع الطلب إن كان محامياً. */
    public static function approve(LegalCase $case, User $admin): Execution
    {
        abort_unless(ExecutionCreation::isEligible($case), 422, 'لا يُفتح تنفيذٌ لهذه القضية: يلزم أن يصدر الحكم، وألّا يكون لها طلب تنفيذٍ قائم.');
        $requester = $case->execution_requested_by !== null ? User::find($case->execution_requested_by) : null;
        $requestReason = (string) $case->execution_request_reason;
        // يُقرأ قبل القرار — القرار يُفرغ حقول الطلب، والمبلغ والمنفَّذ ضده يُنقلان لملفّ التنفيذ صراحةً
        $requestAmount = $case->execution_request_amount;
        $requestDefendant = $case->execution_request_defendant;

        $exec = DB::transaction(function () use ($case, $admin, $requester, $requestReason, $requestAmount, $requestDefendant) {
            Workflow::run(new DecideCaseExecutionRequest(approve: true), $case, $admin, [
                'requested_by' => $requester?->name, 'request_reason' => $requestReason, 'request_amount' => $requestAmount,
                'request_defendant' => $requestDefendant,
            ]);

            return ExecutionCreation::fromCase($case->fresh(), $admin, $requester?->role === Role::Lawyer ? $requester : null, $requestAmount, $requestDefendant);
        });

        self::note($case, $admin, "اعتمدت الإدارة العليا طلب تنفيذ الحكم وفُتح ملفّ التنفيذ {$exec->number}.");
        if ($requester !== null && $requester->id !== $admin->id) {
            Notify::send($requester->id, 'exec', 't-green', "اعتُمد طلبك وفُتح ملفّ تنفيذ الحكم {$exec->number} للقضية {$case->number}.");
        }

        return $exec;
    }

    public static function reject(LegalCase $case, User $admin, string $reason): void
    {
        $reason = trim($reason);
        $requester = $case->execution_requested_by !== null ? User::find($case->execution_requested_by) : null;

        Workflow::run(new DecideCaseExecutionRequest(approve: false), $case, $admin, [
            'reason' => $reason, 'requested_by' => $requester?->name, 'request_reason' => $case->execution_request_reason,
            'request_amount' => $case->execution_request_amount, 'request_defendant' => $case->execution_request_defendant,
        ]);

        self::note($case, $admin, 'رفضت الإدارة العليا طلب فتح تنفيذ الحكم — السبب: '.$reason);
        if ($requester !== null) {
            Notify::send($requester->id, 'exec', 't-red', "رُفض طلب فتح تنفيذ الحكم في القضية {$case->number}: {$reason}");
        }
        Audit::log(
            action: 'رفض طلب تنفيذ',
            description: "رفض {$admin->name} طلب فتح تنفيذ الحكم في القضية {$case->number}: {$reason}",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['القرار' => 'مرفوض', 'السبب' => $reason],
        );
    }

    /**
     * حالة الطلب لبطاقة القضيّة عند الطاقم — `null` بلا طلبٍ قائم.
     *
     * @return array{at: string|null, by: string, reason: string, amount: int|null, defendant: string|null}|null
     */
    public static function pending(LegalCase $case): ?array
    {
        if ($case->execution_requested_at === null) {
            return null;
        }
        // `locale()` بمعاملٍ يضبط لغة النسخة — في سطرٍ مستقلّ (يُنمَّط «نسخة أو نصّ» فلا يُسلسَل)
        $at = Carbon::parse($case->execution_requested_at);
        $at->locale('ar');

        return [
            'at' => $at->diffForHumans(),
            'by' => $case->execution_requested_by !== null ? (string) User::whereKey($case->execution_requested_by)->value('name') : '—',
            'reason' => (string) $case->execution_request_reason,
            'amount' => $case->execution_request_amount,
            'defendant' => $case->execution_request_defendant,
        ];
    }

    private static function note(LegalCase $case, User $actor, string $text): void
    {
        $case->messages()->create([
            'who' => 'note', 'name' => $actor->name, 'role' => 'طلب تنفيذ',
            'body' => '<p>'.e($text).'</p>',
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);
    }
}
