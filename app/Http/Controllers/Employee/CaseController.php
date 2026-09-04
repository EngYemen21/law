<?php

namespace App\Http\Controllers\Employee;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeCaseDocumentJob;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * دور الموظف (خدمة العملاء) مع القضايا — متابعة وتنسيق والتواصل مع العميل.
 */
class CaseController extends Controller
{
    public function index(): Response
    {
        $allCases = LegalCase::with(['user', 'assignedLawyer', 'hearings'])
            ->latest('id')->get();

        $cases = $allCases->map(function (LegalCase $c) {
            $nextHearing = $c->nextHearingLive();

            return [
                'no' => $c->number,
                'client' => Ticket::maskClient($c->user?->name ?? ''),
                'clientId' => $c->user_id,
                'type' => $c->type,
                'dept' => $c->department,
                'court' => $c->court ?? '—',
                'lawyer' => $c->assigned_lawyer ?: ($c->assignedLawyer?->name ?? '—'),
                'lawyerId' => $c->assigned_lawyer_id,
                'status' => $c->status,
                'tone' => $c->tone,
                'next' => $c->nextHearingLabel(),
                'hasNextHearing' => (bool) $nextHearing,
                'updatedAgo' => $c->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
            ];
        });

        $counts = [
            'total' => $allCases->count(),
            'active' => $allCases->whereNotIn('status', ['مغلقة', 'مؤرشفة'])->count(),
            'withHearings' => $allCases->filter(fn (LegalCase $c) => $c->nextHearingLive() !== null)->count(),
            'preparing' => $allCases->where('status', 'قيد التحضير')->count(),
            'inCourt' => $allCases->where('status', 'منظورة')->count(),
            'ruled' => $allCases->where('status', 'صدر الحكم')->count(),
            'closed' => $allCases->whereIn('status', ['مغلقة', 'مؤرشفة'])->count(),
        ];

        $departments = $allCases->pluck('department')->filter()->unique()->values();
        $types = $allCases->pluck('type')->filter()->unique()->values();

        $lawyers = User::where('role', Role::Lawyer)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return Inertia::render('employee/cases', [
            'cases' => $cases,
            'counts' => $counts,
            'departments' => $departments,
            'types' => $types,
            'lawyers' => $lawyers,
        ]);
    }

    public function show(LegalCase $case): Response
    {
        $case->load(['user', 'hearings', 'documents', 'assignedLawyer']);

        $client = $case->user;
        $clientStats = $client ? [
            'totalTickets' => Ticket::where('user_id', $client->id)->count(),
            'activeTickets' => Ticket::where('user_id', $client->id)->whereNotIn('status', ['مكتملة', 'مغلقة'])->count(),
            'totalCases' => LegalCase::where('user_id', $client->id)->count(),
            'memberSince' => $client->created_at?->locale('ar')->translatedFormat('F Y') ?? '—',
        ] : null;

        $documents = $case->documents->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'by' => $d->uploaded_by === 'staff' ? 'المكتب' : ($d->uploaded_by === 'lawyer' ? 'المستشار' : 'العميل'),
            'status' => $d->status,
            'docType' => $d->doc_type,
            'summary' => $d->summary,
            'date' => $d->created_at?->locale('ar')->translatedFormat('j M Y') ?? '—',
            // بقرار المنتج: الموظف يرى أنّ المستند رُفع ويقرأ ملخّصه، ولا يفتحه ولا ينزّله.
            // صريحٌ لا مصادفةً — كي لا يُضاف الرابط سهواً عند أي توحيد لاحق للشكل.
            'downloadUrl' => null,
        ]);

        return Inertia::render('employee/case', [
            'case' => [
                'no' => $case->number,
                'client' => Ticket::maskClient($case->user?->name ?? ''),
                'type' => $case->type,
                'dept' => $case->department,
                'court' => $case->court ?? '—',
                'lawyer' => $case->assigned_lawyer ?: ($case->assignedLawyer?->name ?? '—'),
                'status' => $case->status,
                'tone' => $case->tone,
                'next' => $case->nextHearingLabel(),
            ],
            'clientStats' => $clientStats,
            'channel' => 'case.'.$case->id,
            // المكتب يرى المحجوب ليراجعه — وهو الفاصل الذي لم يكن موجوداً
            'messages' => $case->messages()->visibleTo(true)->get()->map->toMessage(),
            'hearings' => $case->hearings->map->toData(),
            'documents' => $documents,
        ]);
    }

    // إرفاق مستند من خدمة العملاء لملفّ القضية — نظير إرفاق المحامي والعميل.
    // كانت صفحة قضية الموظف بلا مستندات ولا إرفاق (عدم تماثل مع بقية الأدوار).
    public function attach(Request $request, LegalCase $case): RedirectResponse
    {
        abort_if($case->status === 'مؤرشفة', 422, 'لا يمكن إرفاق مستندات على قضية مؤرشفة.');

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx']]); // حتى 10MB

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $doc = $case->documents()->create([
            'name' => $name,
            'path' => $file->store("case-docs/{$case->id}"),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => 'staff',
            'status' => 'قيد الفحص',
        ]);

        $case->messages()->create([
            'who' => 'staff', 'name' => $request->user()->name, 'role' => 'مستند',
            'body' => '<p>أرفق المكتب مستنداً بملف القضية:</p><div class="doc-list"><span class="doc-chip">📎 '.e($name).'</span></div>',
            'time_label' => $this->clock(),
        ]);
        $case->update(['update_text' => 'أرفق المكتب مستنداً: '.$name]);
        Notify::send($case->user_id, 'upload', 't-cyan', "أُرفق مستند جديد بملف قضيتك {$case->number}.");

        AnalyzeCaseDocumentJob::dispatch($case, $doc);

        return back();
    }

    // ردّ خدمة العملاء للعميل داخل القضية (بثّ لحظي)
    public function reply(Request $request, LegalCase $case): \Illuminate\Http\Response
    {
        $data = $request->validate(['body' => ['required', 'string']]);

        $case->messages()->create([
            'who' => 'staff', 'name' => $request->user()->name, 'role' => 'خدمة العملاء',
            'body' => nl2br(e($data['body'])), 'time_label' => $this->clock(),
        ]);

        return response()->noContent();
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
