<?php

namespace App\Support;

use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Services\LegalAiService;
use App\Services\ZoomService;

/**
 * جلب ملخّص AI Companion من Zoom لاستشارة مرئية وحفظه وبثّه — منطق مشترك
 * بين الأمر المجدول (zoom:pull-summaries) وwebhook (meeting.summary_completed). idempotent.
 */
class ConsultSummary
{
    /**
     * يجلب ملخّص Zoom ويحلّه محلّ الملخّص المؤقّت؛ يعيد true إن جُلب.
     *
     * @param  array<string, mixed>|null  $payload  محتوى payload.object من الويبهوك (يحمل الملخّص جاهزاً)
     */
    public static function pull(Consult $consult, ZoomService $zoom, ?array $payload = null): bool
    {
        // نصوص Zoom تُنسب لمصدرها في سجلّ النسخ وإن سُحبت بزرٍّ من الطاقم (`ContentRevisions::machine`)
        return ContentRevisions::machine('zoom', fn () => self::pullFromZoom($consult, $zoom, $payload));
    }

    /** @param  array<string, mixed>|null  $payload */
    private static function pullFromZoom(Consult $consult, ZoomService $zoom, ?array $payload): bool
    {
        if (empty($consult->meet_id)) {
            return false;
        }

        // يُهيَّأ خارج الشرط: `return ! empty($updates)` في الذيل يقرؤه ولو لم يُرجع
        // Zoom تفاصيل — فيرمي «Undefined variable» في PHP 8، ويُقيَّم `false` صامتاً
        // في اختبارٍ لا يرفع التحذيرات.
        $updates = [];

        // 1. جلب تفاصيل الجلسة المنتهية (المشاركون، التسجيل المرئي والصوتي، رابط المشاركة)
        $details = $zoom->fullPastMeetingDetails((string) $consult->meet_id);
        if ($details) {
            if ($details['uuid'] && ! $consult->zoom_uuid) {
                $updates['zoom_uuid'] = $details['uuid'];
            }
            if ($details['starts_at'] && ! $consult->join_time) {
                $updates['join_time'] = $details['starts_at'];
            }
            if ($details['ends_at'] && ! $consult->leave_time) {
                $updates['leave_time'] = $details['ends_at'];
            }
            if ($details['duration_sec'] !== null && ! $consult->duration_sec) {
                $updates['duration_sec'] = $details['duration_sec'];
            }
            if (! empty($details['participants_log']) && empty($consult->zoom_participants_log)) {
                $updates['zoom_participants_log'] = $details['participants_log'];
            }
            if ($details['recording_url'] && ! $consult->recording_url) {
                $updates['recording_url'] = $details['recording_url'];
            }
            if ($details['share_url'] && ! $consult->zoom_share_url) {
                $updates['zoom_share_url'] = $details['share_url'];
            }
            if ($details['audio_url'] && ! $consult->zoom_audio_url) {
                $updates['zoom_audio_url'] = $details['audio_url'];
            }
            if (! empty($updates)) {
                $consult->update($updates);
            }
        }

        // 2. جلب ملخص الذكاء الاصطناعي إن لم يكن كُتب سابقاً
        if ($consult->zoom_summary_at === null) {
            // الجلب الكامل أولاً (كل الانعقادات مدموجة) — حمولة الويبهوك تخصّ انعقاداً واحداً
            // فتُترك احتياطاً حين يتعذّر الاستعلام، وإلا أسقطنا أجزاء الجلسة المنقطعة
            $summary = $zoom->fullMeetingSummary((string) $consult->meet_id, $consult->zoom_uuid ?: ($details['uuid'] ?? null))
                ?? ZoomService::summaryFromPayload($payload);

            if ($summary !== null) {
                $text = ZoomSummaryText::format("ملخص الاستشارة — {$consult->ref}", $summary);

                $updateData = [
                    // مادّة الجلسة تُحفظ **دائماً** في عمودها — نظير `meetings.zoom_summary`
                    'zoom_summary' => $text,
                    'zoom_summary_at' => now(),
                    'zoom_ai_next_steps' => $summary['next_steps'],
                ];

                // أمّا حقل العميل فلا يُلمس إلّا إن كان قالبياً أو فارغاً. كان يُكتب
                // مباشرةً، فمع مسار الاعتماد صار ذلك يستبدل صامتاً نصّاً اعتمده محامٍ
                // وقرأه العميل بمخرج نموذجٍ لم يمرّ به أحد. والقاعدة منقولة من
                // `MeetingSummary`. وأثرٌ نافع: حالة «انتهت بلا تدوين» تترك `summary`
                // فارغاً، و`isPlaceholderSummary(null) === true` — فيصير Zoom موردَ
                // المادّة التلقائيّ لتلك الحالة بلا شرطٍ خاصّ.
                // **والاعتماد نهائيّ.** الحارس القالبيّ وحده لا يكفي: `approveSummary`
                // يشترط `filled($summary)` ولا يشترط ألّا يكون قالبياً، فنصٌّ قالبيٌّ
                // اعتُمد ووصل العميل يبقى قابلاً للاستبدال صامتاً. والقاعدة نفسها
                // المطبَّقة في `MeetingSummary::pull` بعد قرار «الاعتماد نهائيّ».
                $approved = $consult->summary_approved_at !== null;

                // **وما كتبه Zoom بغير العربيّة لا يصل العميل** (قرار المالك 2026-10-03): يبقى في `zoom_summary`
                // للطاقم، والملخّص بانتظار المستشار — `ZoomSummaryText::isArabic`
                if (! $approved && ZoomSummaryText::isArabic($summary) && ZoomSummaryText::isPlaceholderSummary($consult->summary)) {
                    $updateData['summary'] = $text;
                }

                $consult->update($updateData);
                Live::push(new ConsultStatusBroadcast($consult->fresh()));

                // القرارات → **اقتراحات** لا مهامّ: مخرج نموذج لا يُنشئ التزاماً على
                // إنسان بلا اعتماد. الإنشاء الفعليّ بزرّ createTasks (P3).
                // ولا تُقترح على معتمد: `toClientCard` يُرسل القرارات للعميل بعد
                // الاعتماد، فاقتراحٌ لاحق يُبلغه ما لم تعتمده الإدارة.
                if (! $approved) {
                    DecisionTasks::suggest($consult, app(LegalAiService::class));
                }

                return true;
            }
        }

        return ! empty($updates);
    }
}
