<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Transitions\Consult\ReferConsult;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Jobs\AssignTicketJob;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\ActiveLawyer;
use App\Support\Audit;
use App\Support\ExecService;
use App\Support\LawyerWorkload;
use App\Support\LegalCatalogue;
use App\Support\Live;
use App\Support\TicketAssignment;
use App\Support\TicketJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DistributeController extends Controller
{
    public function index(): Response
    {
        // الحِمل من المصدر الواحد (`LawyerWorkload`) الذي تقرؤه صفحة «المحامون» — كان يُحسب هنا بخمسة
        // استعلاماتٍ لكلّ محامٍ وبقائمة حالاتٍ عربيّة تُسقط الاستشارة التي «لم يحضر» عميلها وهي مفتوحة
        $activeLawyers = User::where('role', Role::Lawyer)->where('status', 'active')->orderBy('name')->get();
        $workload = LawyerWorkload::forMany($activeLawyers->pluck('id')->map(fn ($id) => (int) $id)->all());
        $lawyers = $activeLawyers->map(function (User $u) use ($workload) {
            $load = $workload[(int) $u->id];

            return [
                'id' => $u->id,
                'name' => $u->name,
                'department' => $u->department ?: 'الاستشارات العامة',
                'jobTitle' => $u->job_title ?: 'مستشار قانوني ومحامٍ',
                'initials' => $u->avatar_initials ?: 'مح',
                'distributionMode' => $u->distribution_mode ?: 'auto',
                'activeTicketsCount' => $load['tickets'],
                'activeCasesCount' => $load['cases'],
                'activeExecutionsCount' => $load['executions'],
                'activeConsultsCount' => $load['consults'],
                'totalLoad' => $load['total'],
                'capacityStatus' => $load['capacity'],
            ];
        });

        // 1. التذاكر والطلبات
        $openTickets = Ticket::with(['user', 'assignedLawyer'])
            ->distributable()
            ->latest('id')
            ->get();

        /*
         * **اقتراح النظام لغير المسنَدة وحدها، موسوماً بالتخصّص** (سلسلة المالك 2026-09-25).
         * كان يُحسب لكلّ تذكرة بلا وسم: فالمسنَدة تُعرض قائمتها على المقترَح لا على محاميها،
         * و⚡ «محامي العقارات» لتذكرةٍ عمّاليّة يبدو اختياراً طبيعيّاً. والمسبح يُحمَّل مرّةً للقائمة.
         */
        $suggestions = TicketAssignment::suggestMany($openTickets->whereNull('assigned_lawyer_id'));

        $tickets = $openTickets
            ->map(function (Ticket $t) use ($suggestions) {
                $suggested = $suggestions[$t->id] ?? null;

                return [
                    'id' => $t->id,
                    'no' => $t->number,
                    'client' => $t->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $t->user?->avatar_initials ?: 'عم',
                    'type' => $t->type ?: 'طلب عام',
                    'subject' => $t->subject ?: 'موضوع التذكرة',
                    'dept' => $t->department ?: 'القسم العام',
                    'priority' => $t->priority ?: 'متوسطة',
                    'lawyer' => $t->assigned_lawyer ?: '—',
                    'lawyerId' => $t->assigned_lawyer_id,
                    'status' => $t->status,
                    'tone' => $t->tone,
                    'date' => $t->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $t->created_at?->format('Y-m-d H:i'),
                    'caseRef' => $t->case_ref,
                    'claimAmount' => $t->claim_amount,
                    'courtName' => $t->court_name,
                    'suggestedLawyerId' => $suggested?->lawyer?->id,
                    'suggestedLawyerName' => $suggested?->lawyer?->name,
                    'suggestion' => $suggested?->toArray(),
                    'itemKind' => 'ticket',
                    'itemKindLabel' => 'تذكرة طلب',
                    'badgeTone' => 'b-blue',
                ];
            });

        // 2. القضايا القضائية
        $cases = LegalCase::with(['user', 'assignedLawyer'])
            ->active()
            ->latest('id')
            ->get()
            ->map(function (LegalCase $c) {
                return [
                    'id' => $c->id,
                    'no' => $c->number,
                    'client' => $c->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $c->user?->avatar_initials ?: 'عم',
                    'type' => $c->type ?: 'قضية قضائية',
                    'subject' => ($c->type ?: 'قضية').' — '.($c->department ?: 'المحكمة'),
                    'dept' => $c->department ?: 'المحاكم القضائية',
                    // لا عمود أولويّة للقضيّة — كانت «عالية» ثابتةً لكلّ قضيّة، فتتصدّر الفرزَ وتُلوَّن
                    // حمراء بلا سبب. لا أولويّة = لا شارة (الواجهة تُخفيها)
                    'priority' => null,
                    'lawyer' => $c->assigned_lawyer ?: '—',
                    'lawyerId' => $c->assigned_lawyer_id,
                    'status' => $c->status,
                    'tone' => $c->tone,
                    'date' => $c->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $c->created_at?->format('Y-m-d H:i'),
                    'caseRef' => $c->number,
                    'claimAmount' => $c->fee ? number_format((int) $c->fee).' ر.س' : null,
                    'courtName' => $c->department,
                    'suggestedLawyerId' => null,
                    'suggestedLawyerName' => null,
                    'itemKind' => 'case',
                    'itemKindLabel' => 'قضية قضائية',
                    'badgeTone' => 'b-amber',
                ];
            });

        // 3. ملفات التنفيذ — قسمها قسم «التنفيذ» في الكتالوج (رمزه `enforcement`)
        $execDept = LegalCatalogue::department(LegalCatalogue::ENFORCEMENT_CODE)?->name ?? 'التنفيذ';
        $executions = Execution::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', Execution::CLOSED_STATUSES)
            ->where(function ($q) {
                $q->whereNull('decision')->orWhere('decision', '!=', 'مرفوض');
            })
            ->latest('id')
            ->get()
            ->map(function (Execution $e) use ($execDept) {
                return [
                    'id' => $e->id,
                    'no' => $e->number,
                    'client' => $e->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $e->user?->avatar_initials ?: 'عم',
                    'type' => 'تنفيذ أحكام وسندات',
                    'subject' => $e->subject ?: 'سند تنفيذي',
                    // قسم ملفّ التنفيذ هو قسم «التنفيذ» في الكتالوج — كان اسمَ المحكمة، فامتلأ فلتر الأقسام
                    // بدوائر التنفيذ (سبعة خيارات) بين الأقسام القانونيّة. والمحكمة باقيةٌ في `courtName`
                    'dept' => $execDept,
                    'priority' => null, // لا عمود أولويّة لملفّ التنفيذ — كالقضيّة أعلاه
                    'lawyer' => $e->assigned_lawyer ?: '—',
                    'lawyerId' => $e->assigned_lawyer_id,
                    'status' => $e->status,
                    'tone' => $e->tone,
                    'date' => $e->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $e->created_at?->format('Y-m-d H:i'),
                    'caseRef' => $e->najiz_request_no,
                    'claimAmount' => $e->amount ? number_format((int) $e->amount).' ر.س' : null,
                    'courtName' => $e->court,
                    'suggestedLawyerId' => null,
                    'suggestedLawyerName' => null,
                    'itemKind' => 'execution',
                    'itemKindLabel' => 'ملف تنفيذ',
                    'badgeTone' => 'b-purple',
                ];
            });

        // 4. الاستشارات والجلسات
        $consults = Consult::with(['user', 'assignedLawyer'])
            ->whereNotIn('status', array_merge(Consult::TERMINAL_STATUSES, Consult::PRE_SESSION_STATUSES))
            ->where('session', '!=', 'جلسة جارية')
            ->latest('id')
            ->get()
            ->map(function (Consult $cn) {
                return [
                    'id' => $cn->id,
                    'no' => $cn->ref,
                    'client' => $cn->user?->name ?? 'عميل المنصة',
                    'userAvatar' => $cn->user?->avatar_initials ?: 'عم',
                    'type' => $cn->type ?: 'استشارة نظامية',
                    'subject' => $cn->subject ?: 'جلسة استشارية',
                    'dept' => $cn->specialty ?: 'الاستشارات العامة',
                    'priority' => $cn->priority ?: 'متوسطة',
                    'lawyer' => $cn->lawyer ?: '—',
                    'lawyerId' => $cn->assigned_lawyer_id,
                    'status' => $cn->status,
                    'tone' => 'b-teal',
                    'date' => $cn->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                    'createdAt' => $cn->created_at?->format('Y-m-d H:i'),
                    'caseRef' => null,
                    'claimAmount' => $cn->total ? number_format((int) $cn->total).' ر.س' : null,
                    'courtName' => $cn->channel ?: 'مرئية',
                    'suggestedLawyerId' => null,
                    'suggestedLawyerName' => null,
                    'itemKind' => 'consult',
                    'itemKindLabel' => 'جلسة استشارة',
                    'badgeTone' => 'b-teal',
                ];
            });

        $allDepts = collect([...$tickets, ...$cases, ...$executions, ...$consults])
            ->groupBy('dept')
            ->map(fn ($group, $name) => [
                'name' => $name ?: 'القسم العام',
                'count' => $group->count(),
            ])->values()->all();

        $unassignedTicketsCount = $tickets->where('lawyer', '—')->count();
        $assignedTicketsCount = $tickets->count() - $unassignedTicketsCount;
        $urgentCount = $tickets->filter(fn (array $t) => TicketJourney::isUrgent($t['priority']))->count();

        $unassignedCasesCount = $cases->where('lawyer', '—')->count();
        $unassignedExecsCount = $executions->where('lawyer', '—')->count();
        $unassignedConsultsCount = $consults->where('lawyer', '—')->count();

        $kpis = [
            'total' => $tickets->count(),
            'unassigned' => $unassignedTicketsCount,
            'assigned' => $assignedTicketsCount,
            'urgent' => $urgentCount,
            'activeLawyersCount' => $lawyers->count(),
            'availableLawyersCount' => $lawyers->where('capacityStatus', 'available')->count(),
            // إحصائيات التوزيع الشامل لكافة الأعمال
            'ticketsTotal' => $tickets->count(),
            'ticketsUnassigned' => $unassignedTicketsCount,
            'casesTotal' => $cases->count(),
            'casesUnassigned' => $unassignedCasesCount,
            'executionsTotal' => $executions->count(),
            'executionsUnassigned' => $unassignedExecsCount,
            'consultsTotal' => $consults->count(),
            'consultsUnassigned' => $unassignedConsultsCount,
            'grandTotal' => $tickets->count() + $cases->count() + $executions->count() + $consults->count(),
            'grandUnassigned' => $unassignedTicketsCount + $unassignedCasesCount + $unassignedExecsCount + $unassignedConsultsCount,
        ];

        return Inertia::render('admin/distribute', [
            'tickets' => $tickets,
            'cases' => $cases,
            'executions' => $executions,
            'consults' => $consults,
            'lawyers' => $lawyers,
            'departments' => $allDepts,
            'kpis' => $kpis,
        ]);
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $lawyer = $this->lawyerFrom($request);

        return back()->with('flash', $this->assignTicketTo($ticket, $lawyer, $request->user()));
    }

    public function assignCase(Request $request, LegalCase $case): RedirectResponse
    {
        $lawyer = $this->lawyerFrom($request);

        return back()->with('flash', $this->assignCaseTo($case, $lawyer, $request->user()));
    }

    public function assignExecution(Request $request, Execution $execution): RedirectResponse
    {
        $lawyer = $this->lawyerFrom($request);

        return back()->with('flash', $this->assignExecutionTo($execution, $lawyer, $request->user()));
    }

    public function assignConsult(Request $request, Consult $consult): RedirectResponse
    {
        $lawyer = $this->lawyerFrom($request);

        return back()->with('flash', $this->assignConsultTo($consult, $lawyer, $request->user()));
    }

    /**
     * **الإسناد الجماعيّ — طلبٌ واحد، والمسار نفسه لكلّ عنصر.**
     *
     * كانت الواجهة تطلق `router.post` لكلّ عنصرٍ مختار في حلقةٍ واحدة، وInertia 3 يلغي الزيارة
     * الجارية عند بدء أخرى: فيُنفَّذ بعضها ويُلغى بعضها، والإشعار يقول «تم إسناد N أعمال» على كلّ حال.
     * الآن الخادم يمرّ على العناصر **بدوال الإسناد الفرديّ نفسها** (حرّاسها وملاحظاتها وتدقيقها)، كلُّ عنصرٍ
     * في معاملته — فرفضُ عنصرٍ لا يُسقط غيره — ويُعلن الناتج بصدق: ما أُسند، وما رُفض ولماذا.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.kind' => ['required', 'string', Rule::in(['ticket', 'case', 'execution', 'consult'])],
            'items.*.id' => ['required', 'integer'],
        ]);
        $lawyer = User::findOrFail($data['lawyer_id']);
        $actor = $request->user();

        $done = 0;
        $failed = [];
        foreach ($data['items'] as $item) {
            $entity = match ($item['kind']) {
                'ticket' => Ticket::find($item['id']),
                'case' => LegalCase::find($item['id']),
                'execution' => Execution::find($item['id']),
                'consult' => Consult::find($item['id']),
            };
            if ($entity === null) {
                $failed[] = "#{$item['id']}: لم يُعثر على العنصر.";

                continue;
            }

            try {
                DB::transaction(fn () => match (true) {
                    $entity instanceof Ticket => $this->assignTicketTo($entity, $lawyer, $actor),
                    $entity instanceof LegalCase => $this->assignCaseTo($entity, $lawyer, $actor),
                    $entity instanceof Execution => $this->assignExecutionTo($entity, $lawyer, $actor),
                    $entity instanceof Consult => $this->assignConsultTo($entity, $lawyer, $actor),
                });
                $done++;
            } catch (HttpExceptionInterface $e) {
                $ref = $entity instanceof Consult ? $entity->ref : $entity->number;
                $failed[] = "{$ref}: {$e->getMessage()}";
            }
        }

        $total = count($data['items']);
        $response = back();
        if ($done > 0) {
            $response = $response->with('flash', "أُسند {$done} من {$total} إلى {$lawyer->name}.");
        }

        return $failed === []
            ? $response
            : $response->withErrors(['message' => 'تعذّر إسناد '.count($failed).' من '.$total.' — '.implode(' · ', $failed)]);
    }

    private function lawyerFrom(Request $request): User
    {
        $data = $request->validate([
            'lawyer_id' => ['required', 'integer', new ActiveLawyer],
        ]);

        return User::findOrFail($data['lawyer_id']);
    }

    /** إسناد تذكرة — المصدر الواحد للإسناد الفرديّ والجماعيّ. يرمي 422 برسالةٍ مقروءة إن رُفض. */
    private function assignTicketTo(Ticket $ticket, User $lawyer, User $actor): string
    {
        TicketAssignment::assertReassignable($ticket);

        // الإسناد وقفزة «محالة» من مصدرٍ واحد مع الإسناد الآليّ، والقفزة بالمحرّك باسم الإداريّ
        TicketAssignment::write($ticket, $lawyer->id, $lawyer->name, $actor);
        TicketAssignment::syncRelatedConsults($ticket->fresh());
        Live::push(new TicketStatusBroadcast($ticket));
        TicketAssignment::notifyAssigned($ticket, $lawyer, $actor);

        $ticket->messages()->create([
            'who' => 'note', 'name' => $actor->name, 'role' => 'توزيع',
            'body' => '<p>أسندت الإدارة التذكرة إلى '.e($lawyer->name).'.</p>', 'time_label' => 'الآن',
        ]);

        Audit::log(
            action: 'إسناد تذكرة',
            description: "أسندت الإدارة ({$actor->name}) التذكرة {$ticket->number} إلى {$lawyer->name}.",
            category: 'تذاكر',
            auditable: $ticket,
            auditableRef: $ticket->number,
            afterState: ['المحامي' => $lawyer->name],
        );

        return "تم إسناد التذكرة {$ticket->number} إلى {$lawyer->name}.";
    }

    private function assignCaseTo(LegalCase $case, User $lawyer, User $actor): string
    {
        abort_if(! $case->isActive(), 422, 'القضية مغلقة أو مؤرشفة — لا يُعاد إسنادها.');

        $case->update([
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        $case->messages()->create([
            'who' => 'note',
            'name' => $actor->name,
            'role' => 'توزيع',
            'body' => '<p>أسندت الإدارة القضية إلى '.e($lawyer->name).'.</p>',
            'time_label' => 'الآن',
        ]);

        Audit::log(
            action: 'إسناد قضية',
            description: "أسندت الإدارة ({$actor->name}) القضية {$case->number} إلى {$lawyer->name}.",
            category: 'قضايا',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['المحامي' => $lawyer->name],
        );

        return "تم إسناد القضية {$case->number} إلى {$lawyer->name}.";
    }

    private function assignExecutionTo(Execution $execution, User $lawyer, User $actor): string
    {
        // مسار الإسناد الواحد (`ExecService::assignLawyer`): حرّاسه (المغلق والمرفوض)، وانتقالُ الرحلة بفاعله،
        // ورسالة الملفّ، وإشعار المحامي وبريده — كان هنا تحديثٌ مباشر بلا شيءٍ من ذلك (تدقيق 2026-09-29)
        ExecService::assignLawyer($execution, $lawyer, $actor);

        Audit::log(
            action: 'إسناد ملف تنفيذ',
            description: "أسندت الإدارة ({$actor->name}) ملف التنفيذ {$execution->number} إلى {$lawyer->name}.",
            category: 'تنفيذ',
            auditable: $execution,
            auditableRef: $execution->number,
            afterState: ['المحامي' => $lawyer->name],
        );

        return "تم إسناد ملف التنفيذ {$execution->number} إلى {$lawyer->name}.";
    }

    /**
     * **إسناد الاستشارة من «التوزيع» = إحالتها من شاشات الاستشارات** (2026-09-27).
     *
     * كان يكتب `assigned_lawyer_id` مباشرةً: فلا تنتقل الحالة إلى «محالة للمحامي»، ولا يُحدَّث محامي
     * التذكرة المرتبطة، ولا يُشعَر المحامي، ولا يُسجَّل في رحلة الاستشارة — وبقواعد منعٍ غير قواعد
     * `ReferConsult` (يسمح بتحليلٍ غير معتمد). الآن الانتقال نفسه وحرّاسه، فالإسناد واحدٌ أيّاً كانت الشاشة.
     */
    private function assignConsultTo(Consult $consult, User $lawyer, User $actor): string
    {
        $transition = new ReferConsult;
        abort_if(($why = $transition->guard($consult, [])) !== null, 422, (string) $why);

        Workflow::run($transition, $consult, $actor, [
            'lawyer_id' => $lawyer->id,
            'lawyer' => $lawyer->name,
        ]);

        return "تم إسناد الاستشارة {$consult->ref} إلى {$lawyer->name}.";
    }

    public function auto(Request $request): RedirectResponse
    {
        $actorName = $request->user()->name;
        // المجمَّدة لا تُسنَد (`assignTicketTo` يرفضها) — كانت تُرسل للطابور فتفشل هناك صامتةً
        // ويَعِد الإشعار بتوزيعها. النطاق نفسه الذي تعرضه الشاشة.
        $tickets = Ticket::distributable()
            ->whereNull('assigned_lawyer_id')
            ->get(['id']);

        foreach ($tickets as $ticket) {
            AssignTicketJob::dispatch($ticket->id, $actorName);
        }

        $n = $tickets->count();
        $msg = $n > 0
            ? "جارٍ توزيع {$n} تذكرة تلقائياً في الخلفية — حدّث الصفحة بعد قليل لرؤية الإسناد."
            : 'لا توجد تذاكر غير مُسندة للتوزيع.';

        return back()->with('flash', $msg);
    }
}
