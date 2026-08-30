<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * البوّابة الموحَّدة لنداء نماذج اللغة — نقطة الاختناق الوحيدة لكل استدعاء.
 *
 * كان ترتيب المزوّدين وقاطع الدائرة والقياس مبعثراً داخل `LegalAiService::run()`
 * التي تعيد `?string` مجرّداً، فلا يبقى أثر لأيّ نداء: أيّ مزوّد أجاب، كم استغرق،
 * ولماذا فشل. البوّابة تحتفظ بالمنطق نفسه حرفياً وتضيف التتبّع والقياس.
 *
 * ما لا تفعله عمداً: **لا تكتب في `ai_runs`**. طبقة الأعمال وحدها تعرف نوع المهمّة
 * والكيان المرتبط ومصدر النتيجة النهائيّ، فهي من يسجّل — وإلّا لصار لكل عملية قيدان.
 */
class AiGateway
{
    /** أوّليّة المزوّدين: Gemini العامل، ثم GLM احتياطاً. */
    public const PROVIDERS = ['gemini', 'glm'];

    /**
     * ينادي المزوّدين بالترتيب حتى يجيب أحدهم. المحتوى نفسه (system/messages) يبقى
     * في يد المنادي عبر `$caller` — فالبوّابة تدير التسلسل والقياس لا صياغة الطلب.
     *
     * @param  callable(string): (string|null)  $caller  ينفّذ النداء لمزوّد بعينه ويعيد النصّ أو null
     */
    /**
     * استهلاك آخر نداء — يضعه المنادي عبر `recordUsage` قبل أن يعود.
     * صالح للنداء السابق مباشرةً فقط؛ البوّابة تقرؤه فور عودة المزوّد.
     */
    private ?AiUsage $lastUsage = null;

    /** يُبلِّغ البوّابة باستهلاك النداء الجاري — يناديه غلاف المزوّد. */
    public function recordUsage(?AiUsage $usage): void
    {
        $this->lastUsage = $usage;
    }

    public function call(callable $caller, ?string $traceId = null): AiCallResult
    {
        $this->lastUsage = null;

        $traceId ??= (string) Str::uuid();
        $startedAt = microtime(true);

        // الميزانيّة قبل المزوّدين: نداءٌ يتجاوز السقف لا يُرسَل أصلاً.
        // **الإيقاف مُطفأ افتراضياً** — تجاوز السقف بلا تفعيله يُنبِّه ولا يمنع، لأن
        // إيقاف معالجة الذكاء كلّها أثرٌ واسع لا يُفتَرض بالنيابة عن المكتب.
        // والسقوط هنا يعود بمخرج احتياطيّ موسوم كأيّ تعذُّر: العميل لا يرى شيئاً
        // تشغيلياً، والطاقم يقرأ السبب مميَّزاً عن عطل المزوّد.
        if (AiOpsMetrics::budgetStopsCalls()) {
            return new AiCallResult(
                text: null,
                traceId: $traceId,
                durationMs: self::elapsed($startedAt),
                failureCode: AiFailure::BUDGET_EXCEEDED,
            );
        }

        $available = array_values(array_filter(
            self::PROVIDERS,
            fn (string $p) => ! empty(config("services.{$p}.key")) && ! Cache::has("ai:cooldown:{$p}")
        ));

        if ($available === []) {
            return new AiCallResult(
                text: null,
                traceId: $traceId,
                durationMs: self::elapsed($startedAt),
                failureCode: AiFailure::PROVIDER_UNAVAILABLE,
            );
        }

        // كل مزوّد بمعزل: فشله (استثناء أو ردّ فارغ) ينتقل للتالي دون إجهاض السلسلة
        foreach ($available as $provider) {
            try {
                $text = $caller($provider);
                if ($text !== null && trim($text) !== '') {
                    return new AiCallResult(
                        text: $text,
                        traceId: $traceId,
                        durationMs: self::elapsed($startedAt),
                        provider: $provider,
                        model: (string) config("services.{$provider}.model"),
                        usage: $this->lastUsage,
                    );
                }
            } catch (\Throwable $e) {
                // بلا محتوى المدخلات: السبب و trace_id وحدهما مفتاح التشخيص
                Log::warning("[AiGateway] فشل المزوّد {$provider} (trace {$traceId}): ".$e->getMessage());
            }
        }

        return new AiCallResult(
            text: null,
            traceId: $traceId,
            durationMs: self::elapsed($startedAt),
            failureCode: AiFailure::PROVIDER_ERROR,
        );
    }

    /** هل من مزوّد قابل للنداء الآن؟ (مهيّأ وغير مهدَّأ) */
    public static function hasAvailableProvider(): bool
    {
        foreach (self::PROVIDERS as $provider) {
            if (! empty(config("services.{$provider}.key")) && ! Cache::has("ai:cooldown:{$provider}")) {
                return true;
            }
        }

        return false;
    }

    private static function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
