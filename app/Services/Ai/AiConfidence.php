<?php

namespace App\Services\Ai;

/**
 * ثقة مشتقّة **خادمياً** من إشارات موضوعيّة — لا من ادّعاء النموذج عن مخرجه.
 *
 * الخيار المرفوض: أن نطلب من النموذج `confidence` ضمن الـJSON. تلك ثقةٌ يصرّح بها
 * المُنتِج عن إنتاجه، أي **ادّعاء لا قياس** — وهي بالضبط صنف البيانات غير المحقّقة
 * الذي أُزيل في P0 وP1 (نموذج مخمَّن، سبب فشل مفترض، ترشيح محامٍ بلا أساس).
 *
 * المعتمد: كل إشارة **قابلة للفحص من بيانات الخادم وحدها** — هل أُرفقت مستندات؟
 * هل أمكن استخراج نصّها فعلاً؟ أهو محامٍ من القائمة الحقيقيّة؟ — ولكلٍّ وزن معلن،
 * ومجموع الأوزان 100. تُخزَّن الإشارات مع الدرجة في `ai_runs.confidence_signals`
 * فتصير الدرجة **قابلة للتدقيق**: يُعرف *لماذا* كانت 45 لا 80.
 *
 * `null` تعني «لا قياس»: لم يجرِ تحليل أصلاً (احتياطيّ/فشل). ولا تعني «ثقة منخفضة»
 * — فالمنخفضة رقمٌ صغير مع إشاراته، والفارق بينهما جوهريّ في المراجعة.
 *
 * ── حدّ هذه الدرجة، مُعلَناً ──
 *
 * **تقيس اكتمال المدخلات لا صحّة المخرج.** `forExecution` تسأل: أرُفعت مستندات؟
 * أأمكن قراءتها؟ أذُكر المنفَّذ ضده؟ — وكلّها عن **الملفّ** لا عن جودة التحليل.
 * فنموذجٌ يكتب ملخّصاً طويلاً خاطئاً على ملفٍّ مكتمل يكسب درجةً عالية. والأوزان
 * (20/25/15/10/35/30) وعتبات الطول (120/200/80) **أرقامٌ اجتهاديّة غير مُعايَرة**
 * بأي قياس على بيانات.
 *
 * ولا تحكم قبولاً إلّا في `ticket.triage`: `AiPolicyGate` لا يقبل `high` آلياً مهما
 * بلغت الثقة، ويقبل `low` بلا عتبة — فالعتبة تحكم `medium` وحدها. ومن الدوالّ
 * الثلاث، `forExecution` و`forConsult` تخدمان مهامّ `high` ⇒ **درجتاهما تُخزَّن
 * وتُرتِّب صندوق المراجعة ولا تقرّر قبولاً**. ومعايرة أوزانٍ لا تقرّر شيئاً عملٌ
 * بلا مقياس، فأُجّلت بقرارٍ مُعلَن.
 *
 * **شرط رفع التأجيل:** ≥100 قرار مراجعة في `ai_runs.review_action`، عندها تقيس
 * `AiThresholdCalibration` العتبةَ على قرارات بشريّة فعليّة بدل تخمينٍ ثانٍ.
 * ويحرس التأجيلَ `AiConfidenceTest::test_the_score_only_gates_a_single_medium_task`:
 * أوّل مهمّة `medium` جديدة تُشتقّ لها ثقة تُسقطه وتُعيد فتح الملفّ.
 */
final class AiConfidence
{
    /** أدنى طول يُعدّ ملخّصاً موضوعياً لا جملة مقتضبة. */
    private const SUBSTANTIAL_SUMMARY = 120;

    private const SUBSTANTIAL_CONSULT_SUMMARY = 200;

    /** أدنى طول لوصف طلب يكفي للحكم عليه. */
    private const SUBSTANTIAL_DETAILS = 80;

    /**
     * ثقة تحليل طلب تنفيذ.
     *
     * @param  int  $documentsTotal  عدد المستندات المرفقة
     * @param  int  $documentsReadable  ما أمكن استخراج نصّه منها فعلاً
     * @return array{score:int, signals:array<string,mixed>}
     */
    public static function forExecution(
        string $summary,
        int $documentsTotal,
        int $documentsReadable,
        bool $hasDefendant,
        bool $hasSanad,
        bool $hasAmount,
    ): array {
        // نسبة المستندات المقروءة: تحليلٌ لم يقرأ مستنداً واحداً ليس كتحليلٍ قرأها كلّها
        $readableRatio = $documentsTotal > 0 ? $documentsReadable / $documentsTotal : 0.0;

        $signals = [
            'documents_attached' => $documentsTotal > 0,
            'documents_total' => $documentsTotal,
            'documents_readable' => $documentsReadable,
            'readable_ratio' => round($readableRatio, 2),
            'defendant_present' => $hasDefendant,
            'sanad_present' => $hasSanad,
            'amount_present' => $hasAmount,
            'summary_substantial' => mb_strlen(trim($summary)) >= self::SUBSTANTIAL_SUMMARY,
        ];

        $score = 0;
        $score += $signals['documents_attached'] ? 20 : 0;
        $score += (int) round(25 * $readableRatio);
        $score += $signals['defendant_present'] ? 15 : 0;
        $score += $signals['sanad_present'] ? 10 : 0;
        $score += $signals['amount_present'] ? 10 : 0;
        $score += $signals['summary_substantial'] ? 20 : 0;

        return ['score' => self::clamp($score), 'signals' => $signals];
    }

    /**
     * ثقة فرز التذكرة.
     *
     * @return array{score:int, signals:array<string,mixed>}
     */
    public static function forTicketTriage(
        string $department,
        string $details,
        string $clientChosenDepartment,
        string $ticketType,
    ): array {
        $catalogue = array_map('trim', explode('،', AiPromptRegistry::DEPARTMENTS));
        $inCatalogue = in_array(trim($department), $catalogue, true);
        $clientChose = trim($clientChosenDepartment) !== '';

        $signals = [
            // المحقِّق يقبل أي قسم غير فارغ؛ كونه من القاموس المعتمد إشارة جودة لا شرط قبول
            'department_in_catalogue' => $inCatalogue,
            'client_chose_department' => $clientChose,
            'agrees_with_client' => $clientChose && trim($department) === trim($clientChosenDepartment),
            'details_length' => mb_strlen(trim($details)),
            'details_substantial' => mb_strlen(trim($details)) >= self::SUBSTANTIAL_DETAILS,
            'ticket_type_present' => trim($ticketType) !== '',
        ];

        $score = 0;
        $score += $inCatalogue ? 35 : 0;
        $score += $signals['details_substantial'] ? 30 : 0;
        // الاتفاق مع اختيار العميل يرفع الثقة؛ وحين لا يختار العميل شيئاً لا يُعاقَب الفرز
        $score += $clientChose ? ($signals['agrees_with_client'] ? 20 : 0) : 10;
        $score += $signals['ticket_type_present'] ? 15 : 0;

        return ['score' => self::clamp($score), 'signals' => $signals];
    }

    /**
     * ثقة تحليل الاستشارة.
     *
     * @param  array<int,string>  $roster  أسماء المحامين الحقيقيّين
     * @return array{score:int, signals:array<string,mixed>}
     */
    public static function forConsult(
        string $class,
        string $summary,
        string $lawyer,
        array $roster,
        bool $hasSubject,
    ): array {
        $summary = trim($summary);
        // أقسام الملخّص التي تطلبها التعليمة صراحةً — حضورها فحصٌ موضوعيّ لاتّباعها
        $sections = ['الوقائع', 'التكييف', 'الرأي'];
        $sectionsFound = count(array_filter($sections, fn ($s) => str_contains($summary, $s)));

        $signals = [
            'lawyer_from_roster' => $lawyer !== '' && in_array($lawyer, $roster, true),
            'roster_available' => $roster !== [],
            'summary_sections_found' => $sectionsFound,
            'summary_sections_expected' => count($sections),
            'summary_substantial' => mb_strlen($summary) >= self::SUBSTANTIAL_CONSULT_SUMMARY,
            'class_well_formed' => str_starts_with(trim($class), 'استشارة'),
            'subject_present' => $hasSubject,
        ];

        $score = 0;
        $score += $signals['lawyer_from_roster'] ? 30 : 0;
        $score += (int) round(25 * ($sectionsFound / count($sections)));
        $score += $signals['summary_substantial'] ? 20 : 0;
        $score += $signals['class_well_formed'] ? 10 : 0;
        $score += $signals['subject_present'] ? 15 : 0;

        return ['score' => self::clamp($score), 'signals' => $signals];
    }

    private static function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
