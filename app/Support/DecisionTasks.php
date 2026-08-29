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
    /**
     * استخراج القرارات وحفظها **اقتراحاتٍ** بلا إنشاء مهامّ — مطلب المرحلة P3:
     * «اجعل قرارات الاجتماعات suggested_tasks أولاً؛ لا تنشئ Task فعليّة إلا بعد اعتماد».
     *
     * كان مسارا ملخّص Zoom (الاستشارة والاجتماع) يُنشئان مهامّ لدى المحامي **تلقائياً**
     * من نصٍّ استخرجه نموذج — أي أن مخرج نموذج كان يُنشئ التزاماً على إنسان بلا أن
     * يقرّه أحد. الاقتراح يُحفظ وينتظر زرّ الاعتماد القائم (`createTasks`).
     *
     * idempotent: لا يُعاد الاستخراج بعد اقتراحٍ أو إنشاءٍ سابق، فلا تتكرّر
     * الاقتراحات عند وصول ويبهوك ثانٍ أو إعادة تشغيل الوظيفة.
     */
    public static function suggest(Model $model, LegalAiService $ai): int
    {
        if ($model->tasks_created || ! empty($model->suggested_tasks)) {
            return 0;
        }

        $decisions = self::extract($model, $ai);
        if ($decisions === []) {
            return 0;
        }

        $model->update([
            'decisions' => array_values($decisions),
            'suggested_tasks' => array_values($decisions),
        ]);

        return count($decisions);
    }

    /**
     * القرارات المحفوظة، وإلا استخراجها من ملخّص Zoom.
     *
     * @return array<int, mixed>
     */
    private static function extract(Model $model, LegalAiService $ai): array
    {
        $decisions = $model->decisions ?? [];
        $summaryText = (string) ($model->zoom_summary ?? $model->summary ?? '');
        if ($decisions === [] && $summaryText !== '') {
            $decisions = $ai->extractDecisions($summaryText);
        }

        return $decisions;
    }

    public static function create(Model $model, LegalAiService $ai, ?User $ownerFallback = null): int
    {
        if ($model->tasks_created) {
            return 0;
        }

        // الاقتراحات المحفوظة أولاً (مسار الاعتماد)، وإلا الاستخراج المباشر
        // (الاستشارة تحفظ الملخّص في summary، والاجتماع في zoom_summary)
        $decisions = ! empty($model->suggested_tasks) ? $model->suggested_tasks : self::extract($model, $ai);
        if ($decisions === []) {
            return 0;
        }

        // المسؤول: المحامي المسنَد (FK)، وإلا الاحتياط الممرّر (الفاعل/محامٍ من المشاركين)
        $owner = $model->assignedLawyer ?? $ownerFallback;
        if (! $owner) {
            return 0;
        }

        $ref = (string) ($model->ref ?: $model->getKey());
        foreach ($decisions as $item) {
            $taskTitle = is_array($item) ? ($item['title'] ?? json_encode($item, JSON_UNESCAPED_UNICODE)) : (string) $item;
            if (trim($taskTitle) === '') {
                continue;
            }
            Task::create([
                'assigned_to' => $owner->id,
                'title' => $taskTitle,
                'ref' => $ref,
                'due' => 'خلال أسبوع',
                'due_at' => now()->addWeek()->toDateString(),
                'status' => 'مفتوحة',
                'tone' => 'b-amber',
            ]);
        }

        $model->update(['decisions' => array_values($decisions), 'tasks_created' => true]);

        return count($decisions);
    }
}
