<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalSource;
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
    public function index(): Response
    {
        return Inertia::render('admin/legal-sources', [
            'sources' => LegalSource::with('reviewer')
                ->orderByRaw("CASE status WHEN 'مسودة' THEN 0 WHEN 'معتمد' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->get()
                ->map(fn (LegalSource $s) => [
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
                    'sourceOwner' => $s->source_owner,
                    'sourceUrl' => $s->source_url,
                    'usageScope' => $s->usage_scope,
                    'status' => $s->status,
                    'reviewedBy' => $s->reviewer?->name,
                    'legalReviewAt' => $s->legal_review_at?->format('Y-m-d'),
                ])->values(),
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

        return back()->with('flash', "اعتُمد المصدر {$source->ref} — صار قابلاً للاستشهاد.");
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

        return back()->with('flash', "أُوقف المصدر {$source->ref} — لن يُستشهد به بعد الآن.");
    }
}
