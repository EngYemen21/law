<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Consult;
use App\Models\User;
use App\Support\DayRange;
use App\Support\Paginate;
use App\Support\SearchText;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as FacadeResponse;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        // إذا كان الجدول جديداً، نملأه بقيود التدقيق الفعلية الموجودة مسبقاً في الاستشارات
        $this->ensureInitialHistoricalLogs();

        $query = $this->filtered($request)->with('user');

        // **تصفيحٌ حقيقيّ لا سقفٌ صامت.** كان `limit(200)` بلا مؤشّرٍ ولا صفحات: يرشّح
        // المدير فيرى نتيجةً تبدو تامّة، والقيد الذي يبحث عنه في الصفّ الخمسمئة. والشاشة
        // كانت تُعيد ترشيح الـ٢٠٠ في المتصفّح لحظياً بينما الترشيح الخادميّ ينتظر «تطبيق»،
        // فمرشّحان على مجموعتين مختلفتين. الآن الخادم وحده يرشّح، والترقيم يحمل المرشّحات.
        $logs = $query->paginate(50)->withQueryString();

        // إحصائيات لوحة القيادة الحية
        $today = Carbon::today();
        // ما تعرضه الشاشة وحده — كانت `consults` و`security` تُحسبان في كلّ فتحٍ ولا يقرؤهما أحد.
        // و`today` منذ بداية اليوم و`activeActors` آخر ٢٤ ساعة: عنواناهما في الشاشة يقولان ذلك نصّاً.
        $stats = [
            'total' => AuditLog::count(),
            'today' => DayRange::on(AuditLog::query(), 'created_at', $today->toImmutable())->count(),
            'critical' => AuditLog::whereIn('severity', ['warning', 'critical'])->count(),
            'financial' => AuditLog::where('category', 'مالية وفواتير')->count(),
            'activeActors' => AuditLog::where('created_at', '>=', now()->subHours(24))->distinct('user_name')->count('user_name'),
        ];

        // قوائم الفلاتر الديناميكية
        // **الخيارات من الصفوف لا مكتوبةً باليد.** كان الجدولُ الفارغ يُحقن بسبع فئاتٍ ثابتة،
        // فتُعرض سبعةُ خيارات لا يطابق أيٌّ منها صفّاً — وهو بعينه ما أُصلح في مرشّح الأولويّة.
        $categories = AuditLog::distinct('category')->pluck('category')->filter()->values()->all();

        $actors = AuditLog::distinct('user_name')->pluck('user_name')->filter()->values()->all();

        return Inertia::render('admin/audit-logs', [
            'logs' => Paginate::shape($logs, fn (AuditLog $log) => $log->toCard()),
            'stats' => $stats,
            'categories' => $categories,
            'actors' => $actors,
            'severityOptions' => AuditLog::severityOptions(),
            'filters' => [
                'search' => $request->input('search', ''),
                'category' => $request->input('category', 'all'),
                'severity' => $request->input('severity', 'all'),
                'user' => $request->input('user', 'all'),
                'from_date' => $request->input('from_date', ''),
                'to_date' => $request->input('to_date', ''),
            ],
        ]);
    }

    /**
     * **التصدير بمرشّحات الشاشة نفسها وبلا سقفٍ صامت.**
     *
     * كان يُصدّر أحدث ١٠٠٠ قيدٍ أيّاً كانت المرشّحات: يرشّح المدير قيود «مالية» لشهرٍ مضى فيستلم
     * ملفّاً لا يحويها، ولا يعلم أنّ الملفّ مقطوع. الآن الاستعلام واحد (`filtered`) للعرض والتصدير،
     * والصفوف تُقرأ دفعاتٍ (`lazyByIdDesc`) فيُكتب السجلّ كلّه دون تحميله في الذاكرة.
     */
    public function export(Request $request)
    {
        $query = $this->filtered($request);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit_logs_'.date('Y-m-d_His').'.csv"',
        ];

        $callback = function () use ($query) {
            $output = fopen('php://output', 'w');
            // BOM for UTF-8 Excel support
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['المعرف', 'المستخدم', 'الدور', 'نوع الإجراء', 'الفئة', 'المرجع', 'الوصف', 'عنوان IP', 'الأهمية', 'التاريخ والوقت']);

            foreach ($query->lazyByIdDesc(500) as $l) {
                fputcsv($output, [
                    $l->id,
                    $l->user_name,
                    AuditLog::roleLabelOf($l->user_role),
                    $l->action,
                    $l->category,
                    $l->auditable_ref ?? '—',
                    $l->description,
                    $l->ip_address,
                    AuditLog::SEVERITY_LABELS[$l->severity] ?? $l->severity,
                    $l->created_at ? $l->created_at->format('Y-m-d H:i:s') : '—',
                ]);
            }

            fclose($output);
        };

        return FacadeResponse::stream($callback, 200, $headers);
    }

    /**
     * **المرشّحات في موضعٍ واحد** — يقرؤها العرض والتصدير معاً، فلا يُصدَّر غيرُ ما يُرى.
     *
     * @return Builder<AuditLog>
     */
    private function filtered(Request $request): Builder
    {
        $query = AuditLog::query()->latest('id');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                SearchText::apply($q, ['action', 'description', 'user_name', 'auditable_ref', 'ip_address'], $search);
            });
        }

        foreach (['category' => 'category', 'severity' => 'severity', 'user' => 'user_name'] as $param => $column) {
            $value = $request->input($param);
            if ($value && $value !== 'all') {
                $query->where($column, $value);
            }
        }

        DayRange::apply($query, 'created_at', $request->input('from_date'), $request->input('to_date'));

        return $query;
    }

    /**
     * ملء سجلات أولية حقيقية من البيانات التشغيلية الموجودة مسبقاً في النظام
     */
    private function ensureInitialHistoricalLogs(): void
    {
        if (AuditLog::count() > 0) {
            return;
        }

        // خريطة اسم→دور من المستخدمين الحقيقيين — لا تخمين للأدوار من شكل الاسم
        $rolesByName = User::pluck('role', 'name')->map(fn ($r) => $r->value ?? (string) $r);

        // 1) استيراد سجلات تدقيق الاستشارات الموجودة (بيانات حقيقية مخزنة في عمود audit)
        // ملاحظة: IP وUser-Agent لم يُخزَّنا وقتها فيبقيان فارغين ويُعرضان «—» — لا قيم مختلقة
        $consults = Consult::whereNotNull('audit')->get();
        foreach ($consults as $consult) {
            if (! is_array($consult->audit)) {
                continue;
            }
            foreach ($consult->audit as $entry) {
                $userName = $entry['user'] ?? $entry['by'] ?? 'النظام';
                $field = $entry['field'] ?? $entry['a'] ?? 'تحديث استشارة';
                $before = $entry['before'] ?? null;
                $after = $entry['after'] ?? null;

                $sev = ($field === 'التسعير' || str_contains($field, 'إلغاء')) ? 'warning' : 'info';

                AuditLog::create([
                    'user_name' => $userName,
                    'user_role' => $rolesByName[$userName] ?? 'system',
                    'action' => 'إجراء على الاستشارة: '.$field.' (مستورد من سجل الاستشارة)',
                    'category' => 'استشارات',
                    'auditable_type' => Consult::class,
                    'auditable_id' => $consult->id,
                    'auditable_ref' => $consult->ref,
                    'description' => "تم إجراء تعديل على حقل «{$field}» في الاستشارة {$consult->ref} بواسطة {$userName}.",
                    'before_state' => $before ? ['قيمة سابقة' => $before] : null,
                    'after_state' => $after ? ['قيمة جديدة' => $after] : null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'severity' => $sev,
                    'created_at' => $consult->created_at ?? now(),
                    'updated_at' => $consult->created_at ?? now(),
                ]);
            }
        }

        // عُلّق بموجب قاعدة المنتج الملزمة «لا بيانات مختلقة تُعرض أبدًا» (2026-08-28):
        // كان يختلق «تسجيل دخول ناجح» لكل مستخدم بعنوان IP عشوائي وأوقات عشوائية
        // ومتصفح مزيف — في سجل تدقيق أمني يُعتمد عليه للامتثال. عمليات الدخول الحقيقية
        // تُسجَّل الآن لحظيًا من AuthController عبر AuditLog::record.
        // (كان الاستيفاء {$u->role} هنا يرمي 500 أيضًا لأن الدور Enum لا يُستوفى نصًا.)
        // $users = User::all();
        // foreach ($users as $u) {
        //     AuditLog::create([
        //         'user_id' => $u->id,
        //         'user_name' => $u->name,
        //         'user_role' => $u->role ?? 'user',
        //         'action' => 'تسجيل دخول للنظام',
        //         'category' => 'أمن وحماية',
        //         'auditable_type' => User::class,
        //         'auditable_id' => $u->id,
        //         'auditable_ref' => $u->email,
        //         'description' => "قام المستخدم {$u->name} بتسجيل الدخول إلى لوحة التحكم بنجاح.",
        //         'ip_address' => '192.168.1.' . rand(10, 99),
        //         'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        //         'severity' => 'info',
        //         'created_at' => now()->subHours(rand(1, 48)),
        //         'updated_at' => now(),
        //     ]);
        // }
    }
}
