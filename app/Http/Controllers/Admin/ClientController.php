<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CaseDocument;
use App\Models\Consult;
use App\Models\Document;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Support\Paginate;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة العملاء — عرض قائمة العملاء، استعراض الملف الشامل لكل عميل، وتعديل بياناته.
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $activity = trim((string) $request->query('activity', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $sort = trim((string) $request->query('sort', 'latest'));

        $query = User::where('role', Role::Client)
            ->withCount(['tickets', 'cases', 'consults', 'executions']);

        // 1. البحث النصي
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 2. الفلترة بحسب الحالة (نشط / موقوف)
        if ($status === 'active' || $status === 'نشط') {
            $query->where('status', 'active');
        } elseif ($status === 'suspended' || $status === 'موقوف') {
            $query->where('status', 'suspended');
        }

        // 3. الفلترة بحسب نوع النشاط
        if ($activity === 'has_cases') {
            $query->has('cases');
        } elseif ($activity === 'has_tickets') {
            $query->has('tickets');
        } elseif ($activity === 'has_consults') {
            $query->has('consults');
        } elseif ($activity === 'has_executions') {
            $query->has('executions');
        } elseif ($activity === 'inactive') {
            $query->doesntHave('cases')->doesntHave('tickets')->doesntHave('consults')->doesntHave('executions');
        } elseif ($activity === 'active_any') {
            $query->where(function ($q) {
                $q->has('cases')->orHas('tickets')->orHas('consults')->orHas('executions');
            });
        }

        // 4. الفلترة بحسب تاريخ التسجيل
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // 5. الترتيب والتنظيم
        if ($sort === 'oldest') {
            $query->orderBy('id', 'asc');
        } elseif ($sort === 'name_asc') {
            $query->orderBy('name', 'asc');
        } elseif ($sort === 'most_active') {
            $query->orderByRaw('(tickets_count + cases_count + consults_count + executions_count) DESC');
        } else {
            $query->orderByDesc('id');
        }

        $clients = $query->paginate(50)->withQueryString();

        return Inertia::render('admin/clients', [
            'clients' => Paginate::shape($clients, fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'nid' => $u->national_id ?: '—',
                'email' => $u->email,
                'mobile' => $u->phone ?: '—',
                'tickets' => $u->tickets_count,
                'cases' => $u->cases_count,
                'consults' => $u->consults_count,
                'executions' => $u->executions_count,
                'status' => $u->isActive() ? 'نشط' : 'موقوف',
                'createdAt' => $u->created_at?->format('Y-m-d') ?: '—',
            ]),
            'filters' => [
                'q' => $search,
                'status' => $status,
                'activity' => $activity,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sort' => $sort,
            ],
            'summaryStats' => [
                'total' => User::where('role', Role::Client)->count(),
                'active' => User::where('role', Role::Client)->where('status', 'active')->count(),
                'suspended' => User::where('role', Role::Client)->where('status', 'suspended')->count(),
            ],
        ]);
    }

    /**
     * الملف الشامل للعميل — يعرض كافة بياناته الشخصية، وتاريخ نشاطه (تذاكر، قضايا، استشارات، تنفيذ، فواتير، مستندات).
     */
    public function show(User $client): Response
    {
        abort_unless($client->role === Role::Client, 404);

        // 1. التذاكر
        $tickets = Ticket::where('user_id', $client->id)
            ->with('assignedLawyer')
            ->latest('id')
            ->get()
            ->map(fn (Ticket $t) => [
                'id' => $t->id,
                'no' => $t->number,
                'type' => $t->type,
                'subject' => $t->subject ?: $t->type,
                'dept' => $t->department ?: '—',
                'status' => $t->status,
                'tone' => $t->tone,
                'lawyer' => $t->assignedLawyer?->name ?? '—',
                'date' => $t->created_at?->format('Y-m-d') ?: '—',
            ]);

        // 2. القضايا
        $cases = LegalCase::where('user_id', $client->id)
            ->with(['assignedLawyer', 'hearings'])
            ->latest('id')
            ->get()
            ->map(fn (LegalCase $c) => [
                'id' => $c->id,
                'no' => $c->number,
                'type' => $c->type,
                'court' => $c->court_name ?: 'المحكمة العامة',
                'lawyer' => $c->assignedLawyer?->name ?? $c->assigned_lawyer ?? '—',
                'status' => $c->status,
                'tone' => $c->tone,
                'fee' => $c->fee ? number_format($c->fee).' ر.س' : '—',
                'feeStatus' => $c->fee_status,
                'hearingsCount' => $c->hearings->count(),
                'date' => $c->created_at?->format('Y-m-d') ?: '—',
            ]);

        // 3. الاستشارات
        $consults = Consult::where('user_id', $client->id)
            ->latest('id')
            ->get()
            ->map(fn (Consult $c) => [
                'id' => $c->id,
                'ref' => $c->ref,
                'subject' => $c->subject ?: 'استشارة قانونية',
                'channel' => $c->channel ?: '—',
                'specialty' => $c->specialty ?: '—',
                'status' => $c->status,
                'session' => $c->session ?: '—',
                'total' => $c->total ? number_format($c->total).' ر.س' : ($c->price ? number_format($c->price).' ر.س' : '—'),
                'isPaid' => $c->paid_at !== null,
                'lawyer' => $c->lawyer ?: '—',
                'when' => $c->when_label ?: ($c->day ? $c->day.' '.$c->time : '—'),
                'date' => $c->created_at?->format('Y-m-d') ?: '—',
            ]);

        // 4. طلبات التنفيذ
        $executions = Execution::where('user_id', $client->id)
            ->latest('id')
            ->get()
            ->map(fn (Execution $e) => [
                'id' => $e->id,
                'no' => $e->number,
                'sanad' => $e->sanad ?: '—',
                'subject' => $e->subject,
                'defendant' => $e->defendant ?: '—',
                'stage' => $e->effectiveStage(),
                'status' => $e->status,
                'tone' => $e->tone,
                'amount' => $e->amount ? number_format($e->amount).' ر.س' : '—',
                'fee' => $e->fee ? number_format($e->fee).' ر.س' : '—',
                'paid' => (bool) $e->paid,
                'date' => $e->created_at?->format('Y-m-d') ?: '—',
            ]);

        // 5. الفواتير
        $invoices = Invoice::where('user_id', $client->id)
            ->latest('id')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id' => $inv->id,
                'no' => $inv->number,
                'desc' => $inv->description ?: 'فاتورة خدمات قانونية',
                'amount' => number_format($inv->amount).' ر.س',
                'rawAmount' => (int) $inv->amount,
                'paid' => (bool) $inv->paid,
                'status' => $inv->paid ? 'مدفوعة' : ($inv->isOverdue() ? 'متأخرة' : $inv->status),
                'tone' => $inv->paid ? 'b-green' : ($inv->isOverdue() ? 'b-red' : $inv->tone),
                'dueAt' => $inv->due_at?->format('Y-m-d') ?: ($inv->due_label ?: '—'),
                'date' => $inv->created_at?->format('Y-m-d') ?: '—',
            ]);

        // 6. المستندات المجمعة للعميل
        $directDocs = Document::where('user_id', $client->id)->latest('id')->get()
            ->map(fn (Document $d) => [
                'id' => 'doc-'.$d->id,
                'name' => $d->name,
                'meta' => $d->meta ?: ($d->direction === 'up' ? 'مرفوع من العميل' : 'صادر من المكتب'),
                'type' => $d->direction === 'up' ? 'مرفوع' : 'صادر',
                'hasFile' => ! empty($d->path),
                'downloadUrl' => ! empty($d->path) ? route('documents.download', $d->id) : null,
                'date' => $d->created_at?->format('Y-m-d') ?: '—',
            ]);

        $ticketDocs = TicketDocument::whereHas('ticket', fn ($q) => $q->where('user_id', $client->id))->latest('id')->get()
            ->map(fn (TicketDocument $td) => [
                'id' => 'tdoc-'.$td->id,
                'name' => $td->name,
                'meta' => 'مستند تذكرة · '.($td->doc_type ?: ($td->ticket ? '#'.$td->ticket->number : 'تذكرة')),
                'type' => 'تذكرة',
                'hasFile' => ! empty($td->path),
                'downloadUrl' => ! empty($td->path) ? route('documents.download-file', ['type' => 'ticket', 'id' => $td->id]) : null,
                'date' => $td->created_at?->format('Y-m-d') ?: '—',
            ]);

        $caseDocs = CaseDocument::whereHas('legalCase', fn ($q) => $q->where('user_id', $client->id))->latest('id')->get()
            ->map(fn (CaseDocument $cd) => [
                'id' => 'cdoc-'.$cd->id,
                'name' => $cd->name,
                'meta' => 'مستند قضية · '.($cd->doc_type ?: ($cd->legalCase ? '#'.$cd->legalCase->number : 'قضية')),
                'type' => 'قضية',
                'hasFile' => ! empty($cd->path),
                'downloadUrl' => ! empty($cd->path) ? route('documents.download-file', ['type' => 'case', 'id' => $cd->id]) : null,
                'date' => $cd->created_at?->format('Y-m-d') ?: '—',
            ]);

        $execDocs = ExecutionDocument::whereHas('execution', fn ($q) => $q->where('user_id', $client->id))->latest('id')->get()
            ->map(fn (ExecutionDocument $ed) => [
                'id' => 'edoc-'.$ed->id,
                'name' => $ed->label ?: basename((string) $ed->path),
                'meta' => 'مستند تنفيذ · '.($ed->doc_type ?: ($ed->execution ? '#'.$ed->execution->number : 'تنفيذ')),
                'type' => 'تنفيذ',
                'hasFile' => ! empty($ed->path),
                'downloadUrl' => ! empty($ed->path) ? route('documents.download-file', ['type' => 'exec', 'id' => $ed->id]) : null,
                'date' => $ed->created_at?->format('Y-m-d') ?: '—',
            ]);

        $allDocs = $directDocs->concat($ticketDocs)->concat($caseDocs)->concat($execDocs)->values();

        // 7. الحسابات والإحصائيات
        $totalInvoiced = (int) Invoice::where('user_id', $client->id)->sum('amount');
        $totalPaid = (int) Invoice::where('user_id', $client->id)->where('paid', true)->sum('amount');
        $unpaidBalance = $totalInvoiced - $totalPaid;

        return Inertia::render('admin/client-detail', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'phone' => $client->phone ?: '',
                'national_id' => $client->national_id ?: '',
                'status' => $client->isActive() ? 'active' : 'suspended',
                'statusLabel' => $client->isActive() ? 'نشط' : 'موقوف',
                'avatar' => $client->avatar_initials ?: self::initials($client->name),
                'phoneVerifiedAt' => $client->phone_verified_at?->format('Y-m-d H:i') ?: null,
                'emailVerifiedAt' => $client->email_verified_at?->format('Y-m-d H:i') ?: null,
                'createdAt' => $client->created_at?->format('Y-m-d H:i') ?: '—',
            ],
            'stats' => [
                'totalTickets' => $tickets->count(),
                'openTickets' => Ticket::where('user_id', $client->id)->whereNotIn('status', ['مكتملة', 'مغلقة'])->count(),
                'totalCases' => $cases->count(),
                'activeCases' => LegalCase::where('user_id', $client->id)->whereNotIn('status', ['مغلقة', 'مؤرشفة', 'صدر الحكم'])->count(),
                'totalConsults' => $consults->count(),
                'totalExecutions' => $executions->count(),
                'totalInvoiced' => $totalInvoiced,
                'totalPaid' => $totalPaid,
                'unpaidBalance' => $unpaidBalance,
            ],
            'tickets' => $tickets,
            'cases' => $cases,
            'consults' => $consults,
            'executions' => $executions,
            'invoices' => $invoices,
            'documents' => $allDocs,
        ]);
    }

    /**
     * تحديث بيانات العميل الأساسية من لوحة الإدارة.
     */
    public function update(Request $request, User $client): RedirectResponse
    {
        abort_unless($client->role === Role::Client, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($client->id)],
            'phone' => ['required', Phone::RULE, Rule::unique('users', 'phone')->where('role', Role::Client->value)->ignore($client->id)],
            'national_id' => ['required', 'regex:/^\d{10}$/', Rule::unique('users', 'national_id')->where('role', Role::Client->value)->ignore($client->id)],
            'status' => ['required', 'string', 'in:active,suspended'],
        ], [
            'name.required' => 'أدخل اسم العميل.',
            'name.max' => 'اسم العميل طويل جداً.',
            'email.required' => 'أدخل البريد الإلكتروني.',
            'email.email' => 'أدخل بريداً إلكترونياً صحيحاً.',
            'email.unique' => 'البريد الإلكتروني مسجل لحساب آخر.',
            'phone.required' => 'أدخل رقم الجوال.',
            'phone.regex' => 'رقم الجوال غير صالح — محليّ 05XXXXXXXX أو دوليّ ‎+9665XXXXXXXX.',
            'phone.unique' => 'رقم الجوال مسجل لحساب عميل آخر.',
            'national_id.required' => 'أدخل رقم الهوية.',
            'national_id.regex' => 'رقم الهوية يجب أن يتكون من 10 أرقام.',
            'national_id.unique' => 'رقم الهوية مسجل لحساب عميل آخر.',
            'status.required' => 'حدد حالة الحساب.',
            'status.in' => 'حالة الحساب غير صالحة.',
        ]);

        $client->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'national_id' => $data['national_id'],
            'status' => $data['status'],
            'avatar_initials' => self::initials($data['name']),
        ]);

        return back()->with('success', 'تم حفظ وتحديث بيانات العميل بنجاح.');
    }

    /**
     * تبديل حالة حساب العميل (تفعيل / إيقاف).
     */
    public function toggle(User $client): RedirectResponse
    {
        abort_unless($client->role === Role::Client, 404);

        $newStatus = $client->isActive() ? 'suspended' : 'active';
        $client->update(['status' => $newStatus]);

        return back()->with('success', $newStatus === 'active' ? 'تم تفعيل حساب العميل بنجاح.' : 'تم إيقاف حساب العميل.');
    }

    private static function initials(string $name): string
    {
        $clean = preg_replace('/^أ\.?\s*/u', '', trim($name));
        $parts = preg_split('/\s+/u', $clean) ?: [];

        return mb_substr($parts[0] ?? '', 0, 1).(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1) : '');
    }
}
