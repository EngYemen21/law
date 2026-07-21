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
        if ($consult->zoom_summary_at !== null || empty($consult->meet_id)) {
            return false; // جُلب سابقاً أو لا اجتماع
        }

        // الويبهوك: اقرأ الملخّص من الحمولة مباشرةً؛ الأمر المجدول: اجلبه من API (بديل)
        $summary = ZoomService::summaryFromPayload($payload) ?? $zoom->meetingSummary((string) $consult->meet_id);
        if ($summary === null) {
            return false; // لم يجهز بعد أو النطاق غير مُفعّل
        }

        $consult->update([
            'summary' => ZoomSummaryText::format("ملخص الاستشارة — {$consult->ref}", $summary),
            'zoom_summary_at' => now(),
        ]);
        Live::push(new ConsultStatusBroadcast($consult)); // «عرض الملخص» يتحدّث لحظياً لدى العميل

        // القرارات → مهام تلقائية لدى المحامي المسؤول (idempotent)
        DecisionTasks::create($consult, app(LegalAiService::class));

        return true;
    }
}
