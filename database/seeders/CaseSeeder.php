<?php

namespace Database\Seeders;

use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Database\Seeder;

class CaseSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.cases + CASE_DETAILS في الواجهة
        $cases = [
            [
                'number' => 'ق-2026-0211', 'type' => 'تجاري', 'status' => 'منظورة', 'tone' => 'b-blue',
                'update' => 'جلسة قادمة الخميس 02 يوليو',
                'next' => 'الخميس 02 يوليو · 10:00 ص',
                'invoice' => 'أتعاب القضية 23,000 ر.س — مدفوعة',
                'paid' => 'دفعة أولى 5,000 · ثانية 5,000 · ثالثة 13,000',
            ],
            [
                'number' => 'ق-2026-0118', 'type' => 'عمالي', 'status' => 'قيد التحضير', 'tone' => 'b-amber',
                'update' => 'إعداد مذكرة الرد على الدعوى',
                'next' => 'لم تُحدد بعد',
                'invoice' => 'أتعاب القضية 17,250 ر.س — دفعة مستحقة',
                'paid' => 'دفعة أولى 5,000 (مدفوعة)',
            ],
            [
                'number' => 'ق-2025-0904', 'type' => 'عقاري', 'status' => 'مغلقة', 'tone' => 'b-green',
                'update' => 'صدور حكم نهائي لصالح العميل',
                'next' => '—',
                'invoice' => 'أتعاب القضية 28,750 ر.س — مدفوعة بالكامل',
                'paid' => 'سُددت كامل الدفعات',
            ],
        ];

        foreach ($cases as $c) {
            $case = LegalCase::updateOrCreate(
                ['number' => $c['number']],
                [
                    'user_id' => $client->id,
                    'type' => $c['type'],
                    'status' => $c['status'],
                    'tone' => $c['tone'],
                    'update_text' => $c['update'],
                    'next_hearing' => $c['next'],
                    'invoice_text' => $c['invoice'],
                    'paid_text' => $c['paid'],
                ]
            );

            // المحادثة الأولية — تطابق seedCase(type, status, update, next) في الواجهة
            if ($case->messages()->count() === 0) {
                $next = $c['next'];
                $hasNext = $next && $next !== '—' && $next !== 'لم تُحدد بعد';
                $case->messages()->createMany([
                    ['who' => 'client', 'name' => 'عبدالله العتيبي', 'role' => 'العميل', 'body' => "بخصوص قضيتي ({$c['type']}) أرغب بمتابعة المستجدات.", 'time_label' => '09:00 ص'],
                    ['who' => 'ai', 'name' => 'الفريق القانوني', 'role' => 'متابعة القضية', 'body' => "مرحباً بكم. حالة القضية: {$c['status']}. آخر تحديث: {$c['update']}".($hasNext ? " — الجلسة القادمة: {$next}" : '').'.', 'time_label' => '09:05 ص'],
                ]);
            }
        }
    }
}
