<?php

namespace App\Domain\Journey;

use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketSummary;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * **من يكتب الحالة خارج المحرّك؟** — الحارس الذي يجعل «الكاتب الوحيد» قاعدةً لا نيّة.
 *
 * يُنادى من `saving` على كيانات الرحلة. إن تغيّر عمود حالةٍ خارج `Workflow::run` حدّد
 * الملفَّ الكاتب من مكدّس النداء، ثمّ بحسب الوضع (`config('journey.guard')`):
 *   - `record`: يُسجّله في `storage/logs/journey-writers.log` — لجرد الكتّاب القدامى.
 *   - `throw`: يرفض الكتابة ما لم يكن الملفّ في `LEGACY_WRITERS`.
 *   - `off`: لا شيء (الإنتاج).
 *
 * **حدٌّ معلَن:** التحديث الجماعيّ عبر الاستعلام (`Model::where()->update()`) لا يُطلق أحداث
 * النموذج فلا يُرى هنا — ولذلك تُنقل تلك المواضع إلى المحرّك بالاسم في مراحلها.
 *
 * **واستثناءٌ رُفع في م٢ (2026-09-20).** كان هنا سطرٌ يعفي **كلّ فاتورةٍ ليست فاتورة استشارة**:
 * `if ($model instanceof Invoice && $model->consult_id === null) { return; }`. فسدادُ فواتير
 * القضايا والتنفيذ — وهي أكبر مبالغ المكتب — لم يكن يُسجَّل في `journey_transitions` أصلاً،
 * ولا أثرَ انتقالٍ له ولا تدقيق (ع٣ في خطّة النظام الماليّ). ولم يَعُد له موضع: كلُّ إصدارٍ
 * يمرّ بـ`Finance\InvoiceFactory` (‏`Workflow::open`)، وكلُّ سدادٍ وإلغاءٍ بانتقالٍ في
 * `Transitions/Invoice`.
 */
final class StateWriteGuard
{
    /** @var array<class-string<Model>, list<string>> */
    public const WATCHED = [
        Ticket::class => ['status'],
        Consult::class => ['status', 'session', 'summary_approved_at'],
        Appointment::class => ['status'],
        Invoice::class => ['status', 'paid'],
        TicketSummary::class => ['status', 'result_status'],
        LegalCase::class => ['status'],
        Execution::class => ['status', 'stage'],
    ];

    /**
     * الملفّات المعفاة من الحارس لأنّها تكتب الحالة خارج المحرّك — **فارغةٌ منذ 2026-09-19.**
     *
     * كانت 25 ملفّاً (جرد 2026-09-14)، صُحّحت من جردٍ حيّ في 2026-09-18، ثمّ نُقل كلُّ كاتبٍ إلى
     * انتقالٍ أو إلى `Workflow::open` (للإنشاء). فكلُّ كتابةٍ لعمودٍ مراقَب تمرّ الآن بالمحرّك،
     * وأيُّ كاتبٍ جديد خارجه يُسقط الاختبارات (`JOURNEY_GUARD=throw` في phpunit.xml).
     *
     * **لا يُضاف بندٌ إلّا لضرورةٍ مؤقّتة معلَّلة** — يحرسه `LegacyWritersListTest`.
     *
     * @var list<string>
     */
    public const LEGACY_WRITERS = [];

    public static function inspect(Model $model): void
    {
        $mode = (string) config('journey.guard', 'off');
        if ($mode === 'off' || Workflow::running()) {
            return;
        }

        $dirty = array_values(array_intersect(array_keys($model->getDirty()), self::WATCHED[$model::class] ?? []));
        if ($dirty === []) {
            return;
        }

        $writer = self::writer(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60));
        if ($writer === null) {
            return;
        }

        $line = $writer.' | '.class_basename($model).' | '.implode(',', $dirty).' | '.($model->exists ? 'update' : 'create');

        if (in_array($writer, self::LEGACY_WRITERS, true)) {
            // المدرج معفى — ويُسجَّل في الجرد وحده (`journey.record_legacy`) ليُعرف أيّ بندٍ ما زال حيّاً
            if ($mode === 'record' && config('journey.record_legacy')) {
                @file_put_contents(storage_path('logs/journey-writers.log'), '[legacy] '.$line.PHP_EOL, FILE_APPEND | LOCK_EX);
            }

            return;
        }

        if ($mode === 'record') {
            @file_put_contents(storage_path('logs/journey-writers.log'), $line.PHP_EOL, FILE_APPEND | LOCK_EX);

            return;
        }

        throw new LogicException("كتابة حالة رحلة خارج Workflow: {$line}");
    }

    /**
     * أوّل ملفٍّ من كود التطبيق في المكدّس — متجاوزاً المكتبات والنماذج والمحرّك نفسه.
     * `null` حين يكون الكاتب خارج `app/` (الاختبارات والبذور): تلك تُهيّئ حالاتٍ لا تنتقل بها.
     *
     * @param  array<int, array<string, mixed>>  $trace
     */
    public static function writer(array $trace, ?string $base = null): ?string
    {
        $base = rtrim(str_replace('\\', '/', $base ?? base_path()), '/').'/';

        foreach ($trace as $frame) {
            if (! isset($frame['file']) || ! is_string($frame['file'])) {
                continue;
            }

            $file = str_replace('\\', '/', $frame['file']);
            if (! str_starts_with($file, $base)) {
                continue;
            }

            $relative = substr($file, strlen($base));

            if (str_starts_with($relative, 'vendor/')
                || str_starts_with($relative, 'app/Models/')
                || str_starts_with($relative, 'app/Domain/Journey/')) {
                continue;
            }

            return str_starts_with($relative, 'app/') ? $relative : null;
        }

        return null;
    }
}
