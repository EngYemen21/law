<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use App\Rules\LawyerInBranch;
use App\Services\LegalAiService;
use App\Support\DecisionTasks;
use App\Support\Live;
use App\Support\Specialties;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * استقبال الاستشارات للموظف/المحامي/الإدارة (يطابق consultRecvView + crStart/crEnd)
 * — متحكم واحد مشترك؛ يُصيَّر لصفحة الدور الحالي.
 */
class ConsultController extends Controller
{
    use ScopedToLawyer;

    public function __construct(private LegalAiService $ai) {}

    // إدارة الاستشارات (يطابق emConsultsView/adConsultsView) — قائمة الرحلة والمؤشرات
    public function index(Request $request): Response
    {
        $consults = $this->scopeForRole($request, Consult::with('user'))
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toCard());

        return Inertia::render($this->prefix($request).'/consults', [
            'consults' => $consults,
        ]);
    }

    // رحلة الاستشارة (يطابق consultView) — التفاصيل والإجراءات وسجل التدقيق
    public function show(Request $request): Response
    {
        $consult = Consult::with('user')
            ->where('ref', (string) $request->query('ref'))
            ->firstOrFail();
        $this->guardConsult($request, $consult);

        return Inertia::render($this->prefix($request).'/consult', [
            'consult' => $consult->toCard(),
            'lawyers' => $this->lawyerOptions($consult),
        ]);
    }

    /** محامون نشطون حقيقيون للإحالة/التعيين، يتصدّرهم متخصّصو قسم الاستشارة. */
    private function lawyerOptions(Consult $consult): array
    {
        $specialty = $consult->specialty ?: $consult->type;

        return User::where('role', Role::Lawyer)->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'department'])
            ->sortByDesc(fn ($u) => Specialties::matches($u->department, $specialty) ? 1 : 0)
            ->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department ?: '—'])
            ->all();
    }

    // استلام الاستشارة (يطابق cTake)
    public function take(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        if ($consult->status === 'جديدة') {
            $consult->employee = $request->user()->name;
            $consult->logAudit($request->user()->name, 'الحالة', $consult->status, 'قيد مراجعة الموظف');
            $consult->status = 'قيد مراجعة الموظف';
            $consult->save();
            Live::push(new ConsultStatusBroadcast($consult));
        }

        return back();
    }

    // طلب استكمال مستندات (يطابق cRequestDocs) — يُشعر العميل
    public function requestDocs(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        $data = $request->validate(['docs' => ['nullable', 'string', 'max:300']]);

        $missing = $consult->missing ?? [];
        $missing[] = trim($data['docs'] ?? '') ?: 'مستند إضافي مطلوب';
        $consult->missing = array_values(array_unique($missing));
        $consult->logAudit($request->user()->name, 'الحالة', $consult->status, 'بانتظار استكمال البيانات');
        $consult->status = 'بانتظار استكمال البيانات';
        $consult->save();
        Live::push(new ConsultStatusBroadcast($consult));

        UserNotification::create([
            'user_id' => $consult->user_id,
            'icon' => 'upload',
            'tone' => 't-amber',
            'body' => "نحتاج استكمال مستندات لاستشارتك ({$consult->ref}): ".implode('، ', $consult->missing).'.',
            'time_label' => 'الآن',
            'is_read' => false,
        ]);

        return back();
    }

    // بدء معالجة الفريق القانوني (يطابق cRunAI/cRerun) — تحليل ذكي
    public function analyze(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        $before = $consult->status;
        $ai = $this->ai->analyzeConsult($consult);

        $consult->ai_class = $ai['class'];
        $consult->ai_summary = $ai['summary'];
        $consult->ai_lawyer = $ai['lawyer'];
        $consult->ai_done = true;
        $consult->missing = $ai['missing'];
        $consult->logAudit($request->user()->name, 'الحالة', $before, 'قيد معالجة الفريق القانوني');
        $consult->logAudit('النظام', 'تحليل الفريق القانوني', '—', 'اكتمل');
        $consult->logAudit('النظام', 'الحالة', 'قيد معالجة الفريق القانوني', 'بانتظار اعتماد الموظف');
        $consult->status = 'بانتظار اعتماد الموظف';
        $consult->save();
        Live::push(new ConsultStatusBroadcast($consult));

        return back();
    }

    // حفظ تعديلات التحليل (يطابق cSaveAI) — تُوثّق الفروقات في سجل التدقيق
    public function saveAnalysis(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        $data = $request->validate([
            'aiClass' => ['required', 'string', 'max:120'],
            'aiSummary' => ['required', 'string', 'max:6000'],
            'aiLawyer' => ['required', 'string', 'max:80'],
        ]);

        $user = $request->user()->name;
        if ($data['aiClass'] !== $consult->ai_class) {
            $consult->logAudit($user, 'التصنيف', (string) $consult->ai_class, $data['aiClass']);
        }
        if ($data['aiSummary'] !== $consult->ai_summary) {
            $consult->logAudit($user, 'الملخص', '(نص سابق)', '(نص محدّث)');
        }
        if ($data['aiLawyer'] !== $consult->ai_lawyer) {
            $consult->logAudit($user, 'المحامي المقترح', (string) $consult->ai_lawyer, $data['aiLawyer']);
        }
        $consult->update(['ai_class' => $data['aiClass'], 'ai_summary' => $data['aiSummary'], 'ai_lawyer' => $data['aiLawyer']]);

        return back();
    }

    // اعتماد التحليل (يطابق cApproveAI) → جاهزة للمحامي
    public function approveAnalysis(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        if ($consult->status === 'بانتظار اعتماد الموظف') {
            $consult->logAudit($request->user()->name, 'اعتماد التحليل', $consult->status, 'جاهزة للمحامي');
            $consult->status = 'جاهزة للمحامي';
            $consult->save();
            Live::push(new ConsultStatusBroadcast($consult));
        }

        return back();
    }

    // الإحالة/تعيين المحامي (يطابق cRefer + adAssign) — يُشعر العميل
    public function refer(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        $data = $request->validate([
            // مشترك بين الموظف/المحامي/الإدارة (كل بدوره عبر route مستقل) — Rule موحَّد يرفض غير المحامين والموقوفين.
            'lawyer_id' => ['nullable', 'integer', new LawyerInBranch],
            'lawyer' => ['nullable', 'string', 'max:80'],
        ]);

        // حلّ المحامي المختص إلى مستخدم حقيقي: بالمعرّف إن مُرّر، وإلا بمطابقة الاسم المقترح
        $name = trim($data['lawyer'] ?? '') ?: ($consult->ai_lawyer ?: $consult->lawyer);
        $lawyerUser = ! empty($data['lawyer_id'])
            ? User::where('role', Role::Lawyer)->find((int) $data['lawyer_id'])
            : User::where('role', Role::Lawyer)->where('name', $name)->first();
        if (! $lawyerUser && $name !== '') {
            $lawyerUser = User::where('role', Role::Lawyer)->where('name', 'like', '%'.$name.'%')->first();
        }
        $lawyer = $lawyerUser?->name ?: $name;

        $consult->logAudit($request->user()->name, 'الحالة', $consult->status, 'محالة للمحامي');
        if ($lawyer !== $consult->lawyer) {
            $consult->logAudit($request->user()->name, 'المحامي', $consult->lawyer, $lawyer);
        }
        $consult->lawyer = $lawyer;
        // ربط المحامي بالمعرّف والفرع (مصدر عزل الرؤية والبثّ)
        if ($lawyerUser) {
            $consult->assigned_lawyer_id = $lawyerUser->id;
            $consult->branch = $lawyerUser->branch ?: $consult->branch;
        }
        $consult->status = 'محالة للمحامي';
        $consult->save();
        Live::push(new ConsultStatusBroadcast($consult));

        UserNotification::create([
            'user_id' => $consult->user_id,
            'icon' => 'scale',
            'tone' => 't-green',
            'body' => "أُحيلت استشارتك ({$consult->ref}) إلى المستشار المختص وستُعقد الجلسة في موعدها.",
            'time_label' => 'الآن',
            'is_read' => false,
        ]);

        return back();
    }

    // تحديث الأولوية (الإدارة العليا — يطابق adConsult priority)
    public function priority(Request $request, Consult $consult): RedirectResponse
    {
        $data = $request->validate(['priority' => ['required', 'string', 'in:عالية,متوسطة,عادية']]);

        if ($data['priority'] !== $consult->priority) {
            $consult->logAudit($request->user()->name, 'الأولوية', $consult->priority, $data['priority']);
            $consult->priority = $data['priority'];
            $consult->save();
        }

        return back();
    }

    // استقبال الاستشارات — الجلسات حسب القناة حتى كتابة الملخص
    public function recv(Request $request): Response
    {
        $consults = $this->scopeForRole($request, Consult::with('user'))
            ->latest('id')->get()
            ->map(fn (Consult $c) => $c->toCard());

        return Inertia::render($this->prefix($request).'/consultrecv', [
            'consults' => $consults,
        ]);
    }

    // بدء الجلسة (يطابق crStart) — مرئية/هاتفية/حضورية
    public function start(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        if ($consult->session === 'بانتظار الجلسة') {
            $consult->update(['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة']);
            Live::push(new ConsultStatusBroadcast($consult));

            $verb = match ($consult->channel) {
                'مرئية' => 'بدأت جلسة استشارتك المرئية — يمكنك الانضمام الآن من صفحة «استشاراتي»',
                'هاتفية' => 'بدأت مكالمة استشارتك الهاتفية',
                default => 'بدأت جلسة استشارتك الحضورية',
            };
            UserNotification::create([
                'user_id' => $consult->user_id,
                'icon' => $consult->channel === 'مرئية' ? 'video' : ($consult->channel === 'هاتفية' ? 'phone' : 'office'),
                'tone' => 't-blue',
                'body' => "{$verb} ({$consult->ref}).",
                'time_label' => 'الآن',
                'is_read' => false,
            ]);
        }

        return back();
    }

    // إنهاء الجلسة وتوليد الملخص (يطابق vrEnd/crEnd + cRunAIFromSession)
    public function end(Request $request, Consult $consult): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
            'duration' => ['nullable', 'string', 'max:20'],
        ]);

        $this->guardConsult($request, $consult);
        if ($consult->session !== 'منتهية') {
            $notes = trim($data['notes'] ?? '');
            $summary = $this->ai->consultSummary($consult, $notes);
            $consult->update([
                'session' => 'منتهية',
                'status' => 'منتهية',
                'session_notes' => $notes !== '' ? $notes : $consult->session_notes,
                'duration_label' => $data['duration'] ?? $consult->duration_label,
                'summary' => $summary,
                'decisions' => $this->ai->extractDecisions($summary),
            ]);
            Live::push(new ConsultStatusBroadcast($consult));

            UserNotification::create([
                'user_id' => $consult->user_id,
                'icon' => 'doc',
                'tone' => 't-green',
                'body' => "انتهت جلسة استشارتك ({$consult->ref}) — ملخص الاستشارة متاح الآن في «استشاراتي».",
                'time_label' => 'الآن',
                'is_read' => false,
            ]);
        }

        return back();
    }

    // تحويل قرارات الاستشارة إلى مهام حقيقية (موديل Task) — لمرة واحدة
    public function createTasks(Request $request, Consult $consult): RedirectResponse
    {
        $this->guardConsult($request, $consult);
        abort_if($consult->tasks_created, 409);

        $count = DecisionTasks::create($consult, $this->ai, $request->user());
        abort_if($count === 0, 422);

        return back()->with('flash', 'تم تحويل '.$count.' قرار إلى مهام');
    }

    // غرفة الجلسة المرئية (يطابق openVideoRoom) — ?ref=CN-… للاستشارة، وتبقى kind=req لطلبات الاجتماعات
    public function room(Request $request): Response
    {
        $card = null;
        if ($ref = $request->query('ref')) {
            $consult = Consult::with('user')->where('ref', $ref)->first();
            if ($consult) {
                $this->guardConsult($request, $consult);
                $card = $consult->toCard();
            }
        }

        return Inertia::render($this->prefix($request).'/videoroom', [
            'consult' => $card,
            'selfName' => $request->user()->name,
            'selfAv' => $request->user()->avatar_initials ?? '',
        ]);
    }

    private function prefix(Request $request): string
    {
        return match ($request->user()->role) {
            Role::Employee => 'employee',
            Role::Lawyer => 'lawyer',
            default => 'admin',
        };
    }

    /**
     * عزل قائمة الاستشارات بحسب الدور:
     * - المحامي: استشاراته المسندة فقط (assigned_lawyer_id) — يسدّ رؤية استشارات غيره.
     * - الموظف: فرعه + الاستشارات بلا فرع (مجمّع الاستقبال المشترك قبل الإسناد).
     * - الإدارة: الكل.
     *
     * @param  Builder<Consult>  $query
     * @return Builder<Consult>
     */
    private function scopeForRole(Request $request, $query)
    {
        $user = $request->user();
        if ($user->role === Role::Lawyer) {
            $query->where('assigned_lawyer_id', $user->id);
        } elseif ($user->role === Role::Employee) {
            $branch = $user->branch;
            $query->where(fn ($q) => $q->where('branch', $branch)->orWhereNull('branch'));
        }

        return $query;
    }

    /**
     * حارس الوصول المباشر لسجل استشارة (يسدّ IDOR):
     * - المحامي: يُمنع (403) إن لم تكن الاستشارة مُسندة إليه (guardAssigned، الإدارة مستثناة).
     * - الموظف: يُمنع إن حملت فرعاً مختلفاً عن فرعه (بلا فرع = مجمّع مشترك).
     */
    private function guardConsult(Request $request, Consult $consult): void
    {
        $user = $request->user();
        if ($user->role === Role::Lawyer) {
            $this->guardAssigned($consult);
        } elseif ($user->role === Role::Employee) {
            abort_if($consult->branch !== null && $consult->branch !== $user->branch, 403);
        }
    }
}
