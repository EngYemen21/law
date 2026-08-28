<?php

namespace App\Support;

use App\Enums\Role;
use App\Events\CorrStatusBroadcast;
use App\Models\Correspondence;
use App\Models\User;
use App\Services\ExternalSystemService;
use Illuminate\Validation\ValidationException;

/**
 * دورة حياة المخاطبة الرسميّة (يطابق corrAdvance/corrReceive/corrClose/corrBrief في التصميم).
 * كلّ انتقال: يحرس المرحلة، يحدّث stage/status/tone، يقيّد التدقيق، يُشعر (مكتب/عميل)، ويبثّ.
 */
class CorrespondenceFlow
{
    /** @param array{direction?:string,entity:string,subject:string,body?:string,case_id?:int,execution_id?:int,due?:string} $data */
    public static function create(User $lawyer, User $client, array $data): Correspondence
    {
        $corr = Correspondence::create([
            'number' => ReferenceNumber::next(Correspondence::class, 'number', 'MKH'),
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'lawyer' => $lawyer->name,
            'case_id' => $data['case_id'] ?? null,
            'execution_id' => $data['execution_id'] ?? null,
            'direction' => in_array($data['direction'] ?? '', ['صادرة', 'واردة'], true) ? $data['direction'] : 'صادرة',
            'entity' => $data['entity'],
            'subject' => $data['subject'],
            'body' => $data['body'] ?? '',
            'stage' => 0,
            'status' => CorrFlow::label(0),
            'tone' => CorrFlow::tone(0),
            'date_label' => now()->format('Y/m/d'),
            'due_label' => $data['due'] ?? '',
            'audit' => [['a' => 'إنشاء المخاطبة', 'by' => $lawyer->name, 't' => now()->format('Y/m/d h:i')]],
        ]);

        Live::push(new CorrStatusBroadcast($corr));

        return $corr;
    }

    /** نقل المخاطبة للمرحلة التالية؛ عند بلوغ «الإرسال للجهة»(3) يستدعي المحوّل الخارجيّ. */
    public static function advance(Correspondence $corr, string $actor): void
    {
        if ((int) $corr->stage >= 3) {
            throw ValidationException::withMessages(['stage' => 'المخاطبة تجاوزت مرحلة الإرسال — استخدم المزامنة/الاستقبال.']);
        }

        $stage = (int) $corr->stage + 1;
        $corr->logAudit(CorrFlow::label($stage), $actor);
        self::setStage($corr, $stage);

        if ($stage === 3) {
            $ext = app(ExternalSystemService::class)->send($corr);
            $corr->update(['channel' => 'النظام الخارجيّ', 'ext_ref' => $ext['ref'], 'ext_status' => $ext['status'], 'ext_synced_at' => now()]);
            self::notifyClient($corr, 'office', 't-blue', "تم إرسال مخاطبة بخصوص ملفّك ({$corr->subject}) إلى {$corr->entity} عبر النظام الخارجيّ.");
            $corr->refresh();
        }

        Live::push(new CorrStatusBroadcast($corr));
    }

    /** مزامنة حالة النظام الخارجيّ (لا تغيّر مرحلة المكتب). */
    public static function sync(Correspondence $corr): void
    {
        if (! $corr->ext_ref) {
            throw ValidationException::withMessages(['ext' => 'لم تُرسَل المخاطبة للنظام الخارجيّ بعد.']);
        }

        $status = app(ExternalSystemService::class)->status($corr);
        $corr->logAudit('مزامنة النظام الخارجيّ: '.$status, 'النظام الخارجيّ');
        $corr->update(['ext_status' => $status, 'ext_synced_at' => now(), 'audit' => $corr->audit]);

        // تقدّم رحلة المكتب إلى «بانتظار الرد» حين تتسلّم الجهة المخاطبة أو تعالجها.
        // كانت المرحلة 4 معلَنة بلا أي كاتب (receive يقفز 3 ← 5)، فيبقى العميل يرى
        // «الإرسال للجهة» بينما الجهة تعالج فعلاً — وهي الحالة التي أُنشئت المرحلة لتمثيلها.
        if ((int) $corr->stage === 3 && in_array($status, ['تم الاستلام لدى الجهة', 'قيد المعالجة لدى الجهة'], true)) {
            self::setStage($corr, 4, []);
        }

        if ($status === 'صدر الرد من الجهة') {
            self::notifyClient($corr, 'office', 't-green', "صدر ردّ من {$corr->entity} بخصوص ({$corr->subject}).");
        }

        Live::push(new CorrStatusBroadcast($corr));
    }

    /** تسجيل ورود الردّ في رحلة المكتب (المرحلة 5) — يجلب نصّ الردّ ويحدّث التنفيذ المرتبط. */
    public static function receive(Correspondence $corr, string $actor): void
    {
        if ((int) $corr->stage < 3) {
            throw ValidationException::withMessages(['stage' => 'لا يمكن استقبال ردّ قبل إرسال المخاطبة.']);
        }

        $reply = $corr->reply_body ?: app(ExternalSystemService::class)->reply($corr);
        $corr->logAudit('استقبال الردّ من النظام الخارجيّ', $corr->channel ?: 'النظام الخارجيّ');
        self::setStage($corr, 5, ['reply_body' => $reply, 'ext_status' => 'صدر الرد من الجهة', 'ext_synced_at' => now()]);

        // تحديث ملفّ التنفيذ المرتبط (إن وُجد) + إشعار العميل
        if ($corr->execution && method_exists($corr->execution, 'procedures')) {
            $corr->execution->procedures()->create(['title' => 'ورود ردّ المخاطبة '.$corr->number.' على ملفّ التنفيذ', 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ']);
            self::notifyClient($corr, 'office', 't-green', "ورد ردّ جهة حكوميّة على ملفّ تنفيذك {$corr->execution->number}.");
        }

        Live::push(new CorrStatusBroadcast($corr));
    }

    /** إفادة العميل رسميّاً بالنتيجة (المحامي/الإدارة). */
    public static function brief(Correspondence $corr, string $note, string $actor): void
    {
        $note = trim($note);
        abort_if($note === '', 422, 'اكتب نصّ الإفادة.');

        $corr->logAudit('إصدار إفادة للعميل', $actor);
        $corr->update(['briefed' => true, 'brief_requested' => false, 'brief_note' => $note, 'audit' => $corr->audit]);
        self::notifyClient($corr, 'office', 't-green', "صدرت إفادة رسميّة بخصوص مخاطبتك {$corr->number} — يمكنك عرضها وطباعتها.");

        Live::push(new CorrStatusBroadcast($corr));
    }

    /** طلب العميل إفادةً رسميّة. */
    public static function requestBrief(Correspondence $corr): void
    {
        $corr->logAudit('طلب العميل إفادة رسميّة', $corr->user?->name ?? 'العميل');
        $corr->update(['brief_requested' => true, 'audit' => $corr->audit]);
        self::notifyOffice($corr, 'office', 't-amber', "طلب العميل إفادة رسميّة بخصوص المخاطبة {$corr->number}.");
        Live::push(new CorrStatusBroadcast($corr));
    }

    /** الإغلاق والأرشفة (الإدارة). */
    public static function close(Correspondence $corr, string $actor): void
    {
        if ((int) $corr->stage < 5) {
            throw ValidationException::withMessages(['stage' => 'لا يمكن إغلاق المخاطبة قبل ورود الردّ.']);
        }
        $corr->logAudit('الإغلاق والأرشفة', $actor);
        self::setStage($corr, 6);
        Live::push(new CorrStatusBroadcast($corr));
    }

    // ── مساعدات ──

    /** @param array<string,mixed> $extra */
    private static function setStage(Correspondence $corr, int $stage, array $extra = []): void
    {
        $corr->update(array_merge([
            'stage' => $stage,
            'status' => CorrFlow::label($stage),
            'tone' => CorrFlow::tone($stage),
            'audit' => $corr->audit,
        ], $extra));
    }

    private static function notifyClient(Correspondence $corr, string $icon, string $tone, string $body): void
    {
        Notify::send($corr->user_id, $icon, $tone, $body);
    }

    private static function notifyOffice(Correspondence $corr, string $icon, string $tone, string $body): void
    {
        if ($corr->assigned_lawyer_id) {
            Notify::send($corr->assigned_lawyer_id, $icon, $tone, $body);
        }
        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send($adminId, $icon, $tone, $body);
        }
    }
}
