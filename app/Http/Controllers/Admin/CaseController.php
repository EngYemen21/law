<?php

namespace App\Http\Controllers\Admin;

use App\Events\CaseStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Mail\CaseFeeSetMail;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\MailService;
use App\Support\CaseJourney;
use App\Support\ExecutionCreation;
use App\Support\InvoiceNumber;
use App\Support\Live;
use App\Support\Notify;
use App\Support\Paginate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الإدارة العليا — الإشراف والمتابعة على كافة القضايا القضائية وتحديد أتعابها وإغلاقها.
 */
class CaseController extends Controller
{
    public function fees(): Response
    {
        $cases = LegalCase::with(['user', 'hearings'])->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('admin/casefees', [
            'cases' => Paginate::shape($cases, fn (LegalCase $c) => [
                'no' => $c->number,
                'type' => $c->type,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'lawyer' => $c->assigned_lawyer ?: '—',
                'status' => $c->status,
                'tone' => $c->tone,
                'fee' => $c->fee,
                'lawyerFee' => $c->lawyer_fee,
                'lawyerPct' => $c->lawyer_pct,
                'feeStatus' => $c->fee_status,
            ]),
        ]);
    }

    // تحديد قيمة الأتعاب → القضية بانتظار سداد العميل
    public function setFee(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'بانتظار اعتماد الأتعاب', 422);

        $data = $request->validate([
            'fee' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lawyer_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $vat = Setting::vatOn($data['fee']);
        $total = $data['fee'] + $vat;
        $pct = $data['lawyer_pct'] ?? 0;
        $lawyerFee = (int) round($data['fee'] * $pct / 100);

        $case->update([
            'fee' => $data['fee'],
            'lawyer_fee' => $lawyerFee,
            'lawyer_pct' => $pct,
            'fee_status' => 'pending_payment',
            'status' => 'بانتظار سداد الأتعاب',
            'tone' => CaseJourney::toneFor('بانتظار سداد الأتعاب'),
            'invoice_text' => "أتعاب القضية {$data['fee']} ر.س + ضريبة {$vat} = {$total} ر.س",
            'update_text' => 'حدّدت الإدارة الأتعاب، بانتظار سداد العميل لتفعيل القضية',
        ]);

        // فاتورة أتعاب حقيقية للعميل (يطابق cfInvoice)
        Invoice::create([
            'user_id' => $case->user_id,
            'case_id' => $case->id,
            'number' => InvoiceNumber::next(),
            'description' => "أتعاب قضية {$case->number} — {$case->type}",
            'amount' => $total,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'خلال 14 يوماً',
            'due_at' => now()->addDays(14)->toDateString(),
            'paid' => false,
        ]);

        $case->messages()->create([
            'who' => 'admin',
            'name' => 'الإدارة العليا',
            'role' => 'أتعاب',
            'body' => '<p>تم تحديد واعتماد أتعاب القضية وإصدار الفاتورة للعميل، وتُفعّل القضية فور إتمام السداد.</p>',
            'time_label' => $this->clock(),
        ]);

        Notify::send($case->user_id, 'card', 't-amber', "صدرت فاتورة أتعاب قضيتك {$case->number} بمبلغ {$total} ر.س. سدّدها لتفعيل القضية.");

        // بريد للعميل بتحديد الأتعاب وإصدار الفاتورة (أفضل-جهد — لا يعطّل الطلب إن فشل)
        $case->loadMissing('user');
        if ($case->user?->email) {
            app(MailService::class)->send($case->user, new CaseFeeSetMail($case, $total));
        }

        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // إشراف الإدارة على كل القضايا
    public function index(): Response
    {
        $rawCases = LegalCase::with(['user', 'hearings', 'ticket', 'assignedLawyer'])
            ->latest('id')
            ->get();

        $cases = $rawCases->map(function (LegalCase $c) {
            $nextHearing = $c->nextHearingLive();

            return [
                'id' => $c->id,
                'no' => $c->number,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'realClientName' => $c->user?->name ?? 'عميل المنصة',
                'type' => $c->type ?: 'قضية عامة',
                'dept' => $c->department ?: ($c->ticket?->department ?: 'القسم العام'),
                'lawyer' => $c->assigned_lawyer ?: ($c->assignedLawyer?->name ?: '—'),
                'lawyerId' => $c->assigned_lawyer_id,
                'status' => $c->status,
                'tone' => $c->tone ?: 'b-blue',
                'courtName' => $c->ticket?->court_name ?: 'المحكمة المختصة',
                'claimAmount' => $c->ticket?->claim_amount,
                'opponent' => $c->ticket?->opponent_name,
                'fee' => $c->fee,
                'feeStatus' => $c->fee_status,
                'updateText' => $c->update_text,
                'ruling' => $c->ruling,
                'hearingsCount' => $c->hearings->count(),
                'nextHearingDate' => $nextHearing ? ($nextHearing->starts_at?->format('Y-m-d H:i') ?? $nextHearing->session_date) : null,
                'nextHearingNotes' => $nextHearing?->notes,
                'date' => $c->created_at?->format('Y-m-d'),
                'canClose' => $c->status === 'صدر الحكم',
                'canArchive' => $c->status === 'مغلقة',
                'canExecute' => ExecutionCreation::isEligible($c),
            ];
        });

        // تصنيفات أنواع القضايا
        $types = $cases->groupBy('type')->map(fn ($group, $name) => [
            'name' => $name ?: 'عامة',
            'count' => $group->count(),
        ])->values()->all();

        // مؤشرات أداء القضايا
        $kpis = [
            'total' => $cases->count(),
            'active' => $cases->whereIn('status', ['قيد الترافع', 'قيد النظر', 'جلسات جارية', 'مرافعة'])->count(),
            'judged' => $cases->where('status', 'صدر الحكم')->count(),
            'closed' => $cases->whereIn('status', ['مغلقة', 'مؤرشفة'])->count(),
            'pendingFee' => $cases->whereIn('status', ['بانتظار سداد الأتعاب', 'بانتظار اعتماد الأتعاب'])->count(),
        ];

        return Inertia::render('admin/cases', [
            'cases' => $cases,
            'types' => $types,
            'kpis' => $kpis,
        ]);
    }

    // الإغلاق بعد الحكم (يطابق cfCloseCase) — الأرشفة النهائية خطوة إدارية مستقلة لاحقة
    public function closeCase(LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'صدر الحكم', 422);

        $case->update([
            'status' => 'مغلقة',
            'tone' => CaseJourney::toneFor('مغلقة'),
            'update_text' => 'أُغلقت القضية بعد اكتمال إجراءات الحكم',
        ]);
        $case->messages()->create([
            'who' => 'admin', 'name' => 'الإدارة', 'role' => 'إغلاق',
            'body' => '<p>بعد صدور الحكم وتنفيذه، تحوّلت القضية إلى <b>مغلقة</b>. وستُؤرشف نهائياً بعد استيفاء كامل الإجراءات.</p>',
            'time_label' => $this->clock(),
        ]);
        Notify::send($case->user_id, 'check', 't-green', "أُغلقت قضيتك {$case->number} بعد اكتمال الإجراءات.");
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // الأرشفة النهائية — تُحفظ القضية المغلقة في الأرشيف القانوني (خطوة إدارية صريحة بعد الإغلاق)
    public function archiveCase(LegalCase $case): RedirectResponse
    {
        abort_unless($case->status === 'مغلقة', 422);

        $case->update([
            'status' => 'مؤرشفة',
            'tone' => CaseJourney::toneFor('مؤرشفة'),
            'update_text' => 'أُودعت القضية في الأرشيف القانوني بعد استيفاء كافة المتطلبات',
        ]);
        $case->messages()->create([
            'who' => 'admin', 'name' => 'الإدارة', 'role' => 'أرشفة',
            'body' => '<p>تمت أرشفة ملف القضية نهائياً في سجلات الأرشيف القانوني الموثقة.</p>',
            'time_label' => $this->clock(),
        ]);
        Notify::send($case->user_id, 'check', 't-green', "أُرشفت قضيتك {$case->number} وحُفظ ملفها في الأرشيف.");
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // التحويل للتنفيذ (يطابق cfExecute)
    // كان معطوباً مزدوجاً: المسار يشير لاسم دالّة غير موجود (convertToExecution) فلا يصل هنا
    // أصلاً، والجسم ينادي createFor غير المعرَّفة — الصحيح fromCase (نفس نظيرة المحامي)
    public function execute(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless(ExecutionCreation::isEligible($case), 422, 'التحويل للتنفيذ متاح للقضايا الصادر حكمها ولم يُفتح لها تنفيذ بعد.');

        $exec = ExecutionCreation::fromCase($case, $request->user());

        // وجهة الملف المفتوح لا الصفحة السابقة — نظير مسار المحامي (lawyer.execs)، والعقد موثّق باختبار
        return redirect()->route('admin.execs')->with('flash', "فُتح طلب التنفيذ {$exec->number} للقضية {$case->number}.");
    }

    private function clock(): string
    {
        return now()->locale('ar')->translatedFormat('h:i A');
    }
}
