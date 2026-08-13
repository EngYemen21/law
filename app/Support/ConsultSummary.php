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
        if (empty($consult->meet_id)) {
            return false;
        }

        // 1. جلب تفاصيل الجلسة المنتهية (المشاركون، التسجيل المرئي والصوتي، رابط المشاركة)
        $details = $zoom->fetchPastMeetingDetails((string) $consult->meet_id);
        if ($details) {
            $updates = [];
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
            $summary = ZoomService::summaryFromPayload($payload)
                ?? $zoom->meetingSummary((string) $consult->meet_id, $consult->zoom_uuid ?: ($details['uuid'] ?? null));

            if ($summary !== null) {
                $consult->update([
                    'summary' => ZoomSummaryText::format("ملخص الاستشارة — {$consult->ref}", $summary),
                    'zoom_summary_at' => now(),
                    'zoom_ai_next_steps' => $summary['next_steps'] ?? [],
                ]);
                Live::push(new ConsultStatusBroadcast($consult->fresh()));

                // القرارات → مهام تلقائية لدى المحامي المسؤول (idempotent)
                DecisionTasks::create($consult, app(LegalAiService::class));

                return true;
            }
        }

        return ! empty($updates);
    }
}
