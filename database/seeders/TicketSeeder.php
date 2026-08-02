<?php

namespace Database\Seeders;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

class TicketSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.tickets في الواجهة
        $tickets = [
            ['number' => 'SB-2026-1042', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'lawyer' => 'أ. سارة القحطاني', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'last' => 'تمت إحالة طلبكم إلى القسم المختص لدراسة الموضوع.', 'date' => 'قبل ساعتين'],
            ['number' => 'SB-2026-1009', 'type' => 'قضية عمالية', 'department' => 'قسم القضايا العمالية', 'lawyer' => 'أ. سارة القحطاني', 'status' => 'بانتظار مستندات', 'tone' => 'b-amber', 'last' => 'يرجى إرفاق عقد العمل ومسير الرواتب لاستكمال الدراسة.', 'date' => 'أمس'],
            ['number' => 'SB-2026-0950', 'type' => 'استشارة قانونية عامة', 'department' => 'قسم الاستشارات العامة', 'lawyer' => 'أ. ريم الزهراني', 'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber', 'last' => 'تمت دراسة طلبكم مبدئياً، الرجاء حجز استشارة لاستكمال الرأي.', 'date' => 'قبل 4 أيام'],
            ['number' => 'SB-2026-0987', 'type' => 'نزاع عقاري', 'department' => 'القسم العقاري', 'lawyer' => 'أ. خالد المالكي', 'status' => 'مكتملة', 'tone' => 'b-green', 'last' => 'تم الانتهاء من الموضوع وإرسال ملخص الاستشارة.', 'date' => 'قبل أسبوع'],
        ];

        foreach ($tickets as $t) {
            $ticket = Ticket::updateOrCreate(
                ['number' => $t['number']],
                [
                    'user_id' => $client->id,
                    'type' => $t['type'],
                    'department' => $t['department'],
                    'assigned_lawyer' => $t['lawyer'],
                    'status' => $t['status'],
                    'tone' => $t['tone'],
                    'last_message' => $t['last'],
                    'date_label' => $t['date'],
                ]
            );

            // المحادثة الأولية — تطابق seedTicket(type, last) في الواجهة
            if ($ticket->messages()->count() === 0) {
                $ticket->messages()->createMany([
                    ['who' => 'client', 'name' => 'عبدالله العتيبي', 'role' => 'العميل', 'body' => $t['type'].' — يرجى دراسة الموضوع وإفادتي بالرأي القانوني.', 'time_label' => '10:01 ص'],
                    ['who' => 'ai', 'name' => 'الفريق القانوني', 'role' => 'استقبال', 'body' => 'تم استلام طلبكم بنجاح وإحالته إلى القسم القانوني المختص. يمكنكم متابعة المستجدات والكتابة هنا في أي وقت.', 'time_label' => '10:02 ص'],
                    ['who' => 'ai', 'name' => 'الفريق القانوني', 'role' => 'متابعة', 'body' => $t['last'], 'time_label' => '10:20 ص'],
                ]);
            }
        }
    }
}
