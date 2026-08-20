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
            $number = 'EXE-'.now()->year.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

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
                'body' => "<p>تم فتح طلب تنفيذ الحكم الصادر في القضية {$case->number}، وإسناده إلى قسم التنفيذ ({$lawyer->name}).</p>",
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

        return $exec;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
