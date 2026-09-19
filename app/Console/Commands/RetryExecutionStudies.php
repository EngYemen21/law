<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeExecutionJob;
use App\Models\Execution;
use Illuminate\Console\Command;

/**
 * **شبكة الأمان لدراسة التنفيذ** (قرار المالك 2026-09-12: يُعاد جدولتها، ولا قالب يملأ الفراغ).
 *
 * `AnalyzeExecutionJob` يعيد المحاولة مرّتين ثمّ يجدول واحدةً بعد ساعة؛ وما سقط بعدها —
 * مهمّةٌ ماتت قبل أن تصل `failed()`، أو ملفٌّ أُنشئ والطابور متوقّف — يلتقطه هذا الأمر.
 * لا يُعيد لملفٍّ نجحت دراسته (`ai_done`) ولا لمنتهٍ ولا لمن بلغ سقف المحاولات.
 */
class RetryExecutionStudies extends Command
{
    protected $signature = 'exec:retry-study';

    protected $description = 'إعادة جدولة دراسة التنفيذ للملفّات التي تعذّرت دراستها';

    public function handle(): int
    {
        $pending = Execution::where('ai_done', false)
            ->whereNull('ai_study')
            ->where('ai_attempts', '<', AnalyzeExecutionJob::MAX_ATTEMPTS)
            // مهلةٌ بين المحاولات: تهدئة المزوّد عند 429 تُقاس بالساعات
            ->where(fn ($q) => $q->whereNull('ai_attempted_at')->orWhere('ai_attempted_at', '<=', now()->subHour()))
            ->get()
            // الملفّ المنتهي مستثنى — والحالات القديمة تُقرأ من `isClosed` لا من عمودٍ واحد.
            // **والمرفوض معه**: قرارُه أُغلق ومرحلته لم تتحرّك، فكان يُلتقط هنا كلّ ساعة
            // فيُعاد دَرْسه ويصل العميلَ «قيد الدراسة» بعد بريد الرفض.
            ->reject(fn (Execution $e) => $e->isClosed() || $e->decision === 'مرفوض');

        foreach ($pending as $exec) {
            AnalyzeExecutionJob::dispatch($exec);
        }

        $this->info('أُعيدت جدولة '.$pending->count().' دراسة تنفيذ.');

        return self::SUCCESS;
    }
}
