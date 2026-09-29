<?php

namespace App\Support\Finance;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * **شاشة «المالية والمحاسبة» — كلّ رقمٍ فيها يُشتقّ هنا** (م٣ من خطّة النظام الماليّ).
 *
 * لماذا صنفٌ قائمٌ بذاته لا استعلاماتٌ في المتحكّم؟ لأنّ العطل الأكبر في هذا النظام (ع١) وُلد
 * من حسابٍ مكتوبٍ مرّتين: `ReportController::revenue()` و`revenuePdf()`. فالمتحكّم هنا يقرأ
 * الطلب ويصيّر، والحساب كلُّه في موضعٍ واحد يُختبَر وحده — **والواجهة لا تحسب شيئاً**، تعرض
 * ما وصلها.
 *
 * ── **ما الجديد حقّاً في هذه الشاشة: التصفية بالفترة** ───────────────────────────────────
 *
 * لم تكن ممكنةً في النظام كلّه قبل عمود `invoices.paid_at` (م١): البديل الوحيد كان
 * `updated_at` وهو يتحرّك مع تذكيرٍ أو رفع إثباتٍ أو تصحيح وصف. فـ«كم دخلنا هذا الربع؟»
 * كان سؤالاً بلا جواب.
 *
 * ── **تدفّقٌ أم رصيد؟ — الفرق الذي يجب ألّا يُخلط** ──────────────────────────────────────
 *
 * الشاشة تعرض نوعين من الأرقام، ولكلٍّ تاريخُه:
 *
 * | النوع | المعنى | التاريخ الحاكم |
 * |---|---|---|
 * | **تدفّق** (المُصدَر · المحصَّل · الضريبة · المقبوضات) | ما وقع **داخل الفترة** | `issued_at` للإصدار، `paid_at` للتحصيل |
 * | **رصيد** (الذمم · المتأخّر · الأعمار) | ما هو قائمٌ **الآن** | لا تاريخ — لقطةُ اللحظة |
 *
 * ولذلك لا تسري الفترة على الذمم والأعمار: «ذمّةُ الربع الماضي» عبارةٌ بلا معنى — الذمّة
 * إمّا قائمةٌ اليوم أو سُدّدت. وكلُّ بطاقةٍ تحمل تسميتها صريحةً («في الفترة» / «حتى اليوم»)
 * فلا يُقارَن رقمٌ بآخر من طبيعةٍ أخرى.
 *
 * ── **والاعتراف بالإيراد عند التحصيل** ─────────────────────────────────────────────────
 *
 * ق٧ في الخطّة: الأساس نقديّ. فـ«محصَّل الفترة» و«ضريبة الفترة» مرجعُهما `paid_at`، لا تاريخ
 * الإصدار — وهو ما يجعل الرقم يطابق الحساب البنكيّ بلا تسويات.
 *
 * ── **والسنة الماليّة تقويميّة** ────────────────────────────────────────────────────────
 *
 * ق٤ (أيّ شهرٍ تبدأ السنة الماليّة؟) **لم يُحسم بعد**، فالفترات هنا تقويميّة: الشهر والربع
 * والسنة الميلاديّة. وحين يُحسم يتغيّر هذا الموضع وحده.
 */
final class FinanceBoard
{
    /** التبويبات الستّة — مفتاحُها في `?tab=` وتسميتُها تُبَثّ للواجهة، فلا تُكتب مرّتين. */
    public const TABS = [
        'dashboard' => 'لوحة المالية',
        'invoices' => 'الفواتير',
        'receipts' => 'المقبوضات',
        'expenses' => 'المصروفات',
        'aging' => 'الذمم والأعمار',
        'vat' => 'الضريبة',
        'reports' => 'التقارير',
    ];

    /** التبويب الافتراضيّ — وإليه يسقط أيّ مفتاحٍ مجهول (لا صفحةَ خطأ على مَعلمةٍ مكتوبة خطأً). */
    public const DEFAULT_TAB = 'dashboard';

    /** الفترات المتاحة — `custom` تقرأ `from`/`to` من الطلب. */
    public const PERIODS = [
        'month' => 'هذا الشهر',
        'quarter' => 'هذا الربع',
        'year' => 'هذه السنة',
        'custom' => 'مدى مخصّص',
    ];

    public const DEFAULT_PERIOD = 'month';

    /** أنواع الفواتير — الرابط على الصفّ هو ما يحدّد النوع (نمط `RevenueSnapshot::incomeSplit`). */
    public const KINDS = [
        'all' => 'كل الأنواع',
        'consult' => 'استشارة',
        'case' => 'قضية',
        'exec' => 'تنفيذ',
    ];

    /** حدود شرائح الأعمار بالأيّام — `null` للشريحة المفتوحة (+٩٠). */
    public const AGING_BUCKETS = [
        ['k' => 'b1', 'label' => '١–٣٠ يوماً', 'from' => 1, 'to' => 30],
        ['k' => 'b2', 'label' => '٣١–٦٠ يوماً', 'from' => 31, 'to' => 60],
        ['k' => 'b3', 'label' => '٦١–٩٠ يوماً', 'from' => 61, 'to' => 90],
        ['k' => 'b4', 'label' => 'أكثر من ٩٠ يوماً', 'from' => 91, 'to' => null],
    ];

    public const PER_PAGE = 50;

    // ─────────────────────────────── التبويب والفترة ───────────────────────────────

    /** مفتاح تبويبٍ صالح — والمجهول يسقط إلى الافتراضيّ لا إلى ٤٠٤. */
    public static function tab(?string $tab): string
    {
        return isset(self::TABS[(string) $tab]) ? (string) $tab : self::DEFAULT_TAB;
    }

    /**
     * **الفترة المطلوبة، مُقوَّمة** — مفتاحُها وحدّاها ووصفُها للعرض.
     *
     * `custom` بمدىً مقلوبٍ أو تاريخٍ غير صالح لا يُسقط الشاشة: يعود إلى الافتراضيّ. والمدى
     * المقلوب (من بعد إلى) يُقوَّم بالتبديل — خطأُ إدخالٍ شائع لا يستحقّ رسالةَ خطأ.
     *
     * @return array{key:string, from:CarbonInterface, to:CarbonInterface, label:string, fromDate:string, toDate:string}
     */
    public static function period(?string $key, ?string $from = null, ?string $to = null): array
    {
        $key = isset(self::PERIODS[(string) $key]) ? (string) $key : self::DEFAULT_PERIOD;

        if ($key === 'custom') {
            $start = self::parseDate($from);
            $end = self::parseDate($to);

            if ($start === null || $end === null) {
                $key = self::DEFAULT_PERIOD; // مدىً ناقص — لا معنى له، فالافتراضيّ أصدق من صفر
            } else {
                if ($start->greaterThan($end)) {
                    [$start, $end] = [$end, $start];
                }

                return self::shape('custom', $start->startOfDay(), $end->endOfDay());
            }
        }

        $now = now();

        return match ($key) {
            'quarter' => self::shape('quarter', $now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()),
            'year' => self::shape('year', $now->copy()->startOfYear(), $now->copy()->endOfYear()),
            default => self::shape('month', $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
        };
    }

    /**
     * **الفترة السابقة المقابلة** — مقارنة التقارير (قرار المالك 2026-09-29): الشهر بالشهر قبله،
     * والربع بالربع قبله، والسنة بالسنة قبلها؛ والمدى المخصّص بمدىً بطوله ينتهي قبل بدايته بيوم.
     *
     * @param  array{key:string, from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return array{key:string, from:CarbonInterface, to:CarbonInterface, label:string, fromDate:string, toDate:string}
     */
    public static function previousPeriod(array $period): array
    {
        $from = CarbonImmutable::instance($period['from']);

        return match ($period['key']) {
            'month' => self::shape('month', $from->subMonthNoOverflow()->startOfMonth(), $from->subMonthNoOverflow()->endOfMonth()),
            'quarter' => self::shape('quarter', $from->subQuarterNoOverflow()->startOfQuarter(), $from->subQuarterNoOverflow()->endOfQuarter()),
            'year' => self::shape('year', $from->subYear()->startOfYear(), $from->subYear()->endOfYear()),
            default => self::shape(
                'custom',
                $from->subDays((int) $from->diffInDays(CarbonImmutable::instance($period['to'])->startOfDay()) + 1)->startOfDay(),
                $from->subDay()->endOfDay(),
            ),
        };
    }

    // ─────────────────────────────── ١. لوحة المالية ───────────────────────────────

    /**
     * بطاقات اللوحة وأعلى خمسة مدينين.
     *
     * **والمحصَّل والضريبة من `RevenueSnapshot::collectedBetween`** لا باستعلامٍ هنا: هو
     * المصدر الواحد لـ«الدخل»، واستعلامٌ ثانٍ بالمعنى نفسه هو بعينه ما ولّد ع١.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     */
    public static function dashboard(array $period): array
    {
        $collected = RevenueSnapshot::collectedBetween($period['from'], $period['to']);

        // الصادر في الفترة: الملغاة خارجه (لا مطالبةَ صدرت)، والمعدومة داخله (صدرت فعلاً ثمّ
        // أُسقطت لاحقاً — وإخراجُها يعيد كتابة ماضي الفترة).
        $issued = (int) Invoice::issued()
            ->whereRaw(self::ISSUED_AT.' BETWEEN ? AND ?', [$period['from'], $period['to']])
            ->sum('amount');

        $debtors = self::debtorsByClient();

        return [
            'issued' => $issued,
            'collected' => $collected['total'],
            'vat' => $collected['vat'],
            'collectedCount' => $collected['count'],
            'receivables' => array_sum(array_column($debtors, 'total')),
            'receivablesCount' => array_sum(array_column($debtors, 'count')),
            'overdue' => array_sum(array_column($debtors, 'overdue')),
            'overdueCount' => array_sum(array_column($debtors, 'overdueCount')),
            // أعلى خمسة مدينين — من رولّة الأعمار نفسها، فلا يفترق رقمُ البطاقة عن رقم التبويب
            'topDebtors' => array_slice($debtors, 0, 5),
        ];
    }

    // ─────────────────────────────── ٢. الفواتير ───────────────────────────────

    /**
     * الفواتير مرشَّحةً بالحالة والنوع والفترة، مرقَّمةً.
     *
     * **والفترة هنا تاريخُ الإصدار لا التحصيل**: التبويب يجيب «ما الذي طالبنا به في هذه
     * الفترة؟». ولو رُشّح بتاريخ التحصيل لاختفت كلُّ فاتورةٍ غير مدفوعة — أي لاختفى بالضبط
     * ما يُفتح هذا التبويب لأجله.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return LengthAwarePaginator<int, Invoice>
     */
    public static function invoices(array $period, string $status, string $kind): LengthAwarePaginator
    {
        $q = Invoice::with('user')
            ->whereRaw(self::ISSUED_AT.' BETWEEN ? AND ?', [$period['from'], $period['to']]);

        // «غير مدفوعة» ليست حالةً مخزّنة بل مرشّحٌ عرضيّ (وهو تبويب شاشة المحاسبة القديمة)
        // وهي الذمّة القائمة نفسها (`Invoice::outstanding`) — لا تُدرج الملغاة والمعدومة
        if ($status === 'غير مدفوعة') {
            $q->outstanding();
        } elseif (in_array($status, InvoiceStatus::values(), true)) {
            $q->where('status', $status);
        }

        self::scopeKind($q, $kind);

        return $q->latest('id')->paginate(self::PER_PAGE)->withQueryString();
    }

    /** مرشّحات الحالة للواجهة: «الكل» ثمّ الحالات السبع بألوانها من مصدرها، ثمّ «غير مدفوعة». */
    public static function statusFilters(): array
    {
        $out = [['k' => 'all', 'label' => 'الكل', 'tone' => 'b-grey']];

        foreach (InvoiceStatus::cases() as $case) {
            $out[] = ['k' => $case->value, 'label' => $case->value, 'tone' => $case->tone()];
        }

        $out[] = ['k' => 'غير مدفوعة', 'label' => 'غير مدفوعة', 'tone' => 'b-amber'];

        return $out;
    }

    // ─────────────────────────────── ٣. المقبوضات ───────────────────────────────

    /**
     * دفتر ما دخل فعلاً — صفٌّ لكلّ قيدٍ في `payments`.
     *
     * **والمبلغ من `amount_halalas` وحده** (م٠): العمود القديم `amount` مختلطٌ — بالهللة في
     * صفوف البوّابة وبالريال في التحصيل اليدويّ — فصفٌّ بـ`51800` وصفٌّ بـ`518` يعنيان المبلغ
     * نفسه، ولا يُجمع العمود. وهو يبقى لقطةَ ما ورد ومرجعَ اختباراتٍ قائمة، ولا يُقرأ هنا.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return LengthAwarePaginator<int, Payment>
     */
    public static function receipts(array $period): LengthAwarePaginator
    {
        return Payment::with(['invoice.user', 'actor'])->received()
            ->whereRaw(self::RECEIVED_AT.' BETWEEN ? AND ?', [$period['from'], $period['to']])
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** مجموع ما دخل في الفترة — بالريال، من العمود القابل للجمع وحده. */
    public static function receiptsTotal(array $period): float
    {
        $halalas = (int) Payment::received()->whereRaw(self::RECEIVED_AT.' BETWEEN ? AND ?', [$period['from'], $period['to']])
            ->sum('amount_halalas');

        return self::riyals($halalas);
    }

    /**
     * **هللاتٌ ← ريالٌ بكسره، لا بقطعه.** كان `intdiv` يقطع كلّ صفٍّ وحده ويقطع المجموع وحده،
     * فثلاثة مقبوضاتٍ بـ١٠٫٥٠ تُعرض ١٠+١٠+١٠ والمجموع ٣١ — صفوفٌ لا تجمع إلى إجماليّها. الكسر
     * العشريّ الدقيق (منزلتان = الهللة) يجعل المجموع مجموعَ الصفوف بحكم الحساب.
     */
    public static function riyals(int $halalas): float
    {
        return round($halalas / 100, 2);
    }

    /** صفّ المقبوضات كما تعرضه الواجهة — الاشتقاق هنا لا هناك. */
    public static function receiptRow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'receiptNo' => $payment->receipt_no,
            'at' => self::dateText($payment->received_at ?? $payment->reconciled_at ?? $payment->created_at),
            'method' => $payment->methodLabel(),
            'invoice' => $payment->invoice?->number ?? '—',
            'client' => Ticket::maskClient($payment->invoice?->user?->name ?? ''),
            'amount' => self::riyals((int) $payment->amount_halalas),
            // من قيَّده: التحصيل اليدويّ يحفظ اسم المحصِّل في `raw.actor`؛ والبوّابة لا فاعلَ
            // بشريّاً لها — فتُسمّى باسمها بدل أن يُنسب القيد إلى أحد
            'actor' => $payment->receiverLabel(),
        ];
    }

    // ─────────────────────────────── المصروفات (المرحلة ب) ───────────────────────────────

    /**
     * جدول المصروفات بالفترة (بتاريخ الصرف) والحالة والتصنيف. و**«بانتظار الاعتماد» بلا فترة**:
     * ما ينتظر قراراً يُعرض كلّه، لا ما وقع في الشهر الجاري وحده.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return LengthAwarePaginator<int, Expense>
     */
    public static function expenses(array $period, string $status, string $category): LengthAwarePaginator
    {
        $status = ExpenseStatus::tryFrom($status);

        return Expense::with(['creator:id,name', 'approver:id,name'])
            ->when($status !== ExpenseStatus::Pending, fn ($q) => $q->whereBetween('spent_on', [$period['from']->toDateString(), $period['to']->toDateString()]))
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->when(ExpenseCategory::tryFrom($category) !== null, fn ($q) => $q->where('category', $category))
            ->latest('spent_on')->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * أرقام رأس التبويب: المعتمد في الفترة (وضريبته)، وما ينتظر الاعتماد الآن.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return array{approved: float, approvedVat: float, pending: int}
     */
    public static function expensesSummary(array $period): array
    {
        $approved = Expense::counted()->whereBetween('spent_on', [$period['from']->toDateString(), $period['to']->toDateString()]);

        return [
            'approved' => self::riyals((int) (clone $approved)->sum('amount_halalas')),
            'approvedVat' => self::riyals((int) $approved->sum('vat_halalas')),
            'pending' => Expense::where('status', ExpenseStatus::Pending->value)->count(),
        ];
    }

    /**
     * صفّ المصروف كما تعرضه الواجهة. و`can` حكم الخادم للإدارة وحدها (الموظّف يرى حالة ما سجّله).
     *
     * @return array<string, mixed>
     */
    public static function expenseRow(Expense $expense, bool $forAdmin = false): array
    {
        return [
            'id' => $expense->id,
            'voucherNo' => $expense->voucher_no,
            'date' => VoucherFormat::date($expense->spent_on),
            'category' => $expense->category->label(),
            'description' => $expense->description,
            'vendor' => $expense->vendor,
            'amount' => self::riyals($expense->amount_halalas),
            'vat' => self::riyals($expense->vat_halalas),
            'paidFrom' => $expense->paidFromLabel(),
            'reference' => $expense->reference,
            'status' => $expense->status->label(),
            'tone' => $expense->status->tone(),
            'creator' => $expense->creator->name ?? '—',
            'approver' => $expense->approver->name ?? null,
            'reason' => $expense->reject_reason ?? $expense->void_reason,
            'hasDocument' => $expense->document_path !== null,
            'can' => $forAdmin ? array_keys(array_filter([
                'approve' => $expense->isPending(),
                'reject' => $expense->isPending(),
                'void' => $expense->isApproved(),
            ])) : [],
        ];
    }

    // ─────────────────────────────── ٤. الذمم والأعمار ───────────────────────────────

    /**
     * **أعمار الذمم لكلّ موكّل** — ومنها أيضاً بطاقتا «الذمم» و«المتأخّر» وأعلى المدينين.
     *
     * التعريف من `RevenueSnapshot::receivables()` لا من شرطٍ يُكتب هنا: غير مدفوعةٍ ولا ملغاةٍ
     * ولا معدومة. فرقمُ هذه الشاشة ورقمُ `/admin/revenue` من مصدرٍ واحد.
     *
     * **والشريحة من تاريخ الاستحقاق بشرط `Invoice::isOverdue` نفسه**: يومُ الاستحقاق ليس
     * تأخّراً حتى نهايته. فما استحقّ اليوم أو غداً في «لم يحلّ أجلها»، لا في شريحة ١–٣٠ —
     * ولو خالفنا الشرط لظهرت فاتورةٌ «متأخّرة» في الأعمار و«مستحقّة» في الجدول.
     *
     * **والحساب في PHP لا في SQL** عن قصد: حسابُ فرق التواريخ يختلف بين سائقي القاعدة
     * (sqlite في الاختبارات · mysql في الإنتاج)، ورقمُ الذمم أثمنُ من أن يعتمد على ذلك.
     * والحجم مكتبيّ — صفوفُ ذمّةٍ قائمة، لا الجدولُ كلُّه.
     *
     * @return list<array{id:int, client:string, b1:int, b2:int, b3:int, b4:int, notYetDue:int, overdue:int, overdueCount:int, total:int, count:int, lastReminder:?string}>
     */
    public static function debtorsByClient(): array
    {
        $rows = RevenueSnapshot::receivables()
            ->with('user:id,name')
            ->get(['id', 'user_id', 'amount', 'due_at', 'reminder_sent_at']);

        $today = now()->startOfDay();
        $byClient = [];

        foreach ($rows as $invoice) {
            $key = (int) ($invoice->user_id ?? 0);
            $byClient[$key] ??= [
                // معرّفُ الموكّل لا اسمُه مفتاحَ الصفّ: اسمان متطابقان صفٌّ واحدٌ كاذب في الأعمار
                'id' => $key,
                'client' => Ticket::maskClient($invoice->user?->name ?? ''),
                'b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0,
                'notYetDue' => 0, 'overdue' => 0, 'overdueCount' => 0,
                'total' => 0, 'count' => 0, 'lastReminder' => null, 'at' => null,
            ];

            $amount = (int) $invoice->amount;
            $byClient[$key]['total'] += $amount;
            $byClient[$key]['count']++;

            $bucket = self::bucketOf($invoice->due_at, $today);
            $byClient[$key][$bucket] += $amount;

            if ($bucket !== 'notYetDue') {
                $byClient[$key]['overdue'] += $amount;
                $byClient[$key]['overdueCount']++;
            }

            // آخر تذكيرٍ أُرسل للموكّل — أحدثُ `reminder_sent_at` على فواتيره القائمة.
            // العمود يُكتب منذ زمنٍ **ولا تقرؤه شاشة** (ب٧)، وهذا أوّل موضعٍ يعرضه.
            $reminder = $invoice->reminder_sent_at;
            if ($reminder !== null && ($byClient[$key]['at'] === null || $reminder->gt($byClient[$key]['at']))) {
                $byClient[$key]['at'] = $reminder;
                $byClient[$key]['lastReminder'] = self::dateText($reminder);
            }
        }

        $out = [];
        foreach ($byClient as $row) {
            unset($row['at']); // مساعدُ المقارنة لا يُرسَل للواجهة
            $out[] = $row;
        }

        usort($out, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return $out;
    }

    /**
     * **شريحة العمر لفاتورةٍ واحدة** — المصدر الواحد لحدود الشرائح.
     *
     * فاتورةٌ بلا تاريخ استحقاق لا تتأخّر (‏`Invoice::isOverdue` يشترط وجوده)، فموضعُها
     * «لم يحلّ أجلها» — وإدراجُها في شريحةٍ يجعل المكتب يلاحق مبلغاً لم يَعِد أحدٌ بموعده.
     */
    public static function bucketOf(?CarbonInterface $dueAt, ?CarbonInterface $today = null): string
    {
        if ($dueAt === null) {
            return 'notYetDue';
        }

        $today ??= now()->startOfDay();

        // الشرط نفسه في `Invoice::isOverdue`: يوم الاستحقاق ليس تأخّراً حتى نهايته
        if (! $dueAt->copy()->endOfDay()->isPast()) {
            return 'notYetDue';
        }

        $days = (int) abs($today->diffInDays($dueAt->copy()->startOfDay()));

        foreach (self::AGING_BUCKETS as $bucket) {
            if ($days >= $bucket['from'] && ($bucket['to'] === null || $days <= $bucket['to'])) {
                return $bucket['k'];
            }
        }

        return 'b4';
    }

    // ─────────────────────────────── ٥. الضريبة ───────────────────────────────

    /**
     * **إقرارُ الفترة: أساسٌ وضريبةٌ وإجماليّ، شهراً شهراً وللفترة كلّها.**
     *
     * المرجع `paid_at` (الاعتراف عند التحصيل — ق٧)، فالملغاة والمعدومة خارجه **بحكم البناء**
     * لا بشرطٍ يُكتب: `paid` لا يكون صحيحاً على أيٍّ منهما (`CancelInvoice` و`WriteOffInvoice`
     * يردّان المدفوعة، و`RefundInvoice` يمحو `paid` و`paid_at` معاً).
     *
     * **والتجميع الشهريّ في PHP** لا بدالّة تاريخٍ في SQL: `strftime` و`DATE_FORMAT` لا
     * تتشابهان، وإقرارٌ ضريبيّ ليس موضعَ اعتمادٍ على سائق القاعدة.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return array{total:int, vat:int, subtotal:int, count:int, months:list<array{m:string, label:string, subtotal:int, vat:int, total:int, count:int}>}
     */
    public static function vat(array $period): array
    {
        $totals = RevenueSnapshot::collectedBetween($period['from'], $period['to']);

        $months = [];
        Invoice::where('paid', true)
            ->whereBetween('paid_at', [$period['from'], $period['to']])
            ->orderBy('paid_at')
            ->get(['id', 'amount', 'vat_amount', 'paid_at'])
            ->each(function (Invoice $invoice) use (&$months) {
                $key = $invoice->paid_at->format('Y-m');
                $months[$key] ??= [
                    'm' => $key,
                    'label' => $invoice->paid_at->locale('ar')->translatedFormat('F Y'),
                    'subtotal' => 0, 'vat' => 0, 'total' => 0, 'count' => 0,
                ];

                $amount = (int) $invoice->amount;
                // الضريبة قد تكون فارغةً على صفٍّ قديم لم تبلغه التعبئة الرجعيّة — صفرٌ أصدق
                // من عكس حسابٍ يخترع رقماً في إقرار
                $vat = (int) ($invoice->vat_amount ?? 0);

                $months[$key]['total'] += $amount;
                $months[$key]['vat'] += $vat;
                // الأساس بالطرح لا بجمعٍ ثانٍ — القاعدة نفسها في `RevenueSnapshot::collectedBetween`
                $months[$key]['subtotal'] += $amount - $vat;
                $months[$key]['count']++;
            });

        return $totals + ['months' => array_values($months)];
    }

    /**
     * الفواتير المكوِّنة لإقرار الفترة — مرقَّمة.
     *
     * @param  array{from:CarbonInterface, to:CarbonInterface, ...}  $period
     * @return LengthAwarePaginator<int, Invoice>
     */
    public static function vatInvoices(array $period): LengthAwarePaginator
    {
        return Invoice::with('user')
            ->where('paid', true)
            ->whereBetween('paid_at', [$period['from'], $period['to']])
            ->latest('paid_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    // ─────────────────────────────── مشتركات ───────────────────────────────

    /**
     * صفّ الفاتورة كما تعرضه جداول هذه الشاشة — بطاقةُ العميل نفسها زائدَ ما تحتاجه الإدارة.
     *
     * `toCard()` هو المصدر الواحد لحالة الفاتورة ولونها ونصّ استحقاقها؛ وما يُضاف هنا هو ما
     * لا يراه العميل: اسمُ الموكّل، وتفصيلُ الضريبة، وتاريخا الإصدار والتحصيل.
     *
     * @param  list<string>  $allowed  أسماء الانتقالات المتاحة الآن (‏`Workflow::allowed`)
     */
    public static function invoiceRow(Invoice $invoice, array $allowed = []): array
    {
        $tax = $invoice->taxBreakdown();

        return $invoice->toCard() + [
            'client' => Ticket::maskClient($invoice->user?->name ?? ''),
            'kind' => self::kindOf($invoice),
            'subtotal' => $tax['subtotal'],
            'vat' => $tax['vat_amount'],
            'vatRate' => $tax['vat_rate'],
            'issuedAt' => self::dateText($invoice->issued_at ?? $invoice->created_at),
            'paidAt' => self::dateText($invoice->paid_at),
            'writtenOffReason' => $invoice->written_off_reason,
            'can' => $allowed,
        ];
    }

    /** نوع الفاتورة من رابطها — الأولويّة نفسها في `RevenueSnapshot::incomeSplit` فلا يختلف تصنيفان. */
    public static function kindOf(Invoice $invoice): string
    {
        return match (true) {
            $invoice->consult_id !== null => 'استشارة',
            $invoice->case_id !== null => 'قضية',
            $invoice->exec_id !== null => 'تنفيذ',
            default => 'أخرى',
        };
    }

    /**
     * تاريخ الإصدار الفعليّ: `issued_at` وإلّا `created_at`.
     *
     * صفوفٌ سبقت م١ قد تصل بلا `issued_at`، ولو رُشّحت بعمودٍ فارغ لاختفت من كلّ فترة —
     * أي لاختفت من الشاشة كلّها بلا رسالة.
     */
    private const ISSUED_AT = 'COALESCE(issued_at, created_at)';

    /** تاريخ القبض: `reconciled_at` وإلّا `created_at` (صفوفٌ قديمة قد تصل بلا تسوية). */
    private const RECEIVED_AT = 'COALESCE(received_at, reconciled_at, created_at)';

    /** @param  Builder<Invoice>  $q */
    private static function scopeKind(Builder $q, string $kind): void
    {
        match ($kind) {
            'consult' => $q->whereNotNull('consult_id'),
            'case' => $q->whereNull('consult_id')->whereNotNull('case_id'),
            'exec' => $q->whereNull('consult_id')->whereNull('case_id')->whereNotNull('exec_id'),
            default => null,
        };
    }

    private static function dateText(?CarbonInterface $at): ?string
    {
        return $at?->locale('ar')->translatedFormat('d F Y');
    }

    private static function parseDate(?string $value): ?CarbonInterface
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Date::parse($value);
        } catch (\Throwable) {
            return null; // تاريخٌ لا يُقرأ — تُعامَل الفترة كأنّها لم تُحدَّد
        }
    }

    /** @return array{key:string, from:CarbonInterface, to:CarbonInterface, label:string, fromDate:string, toDate:string} */
    private static function shape(string $key, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'key' => $key,
            'from' => $from,
            'to' => $to,
            'label' => $from->locale('ar')->translatedFormat('d F Y').' — '.$to->locale('ar')->translatedFormat('d F Y'),
            // بصيغة حقل التاريخ في الواجهة، كي يعيدها المدى المخصّص كما هي
            'fromDate' => $from->toDateString(),
            'toDate' => $to->toDateString(),
        ];
    }
}
