<?php

namespace Database\Seeders;

use App\Models\Execution;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExecutionSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.execs في الواجهة
        $execs = [
            ['number' => 'تنفيذ-5521', 'subject' => 'تنفيذ حكم مالي', 'status' => 'جارٍ', 'tone' => 'b-blue', 'last' => 'تقديم طلب حجز تحفظي على الحسابات'],
            ['number' => 'تنفيذ-5440', 'subject' => 'تنفيذ سند لأمر', 'status' => 'مكتمل', 'tone' => 'b-green', 'last' => 'تم تحصيل كامل المبلغ'],
        ];

        foreach ($execs as $e) {
            $execution = Execution::updateOrCreate(
                ['number' => $e['number']],
                [
                    'user_id' => $client->id,
                    'subject' => $e['subject'],
                    'status' => $e['status'],
                    'tone' => $e['tone'],
                    'last_action' => $e['last'],
                ]
            );

            // المحادثة الأولية — تطابق seedExec(subject, status, last) في الواجهة
            if ($execution->messages()->count() === 0) {
                $execution->messages()->createMany([
                    ['who' => 'client', 'name' => 'عبدالله العتيبي', 'role' => 'العميل', 'body' => "بخصوص طلب التنفيذ ({$e['subject']}) أرغب بمتابعة الإجراء.", 'time_label' => '09:00 ص'],
                    ['who' => 'ai', 'name' => 'الفريق القانوني', 'role' => 'التنفيذ', 'body' => "حالة الطلب: {$e['status']}. آخر إجراء: {$e['last']}.", 'time_label' => '09:05 ص'],
                ]);
            }
        }
    }
}
