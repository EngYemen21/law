<?php

namespace App\Support;

use App\Mail\MeetingScheduledMail;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MailService;
use App\Services\ZoomService;
use App\Support\Booking\BookingMoved;

/**
 * تجسيد دعوة الاجتماع: جلسة Zoom + اجتماع «قادم» في اجتماعات العميل.
 *
 * لماذا هنا: كان هذا الجسم داخل MeetRequestController::confirm — أي أن **تأكيد العميل** هو
 * ما يُنشئ الاجتماع فعلاً، لا مجرّد إقرار بالحضور. وبقرار صاحب المنتج أُلغي التأكيد: الدعوة
 * تُولَد مؤكَّدة لحظة إرسال الطاقم، فنُقل الجسم إلى هنا ليناديه مسار الإرسال مباشرةً.
 *
 * الحارس الأهمّ محفوظ كما هو: اجتماع Zoom **واحد** لكل دعوة. إنشاء ثانٍ كان يكتب معرّفه فوق
 * الأول ويتركه يتيماً في حساب Zoom — بتسجيل سحابي مفعّل يستهلك حصّة التخزين — بلا أي مقبض
 * لتنظيفه لاحقاً.
 */
class MeetInvitation
{
    /** يُنشئ (أو يُكمل) اجتماع الدعوة ويرفعها إلى STAGE_CONFIRMED. يُعيد الاجتماع. */
    public static function schedule(MeetRequest $req, User $client): Meeting
    {
        $startsAt = MeetingTime::parse($req->day, $req->time);
        $durMinutes = $req->duration_min ?: 60;
        $existing = $req->meeting_id ? Meeting::find($req->meeting_id) : null;

        $zoom = ($existing && ! empty($existing->meet_id))
            ? null
            : app(ZoomService::class)->createMeeting(
                "{$req->type} — {$req->service} ({$req->ref})", $durMinutes, false, $startsAt
            );

        if ($existing) {
            $meeting = $existing;
            $meeting->update([
                'status' => 'قادم',
                // الموعد يُحدَّث أيضاً: إعادة الإرسال بموعد جديد كانت تُبقي الاجتماع على موعده القديم
                'when_label' => $req->day.' · '.$req->time,
                'starts_at' => $startsAt,
                'meet_id' => $zoom['id'] ?? $meeting->meet_id,
                'meet_link' => $zoom['join_url'] ?? $meeting->meet_link,
                'host_link' => $zoom['start_url'] ?? $meeting->host_link,
                'meet_password' => $zoom['password'] ?? $meeting->meet_password,
                'has_link' => true,
            ]);

            // **Zoom يتبع الموعد.** كان هذا الفرع يُحدّث `starts_at` و`when_label`
            // ويُبقي `meet_id`، و`$zoom` فارغةٌ هنا — فلا يُنادى `updateMeeting`
            // إطلاقاً: قاعدة البيانات والبريد والبوّابة على الموعد الجديد، وZoom على
            // القديم. والتعليق أعلاه يشهد أن نصف العطل أُصلح وظُنّ تامّاً.
            BookingMoved::apply($meeting, $startsAt);
        } else {
            $meeting = Meeting::create([
                'user_id' => $req->user_id,
                'ref' => 'M-'.now()->format('y').random_int(100, 999),
                'title' => "{$req->type} — {$req->service}",
                'type' => 'اجتماع مع عميل',
                'client_name' => $client->name,
                'when_label' => $req->day.' · '.$req->time,
                'starts_at' => $startsAt,
                'status' => 'قادم',
                'case_ref' => $req->case_ref,
                'dur' => $durMinutes.' دقيقة',
                'assigned_lawyer_id' => $req->assigned_lawyer_id,
                'meet_id' => $zoom['id'] ?? null,
                'meet_link' => $zoom['join_url'] ?? null,
                'host_link' => $zoom['start_url'] ?? null,
                'meet_password' => $zoom['password'] ?? null,
                'created_by' => $req->sent_by,
                // عُلّق بطلب صاحب المنتج (2026-08-26): قوائم «قبل/أثناء/بعد الاجتماع» نصّ ثابت مختلق لا بيانات حقيقية
                // 'before_items' => ['مراجعة موضوع الدعوة: '.$req->service, 'قراءة المستندات ذات الصلة', 'تجهيز جدول الأعمال'],
                // 'during_items' => ['تسجيل الجلسة', 'تحويل الصوت إلى نص', 'استخراج القرارات'],
                // 'after_items' => ['إنشاء الملخص', 'إعداد المحضر', 'تحويل القرارات إلى مهام'],
                'has_link' => true,
            ]);
        }

        $req->update([
            'stage' => MeetRequest::STAGE_CONFIRMED,
            'meeting_id' => $meeting->id,
            'meet_id' => $zoom['id'] ?? $req->meet_id,
            'meet_link' => $zoom['join_url'] ?? $req->meet_link,
            'host_link' => $zoom['start_url'] ?? $req->host_link,
        ]);

        // مزامنة تقويم Google — النشر (بعد موافقة الإدارة/دعوة الإدارة) هو لحظة اعتماد الموعد
        GoogleCalendarService::syncMeeting($meeting);

        return $meeting;
    }

    /** إشعار العميل والمحامي + بريد الموعد — أفضل-جهد، لا يُجهض دعوة أُرسلت فعلاً. */
    public static function announce(MeetRequest $req, Meeting $meeting, User $client): void
    {
        Notify::send($req->user_id, 'video', 't-blue', "اجتماع مجدول: «{$req->service}» ({$req->day} · {$req->time}) — تجده في قسم الاجتماعات بالمنصة.");

        if ($meeting->assigned_lawyer_id) {
            Notify::send($meeting->assigned_lawyer_id, 'video', 't-blue', "اجتماع مجدول مع العميل ({$client->name}): «{$meeting->title}» ({$meeting->when_label}).");
        }

        app(MailService::class)->send($client, new MeetingScheduledMail(
            $client->name,
            "{$req->type} — {$req->service}",
            "{$req->day} · {$req->time}",
            $meeting->portalUrlFor($client),
            'داخل النظام الإداري لمكاتب المحاماة (قسم الاجتماعات)',
            'الاجتماع مجدول ومؤكَّد. لأسباب السرية، يرجى تسجيل الدخول إلى حسابك بالمنصة عند موعد الجلسة.'
        ));
    }
}
