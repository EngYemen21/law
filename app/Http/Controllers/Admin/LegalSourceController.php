<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalSource;
use App\Support\Audit;
use App\Support\SearchText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * اعتماد المصادر القانونيّة — الحلقة التي كانت مفقودة.
 *
 * `ai:import-sources` يُدخل كل مادّة بحالة «مسودة» عمداً: الاعتماد فعلٌ بشريّ لا
 * يُمنح بتشغيل أمر في الطرفيّة. لكن **لم يكن ثمّة مسار للاعتماد أصلاً**، فالمصادر
 * تدخل إلى طريق مسدود ولا يُستشهد بأيّها أبداً (`LegalKnowledge` يرشّح بـapproved).
 *
 * الشاشة تعرض النصّ كاملاً مع بياناته الحاكمة — فالمحامي يعتمد ما قرأه لا ما
 * أُخبِر عنه.
 */
class LegalSourceController extends Controller
{
    /** مادّة واحدة تحمل نصّاً نظامياً كاملاً؛ صفحةٌ أطول تصير غير قابلة للقراءة. */
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = [
            'system' => trim((string) $request->query('system', '')),
            'status' => trim((string) $request->query('status', '')),
            'q' => trim((string) $request->query('q', '')),
        ];

        $query = LegalSource::with('reviewer')
            // الترتيب بثوابت النموذج لا بنصوصٍ منقوشة في SQL — المسودّات أوّلاً (هي ما ينتظر قراراً)
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [LegalSource::STATUS_DRAFT, LegalSource::STATUS_APPROVED])
            ->orderBy('system_name')
            ->orderBy('id');

        if ($filters['system'] !== '') {
            $query->where('system_name', $filters['system']);
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        // البحث في النصّ نفسه لا في عنوانه وحده: المحامي يبحث عن حكمٍ لا عن ترقيم
        if ($filters['q'] !== '') {
            SearchText::apply($query, ['text', 'ref', 'article_no', 'title'], (string) $filters['q']);
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('admin/legal-sources', [
            'filters' => $filters,
            'sources' => collect($page->items())->map(fn (LegalSource $s) => [
                'id' => $s->id,
                'ref' => $s->ref,
                'systemName' => $s->system_name,
                'articleNo' => $s->article_no,
                'title' => $s->title,
                'text' => $s->text,
                'citation' => $s->citation(),
                'jurisdiction' => $s->jurisdiction,
                'domain' => $s->domain ?? '— عامّ —',
                'version' => $s->version,
                'effectiveFrom' => $s->effective_from?->format('Y-m-d'),
                'effectiveTo' => $s->effective_to?->format('Y-m-d'),
                // نصٌّ لم يبدأ سريانه لا يُسترجَع اليوم مهما اعتُمد — يُقال صراحةً
                'inForce' => $s->effective_from === null || ! $s->effective_from->isAfter(now()),
                'sourceOwner' => $s->source_owner,
                'sourceUrl' => $s->source_url,
                'usageScope' => $s->usage_scope,
                'status' => $s->status,
                // النبرة والأفعال من النموذج — الشاشة لا تقارن نصّ الحالة
                'statusTone' => $s->statusTone(),
                'canApprove' => $s->canApprove(),
                'canSuspend' => $s->canSuspend(),
                'reviewedBy' => $s->reviewer?->name,
                'legalReviewAt' => $s->legal_review_at?->format('Y-m-d'),
            ])->values(),
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'systems' => $this->systemRows(),
            'statusOptions' => LegalSource::statusOptions(),
            'stats' => [
                'draft' => LegalSource::where('status', LegalSource::STATUS_DRAFT)->count(),
                'approved' => LegalSource::where('status', LegalSource::STATUS_APPROVED)->count(),
                'suspended' => LegalSource::where('status', LegalSource::STATUS_SUSPENDED)->count(),
            ],
        ]);
    }

    /**
     * الاعتماد يكتب الحالة والمُعتمِد وتاريخ مراجعته **معاً** — لا اعتماد مجهول
     * صاحبه: أثرُ المراجعة القانونيّة جزء من المصدر لا بيانات جانبيّة.
     */
    public function approve(Request $request, LegalSource $source): RedirectResponse
    {
        $source->update([
            'status' => LegalSource::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'legal_review_at' => now()->toDateString(),
        ]);

        Audit::log(
            action: 'اعتماد مصدر قانونيّ',
            description: "اعتُمد {$source->citation()} ({$source->ref}) — صار قابلاً للاستشهاد.",
            category: 'المساعد القانوني',
            auditable: $source,
            auditableRef: $source->ref,
        );

        return back()->with('flash', "اعتُمد المصدر {$source->ref} — صار قابلاً للاستشهاد.");
    }

    /**
     * اعتماد نظامٍ كاملٍ دفعةً واحدة.
     *
     * نظامٌ من مئات المواد لا يُعتمد بمئات النقرات — والاعتماد هنا فعلٌ قانونيّ
     * حقيقيّ: «أشهد أن هذا النصّ الحرفيّ المنشور في الجريدة الرسميّة صالحٌ
     * للاستشهاد». ولأنه واسع الأثر يلزمه تأكيدٌ صريح باسم النظام (لا زرّ واحد
     * يُضغط سهواً)، ويُسجَّل في سجلّ التدقيق بعدد ما اعتُمد.
     */
    public function approveSystem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'system' => ['required', 'string'],
            'confirm' => ['required', 'string'],
        ]);

        if ($data['confirm'] !== $data['system']) {
            return back()->withErrors(['confirm' => 'اكتب اسم النظام حرفياً لتأكيد اعتماده كاملاً.']);
        }

        $pending = LegalSource::where('system_name', $data['system'])
            ->where('status', LegalSource::STATUS_DRAFT);

        $count = (clone $pending)->count();

        if ($count === 0) {
            return back()->withErrors(['system' => 'لا مسودّات في هذا النظام.']);
        }

        $pending->update([
            'status' => LegalSource::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'legal_review_at' => now()->toDateString(),
        ]);

        Audit::log(
            action: 'اعتماد نظام قانونيّ كامل',
            description: "اعتُمدت {$count} مادّة من «{$data['system']}» دفعةً واحدة — صارت قابلة للاستشهاد.",
            category: 'المساعد القانوني',
            severity: 'warning',
            auditableRef: $data['system'],
        );

        return back()->with('flash', "اعتُمدت {$count} مادّة من «{$data['system']}».");
    }

    /**
     * الإيقاف يُخرج المصدر من الاسترجاع فوراً **دون حذف**: نصٌّ نُسخ أو عُدّل يجب
     * أن يتوقّف الاستشهاد به، ويبقى القيد لتدقيق ما استُشهد به سابقاً.
     */
    public function suspend(Request $request, LegalSource $source): RedirectResponse
    {
        $source->update([
            'status' => LegalSource::STATUS_SUSPENDED,
            'reviewed_by' => $request->user()->id,
            'legal_review_at' => now()->toDateString(),
        ]);

        Audit::log(
            action: 'إيقاف مصدر قانونيّ',
            description: "أُوقف {$source->citation()} ({$source->ref}) — لن يُستشهد به بعد الآن.",
            category: 'المساعد القانوني',
            severity: 'warning',
            auditable: $source,
            auditableRef: $source->ref,
        );

        return back()->with('flash', "أُوقف المصدر {$source->ref} — لن يُستشهد به بعد الآن.");
    }

    /**
     * النظر إلى القاعدة كأنظمة لا كمواد متفرّقة: المحامي يقرّر على مستوى النظام،
     * وهذه الصفوف تُريه أين يقف كلٌّ منها قبل أن يفتح مادّة واحدة.
     *
     * @return array<int, array{name:string,total:int,draft:int,approved:int,suspended:int,effectiveFrom:string|null,inForce:bool}>
     */
    private function systemRows(): array
    {
        return LegalSource::query()
            ->selectRaw('system_name, COUNT(*) total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) draft', [LegalSource::STATUS_DRAFT])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) approved', [LegalSource::STATUS_APPROVED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) suspended', [LegalSource::STATUS_SUSPENDED])
            ->selectRaw('MIN(effective_from) effective_from')
            ->groupBy('system_name')
            ->orderBy('system_name')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->system_name,
                'total' => (int) $row->total,
                'draft' => (int) $row->draft,
                'approved' => (int) $row->approved,
                'suspended' => (int) $row->suspended,
                'effectiveFrom' => $row->effective_from ? substr((string) $row->effective_from, 0, 10) : null,
                // اعتمادُ نظامٍ لم يبدأ سريانه صحيحٌ ولا يُنتج استشهاداً اليوم؛ يُقال لا يُخفى
                'inForce' => $row->effective_from === null || substr((string) $row->effective_from, 0, 10) <= now()->toDateString(),
            ])
            ->all();
    }
}
