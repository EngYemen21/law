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
        if ($meeting->zoom_summary_at !== null || empty($meeting->meet_id)) {
            return false;
        }

        // الويبهوك: اقرأ الملخّص من الحمولة مباشرةً؛ الأمر المجدول: اجلبه من API (بديل)
        $summary = ZoomService::summaryFromPayload($payload) ?? $zoom->meetingSummary((string) $meeting->meet_id);
        if ($summary === null) {
            return false;
        }

        $meeting->update([
            'zoom_summary' => ZoomSummaryText::format("ملخص الاجتماع — {$meeting->ref}", $summary),
            'zoom_summary_at' => now(),
        ]);
        Live::push(new MeetingStatusBroadcast($meeting));

        // القرارات → مهام تلقائية لدى المحامي المسؤول (idempotent)
        DecisionTasks::create($meeting, app(LegalAiService::class));

        return true;
    }
}
