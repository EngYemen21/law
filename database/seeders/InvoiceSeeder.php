<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.invoices في الواجهة
        $invoices = [
            ['no' => 'INV-2026-312', 'desc' => 'أتعاب قضية · CASE-2026-0001', 'amount' => 23000, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due' => 'تستحق قبل 02 يوليو', 'paid' => false],
            ['no' => 'INV-2026-309', 'desc' => 'استشارة هاتفية · الأحوال الشخصية', 'amount' => 345, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due' => 'تستحق قبل 01 يوليو', 'paid' => false],
            ['no' => 'INV-2026-305', 'desc' => 'مراجعة عقد · العقود والاتفاقيات', 'amount' => 460, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due' => 'تستحق قبل 29 يونيو', 'paid' => false],
            ['no' => 'INV-2026-301', 'desc' => 'استشارة مرئية · القسم التجاري', 'amount' => 518, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due' => 'تستحق قبل 30 يونيو', 'paid' => false],
            ['no' => 'INV-2026-296', 'desc' => 'اجتماع فريق قضية · عمالي', 'amount' => 805, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due' => 'سُددت في 22 يونيو', 'paid' => true],
            ['no' => 'INV-2026-288', 'desc' => 'استشارة حضورية · القسم العقاري', 'amount' => 690, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due' => 'سُددت في 20 يونيو', 'paid' => true],
            ['no' => 'INV-2026-275', 'desc' => 'مراجعة مستند · الشركات', 'amount' => 402, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due' => 'سُددت في 15 يونيو', 'paid' => true],
            ['no' => 'INV-2026-260', 'desc' => 'استشارة مرئية · البنوك والتمويل', 'amount' => 575, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due' => 'سُددت في 10 يونيو', 'paid' => true],
            ['no' => 'INV-2026-244', 'desc' => 'أتعاب تنفيذ · التنفيذ', 'amount' => 1150, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due' => 'سُددت في 05 يونيو', 'paid' => true],
            ['no' => 'INV-2026-231', 'desc' => 'استشارة هاتفية · الملكية الفكرية', 'amount' => 299, 'status' => 'مدفوعة', 'tone' => 'b-green', 'due' => 'سُددت في 01 يونيو', 'paid' => true],
        ];

        foreach ($invoices as $v) {
            Invoice::updateOrCreate(
                ['number' => $v['no']],
                [
                    'user_id' => $client->id,
                    'description' => $v['desc'],
                    'amount' => $v['amount'],
                    'status' => $v['status'],
                    'tone' => $v['tone'],
                    'due_label' => $v['due'],
                    'paid' => $v['paid'],
                ]
            );
        }
    }
}
