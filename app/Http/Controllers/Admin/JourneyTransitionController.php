<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Support\CaseJourney;
use App\Support\Paginate;
use App\Support\SearchText;
use App\Support\TicketJourney;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **لوحة رقابة وحوكمة انتقالات الرحلة الموحدة (Journey Transitions Audit)**
 *
 * تعرض كل الحركات والانتقالات الصادرة عن محرك الحالات المركزي `Workflow::run`
 * لجميع النطاقات الخمسة (التذاكر، الاستشارات، الفواتير، القضايا، والتنفيذ).
 */
class JourneyTransitionController extends Controller
{
    /** قاموس ترجمة أسماء الكيانات للعرض العربي */
    public const ENTITY_LABELS = [
        'Ticket' => 'تذكرة',
        'ticket' => 'تذكرة',
        'Consult' => 'استشارة',
        'consult' => 'استشارة',
        'LegalCase' => 'قضية',
        'case' => 'قضية',
        'Execution' => 'تنفيذ قضائي',
        'execution' => 'تنفيذ قضائي',
        'Invoice' => 'فاتورة مالية',
        'invoice' => 'فاتورة مالية',
        'TicketSummary' => 'ملخص تذكرة',
    ];

    /** ألوان ونغمات الكيانات */
    public const ENTITY_TONES = [
        'Ticket' => 'b-blue',
        'Consult' => 'b-amber',
        'LegalCase' => 'b-green',
        'Execution' => 'b-purple',
        'Invoice' => 'b-cyan',
        'TicketSummary' => 'b-grey',
    ];

    public function index(Request $request): Response
    {
        $query = $this->filtered($request)->with('actor');

        // الترقيم الخادومي
        $paginated = $query->paginate(50)->withQueryString();

        // الإحصائيات الفورية (KPIs)
        $today = Carbon::today();
        $stats = [
            'total' => JourneyTransition::count(),
            'today' => JourneyTransition::whereDate('created_at', $today)->count(),
            'withReason' => JourneyTransition::whereNotNull('reason')->where('reason', '!=', '')->count(),
            'byEntity' => [
                'tickets' => JourneyTransition::whereIn('entity_type', ['Ticket', 'ticket'])->count(),
                'consults' => JourneyTransition::whereIn('entity_type', ['Consult', 'consult'])->count(),
                'cases' => JourneyTransition::whereIn('entity_type', ['LegalCase', 'case'])->count(),
                'executions' => JourneyTransition::whereIn('entity_type', ['Execution', 'execution'])->count(),
                'invoices' => JourneyTransition::whereIn('entity_type', ['Invoice', 'invoice'])->count(),
            ],
        ];

        // قوائم الفلاتر المستخرجة من البيانات الحية
        $availableEntities = JourneyTransition::distinct('entity_type')
            ->pluck('entity_type')
            ->filter()
            ->values()
            ->map(fn ($type) => [
                'key' => $type,
                'label' => self::ENTITY_LABELS[$type] ?? $type,
            ])
            ->all();

        $availableTransitions = JourneyTransition::distinct('transition')
            ->pluck('transition')
            ->filter()
            ->values()
            ->map(fn ($tr) => [
                'key' => $tr,
                'label' => self::humanTransitionName($tr),
            ])
            ->all();

        $availableActors = User::whereIn('id', JourneyTransition::distinct('actor_id')->pluck('actor_id')->filter())
            ->get(['id', 'name', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                // اسم الدور بالعربيّة من `Role` — كانت الشاشة تعرض `lawyer` و`admin` كما هما
                'roleLabel' => $u->role->label(),
            ])
            ->all();

        return Inertia::render('admin/journey-transitions', [
            'transitions' => Paginate::shape($paginated, fn (JourneyTransition $row) => $this->shapeRow($row)),
            'stats' => $stats,
            'filters' => [
                'search' => $request->input('search', ''),
                'entity_type' => $request->input('entity_type', 'all'),
                'transition' => $request->input('transition', 'all'),
                'actor_id' => $request->input('actor_id', 'all'),
                'from_date' => $request->input('from_date', ''),
                'to_date' => $request->input('to_date', ''),
            ],
            'availableEntities' => $availableEntities,
            'availableTransitions' => $availableTransitions,
            'availableActors' => $availableActors,
        ]);
    }

    /**
     * تشكيل صف الانتقال ليناسب العرض الغني في الواجهة مع الروابط العميقة
     */
    /**
     * **لون الحالة بكتالوج نوعها** — كان كلّ صفٍّ يُلوَّن بكتالوج التذاكر (`TicketJourney::toneFor`)،
     * فحالات القضايا والاستشارات والتنفيذ والفواتير تسقط إلى أزرقه الاحتياطيّ. وانتقالات الاستشارة
     * تسجّل حالة الجلسة أحياناً (`MarkNoShow`: بانتظار الجلسة → لم تُعقد)، فيُقرأ كتالوجها بعدها.
     */
    private static function stateTone(string $type, string $state): string
    {
        return match (strtolower($type)) {
            'ticket' => TicketJourney::toneFor($state),
            'consult' => ConsultStatus::tryFrom($state)?->tone() ?? SessionState::tryFrom($state)?->tone() ?? 'b-grey',
            'legalcase', 'case' => CaseJourney::toneFor($state),
            'execution' => ExecutionStatus::tryFrom($state)?->tone() ?? 'b-grey',
            'invoice' => InvoiceStatus::tryFrom($state)?->tone() ?? 'b-grey',
            default => 'b-grey',
        };
    }

    private function shapeRow(JourneyTransition $row): array
    {
        $entityType = (string) $row->entity_type;
        $cleanType = class_basename($entityType);
        $ref = $row->entity_ref ?: "#{$row->entity_id}";

        return [
            'id' => $row->id,
            'entityType' => $cleanType,
            'entityLabel' => self::ENTITY_LABELS[$cleanType] ?? $cleanType,
            'entityTone' => self::ENTITY_TONES[$cleanType] ?? 'b-grey',
            'entityId' => $row->entity_id,
            'entityRef' => $ref,
            'url' => $this->resolveEntityUrl($cleanType, $row->entity_ref, $row->entity_id),
            'transition' => $row->transition,
            'transitionLabel' => self::humanTransitionName($row->transition),
            'fromState' => self::humanStateName($row->from_state),
            'toState' => self::humanStateName($row->to_state),
            'fromTone' => $row->from_state ? self::stateTone($cleanType, (string) $row->from_state) : 'b-grey',
            'toTone' => self::stateTone($cleanType, (string) $row->to_state),
            'actor' => $row->actor ? [
                'id' => $row->actor->id,
                'name' => $row->actor->name,
                'role' => $row->actor->role instanceof \BackedEnum ? $row->actor->role->value : (string) $row->actor->role,
                'roleLabel' => self::actorRoleLabel($row->actor),
            ] : [
                'id' => null,
                'name' => 'النظام الآلي / الذكاء الاصطناعي',
                'role' => 'system',
                'roleLabel' => self::actorRoleLabel(null),
            ],
            'reason' => $row->reason ?: null,
            'payload' => $row->payload ?: null,
            'payloadItems' => self::formatPayload($row->payload),
            'createdAt' => $row->created_at?->format('Y/m/d H:i:s'),
            'since' => $row->created_at?->locale('ar')->diffForHumans(),
        ];
    }

    /**
     * توليد رابط عميق ومباشر لفتح الملف المستهدف في لوحة الإدارة
     */
    private function resolveEntityUrl(string $type, ?string $ref, mixed $id): ?string
    {
        return match ($type) {
            'Ticket', 'ticket' => $ref ? "/admin/tickets/{$ref}" : ($id ? "/admin/tickets/{$id}" : '/admin/tickets'),
            // صفحة الاستشارة نفسها بمرجعها — كان `/admin/consults?q=` وتلك الصفحة لا تقرأ `q`
            'Consult', 'consult' => $ref ? '/admin/consult?ref='.urlencode($ref) : '/admin/consults',
            'LegalCase', 'case' => $ref ? "/admin/cases/{$ref}" : ($id ? "/admin/cases/{$id}" : '/admin/cases'),
            'Execution', 'execution' => '/admin/execs',
            // تبويب الفواتير بلا `q`: شاشة المالية لا بحث فيها بعد ولا صفحةَ تفاصيل للفاتورة، ورابطٌ
            // يَعِد بترشيحٍ لا يقع أسوأ من رابطٍ صادق إلى القائمة.
            'Invoice', 'invoice' => '/admin/finance?tab=invoices',
            'TicketSummary' => $ref ? "/admin/tickets/{$ref}" : '/admin/approvals',
            default => null,
        };
    }

    /**
     * تصدير السجلات بتنسيق CSV للمراجعة والامتثال المؤسسي
     */
    public function export(Request $request): StreamedResponse
    {
        // **مرشّحات الشاشة كلّها** (`filtered`): كان التصدير يُهمل «الانتقال» و«الفاعل» ويقطع عند
        // ٢٠٠٠ صفّ بصمت — فيُصدَّر غيرُ ما يُرى. والقراءة دفعاتٌ بلا سقف ولا تحميلٍ كامل في الذاكرة.
        $records = $this->filtered($request)->with('actor')->lazyByIdDesc(500);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="journey_transitions_'.date('Y-m-d_His').'.csv"',
        ];

        return response()->stream(function () use ($records) {
            $out = fopen('php://output', 'w');
            // علامة UTF-8 BOM للتوافق التام مع Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'المعرف',
                'التاريخ والوقت',
                'نوع الكيان',
                'مرجع الملف',
                'العملية النظامية',
                'الحالة السابقة',
                'الحالة الجديدة',
                'الفاعل المسؤول',
                'الدور',
                'المبرر والتسبيب',
            ]);

            foreach ($records as $r) {
                $cleanType = class_basename((string) $r->entity_type);
                fputcsv($out, [
                    $r->id,
                    $r->created_at ? $r->created_at->format('Y-m-d H:i:s') : '—',
                    self::ENTITY_LABELS[$cleanType] ?? $cleanType,
                    $r->entity_ref ?: "#{$r->entity_id}",
                    self::humanTransitionName($r->transition),
                    self::humanStateName($r->from_state),
                    self::humanStateName($r->to_state),
                    $r->actor?->name ?? 'النظام الآلي',
                    self::actorRoleLabel($r->actor),
                    $r->reason ?: '—',
                ]);
            }

            fclose($out);
        }, 200, $headers);
    }

    /**
     * **المرشّحات في موضعٍ واحد** — العرض والتصدير يقرآنها معاً، فلا يُصدَّر غيرُ ما يُرى.
     *
     * @return Builder<JourneyTransition>
     */
    private function filtered(Request $request): Builder
    {
        $query = JourneyTransition::query()->latest('id');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                SearchText::apply($q, ['entity_ref', 'transition', 'from_state', 'to_state', 'reason'], $search);
                $q->orWhereHas('actor', fn ($aq) => SearchText::apply($aq, ['name', 'email'], $search));
            });
        }

        foreach (['entity_type', 'transition'] as $column) {
            $value = $request->input($column);
            if ($value && $value !== 'all') {
                $query->where($column, $value);
            }
        }

        $actorId = $request->input('actor_id');
        if ($actorId && $actorId !== 'all') {
            $actorId === 'system' ? $query->whereNull('actor_id') : $query->where('actor_id', (int) $actorId);
        }

        if ($fromDate = $request->input('from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate = $request->input('to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        return $query;
    }

    /** اسم دور الفاعل بالعربيّة — و«إجراء آليّ» حين لا فاعل بشريّ. */
    private static function actorRoleLabel(?User $actor): string
    {
        return $actor?->role instanceof Role ? $actor->role->label() : 'إجراء آليّ';
    }

    /**
     * ترجمة إنسانية نظيفة لأسماء الانتقالات البرمجية
     */
    public static function humanTransitionName(?string $name): string
    {
        if (! $name) {
            return 'انتقال حالة';
        }

        return match ($name) {
            'ticket.opened' => 'إنشاء التذكرة',
            'ticket.awaiting_documents' => 'طلب استكمال المستندات',
            'ticket.documents_received' => 'استلام والتحقق من المستندات',
            'ticket.referred_to_lawyer' => 'إحالة التذكرة للمستشار',
            'ticket.awaiting_admin_summary_approval' => 'رفع الملخص لاعتماد الإدارة',
            'ticket.legal_opinion_published' => 'نشر الرأي القانوني المعتمد',
            'ticket.propose_outcome_track' => 'رفع مقترح مآل التذكرة',
            'ticket.approve_outcome_track' => 'اعتماد المسار النهائي للتذكرة',
            'ticket.convert_to_case' => 'تحويل التذكرة إلى قضية',
            'ticket.consult_requested' => 'طلب حجز استشارة على التذكرة',
            'ticket.awaits_schedule' => 'بانتظار جدولة الموعد',
            'ticket.scheduled' => 'تثبيت موعد الاستشارة',
            'ticket.booking_withdrawn' => 'إلغاء حجز الموعد للتذكرة',
            'ticket.rescheduled' => 'إعادة جدولة موعد التذكرة',
            'ticket.ready_for_outcome' => 'جاهزية التذكرة لحسم المآل',
            'ticket.close_justified' => 'إغلاق مسبب للتذكرة',
            'ticket.correct-status' => 'تصحيح إداري لحالة التذكرة',
            'ticket.session_ended' => 'انتهاء جلسة الاستشارة',

            'ticket_summary.opened' => 'صياغة ملخص الملف',
            'ticket_summary.lawyer_approved' => 'اعتماد المستشار للملخص',
            'ticket_summary.approved' => 'اعتماد الإدارة للملخص',

            'consult.request' => 'طلب استشارة جديدة',
            'consult.price' => 'تسعير الاستشارة وتحديد الرسوم',
            'consult.settle-payment' => 'سداد فاتورة الاستشارة',
            'consult.refer' => 'إحالة الاستشارة للدراسة',
            'consult.propose-appointment' => 'اقتراح موعد الاستشارة',
            'consult.publish-appointment' => 'اعتماد وتثبيت موعد الاستشارة',
            'consult.start' => 'بدء انعقاد الجلسة',
            'consult.no-show' => 'تسجيل تعذر حضور الجلسة',
            'consult.reschedule' => 'إعادة جدولة موعد الاستشارة',
            'consult.cancel' => 'إلغاء الاستشارة',

            'case.open_from_outcome' => 'قيد قضية من مآل تذكرة',
            'case.activate' => 'تفعيل ومباشرة ملف القضية',
            'case.file_najiz' => 'رفع صحيفة الدعوى بناجز',
            'case.register_najiz' => 'قيد الدعوى ورقم المحكمة',
            'case.record_ruling' => 'تسجيل حكم قضائي',
            'case.set_fee' => 'اعتماد أتعاب القضية',

            'exec.open_from_ticket' => 'فتح ملف تنفيذ من تذكرة',
            'exec.open_from_case' => 'فتح ملف تنفيذ من حكم قضية',
            'exec.activate' => 'مباشرة إجراءات التنفيذ',
            'exec.apply_analysis' => 'فحص السند التنفيذي',
            'exec.set_fee' => 'تقدير أتعاب التنفيذ',
            'exec.approve_fee' => 'اعتماد عرض أتعاب التنفيذ',
            'exec.accept_offer' => 'موافقة العميل على أتعاب التنفيذ',
            'exec.file_najiz' => 'قيد السند في محكمة التنفيذ',
            'exec.register_najiz' => 'صدور قرار التنفيذ القضائي',
            'exec.apply_measures' => 'تطبيق إجراءات المادة 46 الجبرية',
            'exec.notify_debtor' => 'إشعار المنفذ ضده بالمطالبة',
            'exec.add_collection' => 'قيد تحصيل مالي جزئي/كلي',
            'exec.close' => 'إغلاق ملف التنفيذ',

            'invoice.opened' => 'إصدار الفاتورة',
            'invoice.settle' => 'تسوية وسداد الفاتورة',
            'invoice.cancel' => 'إلغاء الفاتورة',
            'invoice.write_off' => 'إسقاط الفاتورة كدين معدوم',

            'meeting.reschedule' => 'إعادة جدولة الاجتماع',
            'hearing.postpone' => 'تأجيل جلسة المحكمة',
            'conversation.handover' => 'تسليم المحادثة لموظف آخر',
            'conversation.claim' => 'استلام ومتابعة المحادثة',

            default => str_replace(['.', '_', '-'], ' ', $name),
        };
    }

    /**
     * ترجمة الحالات البرمجية إلى مسميات إدارية عربية واضحة
     */
    public static function humanStateName(?string $state): string
    {
        if ($state === null || $state === '' || $state === 'null' || $state === '—') {
            return 'إنشاء جديد';
        }

        return match ($state) {
            'awaiting_lawyer' => 'بانتظار المستشار',
            'awaiting_admin' => 'بانتظار الإدارة',
            'approved' => 'معتمد',
            'open' => 'مفتوحة',
            'completed' => 'مكتملة',
            'closed' => 'مغلقة',
            'under_review' => 'قيد المراجعة',
            'pending_admin' => 'بانتظار الاعتماد',
            'in_consultation' => 'جلسة استشارة',
            'pending_docs' => 'بانتظار المستندات',
            'referred_to_lawyer' => 'محالة للمستشار',
            'paid' => 'مدفوعة',
            'unpaid' => 'غير مدفوعة',
            'draft' => 'مسودة',
            'cancelled' => 'ملغاة',
            'active' => 'نشطة',
            'in_progress' => 'قيد المعالجة',
            'resolved' => 'تم الحل',
            default => $state,
        };
    }

    /**
     * تحويل بيانات الإجراء (Payload) إلى مصفوفة مدخلات عربية منسقة بوضوح
     */
    public static function formatPayload(?array $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $labels = [
            'fee' => 'قيمة الأتعاب',
            'fee_mode' => 'نوع الأتعاب',
            'duration' => 'المدة المتوقعة (أيام)',
            'court' => 'المحكمة المختصة',
            'circuit' => 'الدائرة القضائية',
            'case_no' => 'رقم القضية',
            'registered_at' => 'تاريخ القيد',
            'filed_at' => 'تاريخ الرفع في ناجز',
            'request_no' => 'رقم الطلب',
            'amount' => 'مبلغ الفاتورة',
            'vat_amount' => 'ضريبة القيمة المضافة',
            'lawyer_fee' => 'أتعاب المحامي',
            'lawyer_pct' => 'نسبة المحامي',
            'ruling' => 'منطوق الحكم',
            'channel' => 'قناة السداد',
            'pay_plan' => 'خطة السداد',
            'fee_status' => 'حالة الأتعاب',
            'ticket' => 'رقم التذكرة',
            'case' => 'رقم القضية',
            'summary_waiver_reason' => 'مبرر استثناء الملخص',
            'ai_source' => 'مصدر التحليل الآلي',
            'advance' => 'دفعة مقدمة',
            'complete' => 'إجراء مكتمل',
            'collection_fee_pct' => 'نسبة عمولة التحصيل',
        ];

        $items = [];
        foreach ($payload as $key => $val) {
            $label = $labels[$key] ?? str_replace(['_', '.'], ' ', $key);

            $displayVal = $val;
            if (is_bool($val)) {
                $displayVal = $val ? 'نعم' : 'لا';
            } elseif (is_null($val)) {
                $displayVal = '—';
            } elseif ($key === 'fee_mode') {
                $displayVal = $val === 'fixed' ? 'أتعاب مقطوعة' : ($val === 'percentage' ? 'نسبة مئوية' : $val);
            } elseif ($key === 'pay_plan') {
                $displayVal = $val === 'full' ? 'سداد كامل' : ($val === 'installments' ? 'أقساط' : $val);
            } elseif ($key === 'fee_status') {
                $displayVal = $val === 'paid' ? 'تم السداد' : ($val === 'unpaid' ? 'معلق' : $val);
            } elseif (in_array($key, ['fee', 'amount', 'vat_amount', 'lawyer_fee']) && is_numeric($val)) {
                $displayVal = number_format((float) $val).' ر.س';
            } elseif (in_array($key, ['lawyer_pct', 'collection_fee_pct']) && is_numeric($val)) {
                $displayVal = $val.'%';
            } elseif (is_array($val)) {
                $displayVal = json_encode($val, JSON_UNESCAPED_UNICODE);
            }

            $items[] = [
                'label' => $label,
                'value' => (string) $displayVal,
            ];
        }

        return $items;
    }
}
