<?php

namespace App\Support;

use App\Events\MeetingStatusBroadcast;
use App\Models\Meeting;
use App\Services\LegalAiService;
use App\Services\ZoomService;

/**
 * جلب ملخّص AI Companion من Zoom لاجتماع مكتب وحفظه (في zoom_summary — يتعايش مع المحضر
 * البشري المعتمَد) وبثّه. مشترك بين الأمر المجدول وwebhook. idempotent عبر zoom_summary_at.
 */
class MeetingSummary
{
    /**
     * @param  array<string, mixed>|null  $payload  محتوى payload.object من الويبهوك (يحمل الملخّص جاهزاً)
     */
    public static function pull(Meeting $meeting, ZoomService $zoom, ?array $payload = null): bool
    {
        // نصوص Zoom تُنسب لمصدرها في سجلّ النسخ وإن سُحبت بزرٍّ من الطاقم (`ContentRevisions::machine`)
        return ContentRevisions::machine('zoom', fn () => self::pullFromZoom($meeting, $zoom, $payload));
    }

    /** @param  array<string, mixed>|null  $payload */
    private static function pullFromZoom(Meeting $meeting, ZoomService $zoom, ?array $payload): bool
    {
        if (empty($meeting->meet_id)) {
            return false;
        }

        // 1. جلب تفاصيل الجلسة المنتهية (المشاركون، التسجيل المرئي والصوتي، رابط المشاركة)
        $details = $zoom->fullPastMeetingDetails((string) $meeting->meet_id);
        if ($details) {
            $updates = [];
            if ($details['uuid'] && ! $meeting->zoom_uuid) {
                $updates['zoom_uuid'] = $details['uuid'];
            }
            if ($details['starts_at'] && ! $meeting->join_time) {
                $updates['join_time'] = $details['starts_at'];
            }
            if ($details['ends_at'] && ! $meeting->leave_time) {
                $updates['leave_time'] = $details['ends_at'];
            }
            if ($details['duration_sec'] !== null && ! $meeting->duration_sec) {
                $updates['duration_sec'] = $details['duration_sec'];
            }
            if (! empty($details['participants_log']) && empty($meeting->zoom_participants_log)) {
                $updates['zoom_participants_log'] = $details['participants_log'];
                if (empty($meeting->participants)) {
                    $names = array_map(fn ($p) => $p['name'].($p['email'] ? ' ('.$p['email'].')' : ''), $details['participants_log']);
                    $updates['participants'] = implode('، ', $names);
                }
            }
            if ($details['recording_url'] && ! $meeting->recording_url) {
                $updates['recording_url'] = $details['recording_url'];
            }
            if ($details['share_url'] && ! $meeting->zoom_share_url) {
                $updates['zoom_share_url'] = $details['share_url'];
            }
            if ($details['audio_url'] && ! $meeting->zoom_audio_url) {
                $updates['zoom_audio_url'] = $details['audio_url'];
            }
            if (! empty($updates)) {
                $meeting->update($updates);
            }
        }

        // 2. جلب ملخص الذكاء الاصطناعي إن لم يكن كُتب سابقاً
        if ($meeting->zoom_summary_at === null) {
            // الجلب الكامل أولاً (كل الانعقادات مدموجة) — حمولة الويبهوك تخصّ انعقاداً واحداً
            // فتُترك احتياطاً حين يتعذّر الاستعلام، وإلا أسقطنا أجزاء الجلسة المنقطعة (M-26753)
            $summary = $zoom->fullMeetingSummary((string) $meeting->meet_id, $meeting->zoom_uuid ?: ($details['uuid'] ?? null))
                ?? ZoomService::summaryFromPayload($payload);

            if ($summary !== null) {
                $updateData = [
                    'zoom_summary' => ZoomSummaryText::format("ملخص الاجتماع — {$meeting->ref}", $summary),
                    'zoom_summary_at' => now(),
                    'zoom_ai_next_steps' => $summary['next_steps'] ?? [],
                    'has_summary' => true,
                ];

                // **الاعتماد نهائيّ.** ما شهدت به الإدارة لا تُغيّره مزامنةٌ لاحقة — لا نصّاً
                // ولا قرارات. والبيانات الوصفية أعلاه (التسجيل، المدّة، سجلّ الحضور،
                // zoom_summary) تبقى تُحدَّث: وقائعُ لا يشملها الاعتماد.
                $approved = $meeting->isApproved();

                // الملخص المعروض للعميل (toCard يقرأ summary لا zoom_summary): القالبي/الفارغ
                // يُستبدل بمحتوى Zoom الحقيقي — كما تفعل الاستشارات تماماً. المكتوب فعلاً يُحترم.
                if (! $approved && ZoomSummaryText::isPlaceholderSummary($meeting->summary)) {
                    $updateData['summary'] = ZoomSummaryText::format("ملخص الاجتماع — {$meeting->ref}", $summary);
                }

                // إذا كان المحضر فارغاً أو يحتوي نصاً قالبياً افتراضياً: استبداله بالأنصعة الحقيقية من Zoom AI
                if (! $approved && ZoomSummaryText::isPlaceholderMinutes($meeting->minutes)) {
                    $updateData['minutes'] = ZoomSummaryText::formatMinutes(
                        $meeting->title,
                        $meeting->when_label ?: $meeting->starts_at?->format('Y-m-d H:i'),
                        $meeting->participants,
                        $summary
                    );
                    $updateData['has_minutes'] = true;
                }

                $meeting->update($updateData);
                Live::push(new MeetingStatusBroadcast($meeting->fresh()));

                // القرارات → **اقتراحات** لا مهامّ: مخرج نموذج لا يُنشئ التزاماً على
                // إنسان بلا اعتماد. الإنشاء الفعليّ بزرّ createTasks (P3).
                // ولا تُقترح على معتمد: toCard يُرسل `decisions` للعميل متى كان معتمداً،
                // فاقتراحٌ بعد الاعتماد يُبلغه قراراتٍ لم تعتمدها الإدارة.
                if (! $approved) {
                    DecisionTasks::suggest($meeting, app(LegalAiService::class));
                }

                return true;
            }
        }

        return ! empty($updates);
    }
}
