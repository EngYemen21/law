<?php

namespace App\Support;

use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * فتح طلب تنفيذ من قضية بلغت «صدر الحكم» (تنفيذ الحكم) — نظير CaseConversion.
 */
class ExecutionCreation
{
    public static function isEligible(LegalCase $case): bool
    {
        return $case->status === 'صدر الحكم' && ! $case->execution()->exists();
    }

    public static function fromCase(LegalCase $case, User $actor): Execution
    {
        // محامي التنفيذ: محامي القضية إن كان حساباً حقيقياً، وإلا المحامي الذي فتح الطلب
        $lawyer = $case->assignedLawyer ?? $actor;

        $exec = DB::transaction(function () use ($case, $lawyer) {
            $number = ReferenceNumber::next(Execution::class, 'number', 'EXE');

            $exec = Execution::create([
                'user_id' => $case->user_id,
                'case_id' => $case->id,
                'number' => $number,
                'subject' => 'تنفيذ حكم — '.$case->type,
                'assigned_lawyer' => $lawyer->name,
                'assigned_lawyer_id' => $lawyer->id,
                'status' => 'جديد',
                'tone' => ExecJourney::toneFor('جديد'),
                'last_action' => 'فتح طلب التنفيذ بعد صدور الحكم',
            ]);

            $exec->messages()->create([
                'who' => 'system', 'name' => 'النظام', 'role' => 'فتح',
                'body' => '<p>تم فتح طلب تنفيذ الحكم الصادر في القضية '.e($case->number).'، وإسناده إلى قسم التنفيذ ('.e($lawyer->name).').</p>',
                'time_label' => self::clock(),
            ]);

            return $exec;
        });

        // رسالة داخل محادثة القضية يراها العميل (بثّ تلقائي عبر CaseMessage)
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $actor->name, 'role' => 'تنفيذ',
            'body' => "<p>تم فتح طلب تنفيذ الحكم رقم <b>{$exec->number}</b>. يمكنكم متابعته من قسم «طلبات التنفيذ».</p>",
            'time_label' => self::clock(),
        ]);

        Notify::send($case->user_id, 'exec', 't-blue', "تم فتح طلب تنفيذ الحكم {$exec->number} لقضيتك {$case->number}. تابعه من «طلبات التنفيذ».");

        Audit::log(
            action: 'تحويل قضية إلى تنفيذ',
            description: "فتح {$actor->name} ملف التنفيذ {$exec->number} من القضية {$case->number} وأُسند إلى {$exec->assigned_lawyer}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $exec,
            auditableRef: $exec->number,
            beforeState: ['القضية' => $case->number],
            afterState: ['ملف التنفيذ' => $exec->number, 'المحامي' => $exec->assigned_lawyer],
            user: $actor,
        );

        return $exec;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
