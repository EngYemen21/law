<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * تشغيلٌ واحد لمجموعة التقييم — قيدٌ في خطّ الأساس.
 *
 * وجودُ أكثرَ من قيد هو ما يجعل السؤال «هل تراجعت الجودة؟» قابلاً للإجابة. القيد
 * الواحد يجيب «كم هي اليوم» فقط، وهو سؤالٌ آخر.
 */
class AiEvaluationRun extends Model
{
    protected $fillable = [
        'live', 'triggered_by', 'cost', 'live_calls',
        'results', 'prompt_versions', 'models',
    ];

    protected $casts = [
        'live' => 'boolean',
        'cost' => 'decimal:6',
        'live_calls' => 'integer',
        'results' => 'array',
        'prompt_versions' => 'array',
        'models' => 'array',
    ];

    /** حصيلة كل مهمّة بمعرّفها — لتيسير المقارنة بين تشغيلين. */
    public function byTask(): array
    {
        return array_column($this->results ?? [], null, 'task');
    }
}
