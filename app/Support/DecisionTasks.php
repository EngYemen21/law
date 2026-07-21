<?php

namespace App\Support;

use App\Models\Task;
use App\Models\User;
use App\Services\LegalAiService;
use Illuminate\Database\Eloquent\Model;

/**
 * تحويل قرارات الجلسة إلى مهام حقيقية (موديل Task) — مشترك بين الزرّ اليدوي (createTasks)
 * والمسار التلقائي بعد جلب ملخّص Zoom. idempotent عبر tasks_created.
 * يعمل على أي كيان لديه: decisions[]، zoom_summary، tasks_created، ref، assignedLawyer.
 */
class DecisionTasks
{
    /** ينشئ المهام ويعيد عددها (0 إن لا قرارات/لا مسؤول/سبق الإنشاء). */
    public static function create(Model $model, LegalAiService $ai, ?User $ownerFallback = null): int
    {
        if ($model->tasks_created) {
            return 0;
        }

        // القرارات المحفوظة، وإلا استخراجها من ملخّص Zoom
        // (الاستشارة تحفظه في summary، الاجتماع في zoom_summary)
        $decisions = $model->decisions ?? [];
        $summaryText = (string) ($model->zoom_summary ?? $model->summary ?? '');
        if ($decisions === [] && $summaryText !== '') {
            $decisions = $ai->extractDecisions($summaryText);
        }
        if ($decisions === []) {
            return 0;
        }

        // المسؤول: المحامي المسنَد (FK)، وإلا الاحتياط الممرّر (الفاعل/محامٍ من المشاركين)
        $owner = $model->assignedLawyer ?? $ownerFallback;
        if (! $owner) {
            return 0;
        }

        $ref = (string) ($model->ref ?: $model->getKey());
        foreach ($decisions as $title) {
            Task::create([
                'assigned_to' => $owner->id,
                'title' => (string) $title,
                'ref' => $ref,
                'due' => 'خلال أسبوع',
                'status' => 'مفتوحة',
                'tone' => 'b-amber',
            ]);
        }

        $model->update(['decisions' => array_values($decisions), 'tasks_created' => true]);

        return count($decisions);
    }
}
