<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use Illuminate\Database\Seeder;

class ExecutionSeeder extends Seeder
{
    public function run(): void
    {
        // العميل التجريبي مُعرَّف برقم الهوية الثابت في DatabaseSeeder (لا بالبريد — قد يتغيّر).
        $client = User::where('national_id', '1000000002')->where('role', Role::Client)->first();
        $lawyer = User::where('email', 'lawyer.jeddah@salasel.sa')->first();
        if (! $client) {
            return;
        }

        // تنوّع كامل عبر مراحل تدفّق التنفيذ العشر — ليظهر كل حالة فعلياً في لوحات الأدوار الأربعة
        $execs = [
            // 0/1: طلب جديد بانتظار التحليل الذكي (لا محامٍ بعد)
            ['number' => 'EXE-2026-0001', 'subject' => 'تحصيل قيمة شيك مرتجع', 'sanad' => 'شيك', 'amount' => 45000, 'stage' => 1, 'ai_done' => false],
            // 2: تحليل مكتمل مع نواقص — بانتظار دراسة المحامي
            ['number' => 'EXE-2026-0002', 'subject' => 'تنفيذ حكم نفقة', 'sanad' => 'حكم قضائي', 'amount' => 18000, 'stage' => 2, 'ai_done' => true, 'ai_missing' => ['صورة الهوية', 'سند النفقة'], 'lawyer' => true],
            // 3: قُبل الطلب — بانتظار تحديد المحامي للأتعاب
            ['number' => 'EXE-2026-0003', 'subject' => 'تنفيذ سند لأمر', 'sanad' => 'سند لأمر', 'amount' => 60000, 'stage' => 3, 'ai_done' => true, 'decision' => 'مقبول', 'lawyer' => true],
            // 4: أتعاب محدَّدة — بانتظار اعتماد الإدارة
            ['number' => 'EXE-2026-0004', 'subject' => 'تنفيذ عقد إيجار تجاري', 'sanad' => 'عقد تنفيذي', 'amount' => 32000, 'stage' => 4, 'ai_done' => true, 'decision' => 'مقبول', 'fee' => 3200, 'vat' => 480, 'lawyer' => true],
            // 5: عرض معتمد — بانتظار قبول العميل
            ['number' => 'EXE-2026-0005', 'subject' => 'تنفيذ محضر صلح', 'sanad' => 'محضر صلح', 'amount' => 25000, 'stage' => 5, 'ai_done' => true, 'decision' => 'مقبول', 'fee' => 2500, 'vat' => 375, 'fee_approved' => true, 'lawyer' => true],
            // 5: عرض رفضه العميل — يوضّح شارة «رفضتَ هذا العرض»
            ['number' => 'EXE-2026-0006', 'subject' => 'تنفيذ قرار تحكيم', 'sanad' => 'قرار تحكيم', 'amount' => 90000, 'stage' => 5, 'ai_done' => true, 'decision' => 'مقبول', 'fee' => 9000, 'vat' => 1350, 'fee_approved' => true, 'offer_status' => 'مرفوض', 'lawyer' => true],
            // 6: فاتورة صادرة — بانتظار السداد
            ['number' => 'EXE-2026-0007', 'subject' => 'تنفيذ حكم مالي', 'sanad' => 'حكم قضائي', 'amount' => 120000, 'stage' => 6, 'ai_done' => true, 'decision' => 'مقبول', 'fee' => 12000, 'vat' => 1800, 'fee_approved' => true, 'offer_status' => 'مقبول', 'invoice_no' => 'INV-2026-0501', 'lawyer' => true],
            // 8: ملف مفتوح قيد التنفيذ — بإجراءات
            ['number' => 'EXE-2026-0008', 'subject' => 'تنفيذ حكم إخلاء عقاري', 'sanad' => 'حكم قضائي', 'amount' => 15000, 'stage' => 8, 'ai_done' => true, 'decision' => 'مقبول', 'fee' => 1500, 'vat' => 225, 'fee_approved' => true, 'offer_status' => 'مقبول', 'paid' => true, 'paid_at' => now()->subDays(3), 'exec_no' => '92-2026-تنفيذ', 'procedures' => true, 'lawyer' => true],
            // 9: ملف مغلق ومؤرشف
            ['number' => 'EXE-2026-0009', 'subject' => 'تنفيذ سند لأمر صغير', 'sanad' => 'سند لأمر', 'amount' => 8000, 'stage' => 9, 'ai_done' => true, 'decision' => 'مقبول', 'fee' => 900, 'vat' => 135, 'fee_approved' => true, 'offer_status' => 'مقبول', 'paid' => true, 'paid_at' => now()->subDays(20), 'exec_no' => '85-2026-تنفيذ', 'procedures' => true, 'lawyer' => true],
        ];

        foreach ($execs as $e) {
            $lawyerId = ($e['lawyer'] ?? false) ? $lawyer?->id : null;

            $execution = Execution::updateOrCreate(
                ['number' => $e['number']],
                [
                    'user_id' => $client->id,
                    'client_code' => 'CL-'.str_pad((string) $client->id, 6, '0', STR_PAD_LEFT),
                    'subject' => $e['subject'],
                    'sanad' => $e['sanad'],
                    'amount' => $e['amount'],
                    'stage' => $e['stage'],
                    'status' => ExecFlow::label($e['stage']),
                    'tone' => ExecFlow::tone($e['stage']),
                    'ai_done' => $e['ai_done'],
                    'ai_summary' => $e['ai_done'] ? 'راجع الفريق القانوني الذكي المستندات المرفقة وأكّد اكتمال بيانات السند.' : null,
                    'ai_missing' => $e['ai_missing'] ?? [],
                    'decision' => $e['decision'] ?? null,
                    'fee' => $e['fee'] ?? 0,
                    'vat' => $e['vat'] ?? 0,
                    'fee_approved' => $e['fee_approved'] ?? false,
                    'offer_status' => $e['offer_status'] ?? null,
                    'invoice_no' => $e['invoice_no'] ?? null,
                    'paid' => $e['paid'] ?? false,
                    'paid_at' => $e['paid_at'] ?? null,
                    'exec_no' => $e['exec_no'] ?? null,
                    'assigned_lawyer_id' => $lawyerId,
                    'assigned_lawyer' => $lawyerId ? $lawyer->name : null,
                    'branch' => $lawyerId ? $lawyer->branch : null,
                    'last_action' => ExecFlow::label($e['stage']),
                ]
            );

            if ($execution->messages()->count() === 0) {
                $execution->messages()->createMany([
                    ['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => "<p>طلب تنفيذ {$e['sanad']} — {$e['subject']}.</p>", 'time_label' => '09:00 ص'],
                ]);
            }

            if (($e['procedures'] ?? false) && $execution->procedures()->count() === 0) {
                $execution->procedures()->createMany([
                    ['title' => 'فتح ملف التنفيذ وتقديم الطلب إلكترونياً', 'type' => 'إجراء', 'detail' => '', 'status' => 'منفّذ'],
                    ['title' => 'إخطار المنفَّذ ضده بموجب المادة 34', 'type' => 'إخطار', 'detail' => '', 'status' => 'منفّذ'],
                    ['title' => 'حجز على الحسابات البنكية', 'type' => 'حجز', 'detail' => '', 'status' => $e['stage'] === 9 ? 'منفّذ' : 'مجدول'],
                ]);
            }
        }

        // سجلّ من «النمط القديم» (تحويل قضية→تنفيذ، stage=null) — يمثّل مسار ExecJourney المستقلّ
        Execution::updateOrCreate(
            ['number' => 'EXE-OLD-0001'],
            [
                'user_id' => $client->id,
                'subject' => 'تنفيذ حكم — تحويل من قضية',
                'status' => 'جارٍ',
                'tone' => 'b-blue',
                'last_action' => 'تقديم طلب حجز تحفظي على الحسابات',
                'assigned_lawyer_id' => $lawyer?->id,
                'assigned_lawyer' => $lawyer?->name,
                'branch' => $lawyer?->branch,
            ]
        );
    }
}
