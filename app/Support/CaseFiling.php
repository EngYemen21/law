<?php

namespace App\Support;

use App\Domain\Journey\Transitions\LegalCase\FileNajiz as FileNajizTransition;
use App\Domain\Journey\Transitions\LegalCase\RegisterNajiz as RegisterNajizTransition;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\CaseStatusBroadcast;
use App\Models\LegalCase;
use App\Models\User;

/**
 * **رفع الدعوى في ناجز ثمّ قيدها.** (قرار المالك 2026-09-11 — الخطّة ب)
 *
 * ثلاث خطواتٍ في الواقع كانت ضغطةً واحدة: اعتماد النصّ (داخل النظام)، ورفع الصحيفة في ناجز
 * (يرجع برقم طلب)، وقبول المحكمة وقيد الدعوى (يرجع برقم القضيّة والدائرة وموعد الجلسة الأولى).
 * فصار ما يراه العميل مطابقاً لما وقع: «بانتظار القيد» بعد الرفع، و«منظورة» بعد القيد وحده.
 */
final class CaseFiling
{
    public const AWAITING = 'بانتظار القيد';

    /** كاتب رسالة الإجراء في المحادثة: المحامي بوسمه، وغيرُه (الموظّف والإدارة) بوسم المكتب. */
    public static function author(User $actor): string
    {
        return $actor->role === Role::Lawyer ? 'lawyer' : 'staff';
    }

    /**
     * ما يجوز الآن من الرفع والقيد، وبياناتهما — للمحامي والموظّف من المصدر نفسه.
     *
     * @return array{canFile: bool, canRegister: bool, data: array<string, string|null>|null}
     */
    public static function panel(LegalCase $case): array
    {
        return [
            'canFile' => $case->status === 'قيد التحضير' && self::fileBlock($case) === null,
            'canRegister' => self::registerBlock($case) === null,
            'data' => $case->najizCard(),
        ];
    }

    /** سببُ تعذّر تسجيل الرفع (أو تصحيح بياناته) — أو `null`. */
    public static function fileBlock(LegalCase $case): ?string
    {
        if ($case->pleading_status !== 'approved') {
            return 'تُرفع الدعوى بعد الاعتماد النهائيّ للائحة.';
        }

        if (! in_array($case->status, ['قيد التحضير', self::AWAITING], true)) {
            return 'قُيّدت هذه الدعوى — بياناتُ رفعها لا تُعدَّل بعد القيد.';
        }

        return null;
    }

    /** سببُ تعذّر تسجيل القيد — أو `null`. */
    public static function registerBlock(LegalCase $case): ?string
    {
        return $case->status === self::AWAITING ? null : 'يُسجَّل القيد بعد تسجيل رفع الدعوى في ناجز.';
    }

    /** تسجيل الرفع: رقم الطلب وتاريخه ⇐ «بانتظار القيد». وتكراره تصحيحٌ للبيانات لا رفعٌ ثانٍ. */
    public static function file(LegalCase $case, User $actor, string $requestNo, string $filedAt): void
    {
        $correction = $case->status === self::AWAITING;

        Workflow::run(new FileNajizTransition, $case, $actor, [
            'request_no' => $requestNo,
            'filed_at' => $filedAt,
        ]);

        $case->messages()->create([
            'who' => $correction ? 'note' : self::author($actor),
            'name' => $actor->name,
            'role' => $correction ? 'تصحيح بيانات الرفع' : 'رفع الدعوى',
            'body' => $correction
                ? '<p>صُحّحت بيانات الرفع في ناجز: رقم الطلب <b>'.e($requestNo).'</b>.</p>'
                : '<p>رُفعت صحيفة الدعوى عبر منصّة ناجز برقم الطلب <b>'.e($requestNo).'</b>، وهي بانتظار قيد المحكمة.</p>',
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);

        if (! $correction) {
            Notify::send($case->user_id, 'scale', 't-blue', "رُفعت دعوى قضيتك {$case->number} عبر منصّة ناجز برقم الطلب {$requestNo}، وهي بانتظار قيد المحكمة.");
        }

        Audit::log(
            action: $correction ? 'تصحيح بيانات رفع دعوى' : 'رفع دعوى في ناجز',
            description: "سجّل {$actor->name} رفع دعوى القضية {$case->number} في ناجز برقم الطلب {$requestNo}.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الحالة' => self::AWAITING, 'رقم الطلب' => $requestNo],
        );

        Live::push(new CaseStatusBroadcast($case));
    }

    /**
     * تسجيل القيد: رقم القضيّة والمحكمة والدائرة وتاريخ القيد ⇐ «منظورة». الجلسة الأولى تُنشأ في
     * المتحكّم عبر مسار الجدولة نفسه (تذكيراتٌ وبريدٌ وإشعار) — لا نسخةٌ ثانية منه هنا.
     *
     * @param  array{case_no: string, court: string, circuit: string, registered_at: string}  $data
     */
    public static function register(LegalCase $case, User $actor, array $data): void
    {
        Workflow::run(new RegisterNajizTransition, $case, $actor, $data);

        $case->messages()->create([
            'who' => self::author($actor),
            'name' => $actor->name,
            'role' => 'قيد الدعوى',
            'body' => '<p>قُيّدت الدعوى لدى '.e($data['court']).' — '.e($data['circuit']).' برقم <b>'.e($data['case_no']).'</b>.</p>',
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);

        Notify::send($case->user_id, 'scale', 't-green', "قُيّدت دعوى قضيتك {$case->number} برقم {$data['case_no']} لدى {$data['court']} — {$data['circuit']}.");

        Audit::log(
            action: 'قيد دعوى',
            description: "سجّل {$actor->name} قيد القضية {$case->number} برقم {$data['case_no']} لدى {$data['court']} — {$data['circuit']}.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الحالة' => 'منظورة', 'رقم القضية' => $data['case_no']],
        );

        Live::push(new CaseStatusBroadcast($case));
    }
}
