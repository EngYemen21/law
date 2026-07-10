<?php

namespace Database\Seeders;

use App\Models\Consult;
use App\Models\User;
use Illuminate\Database\Seeder;

class ConsultSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس روح CONSULTS في التصميم الأصلي — تنوّع مراحل رحلة المعالجة (CONSULT_FLOW) والقنوات
        $consults = [
            [
                'ref' => 'CN-2026-1042', 'subject' => 'نزاع تجاري مع مورّد', 'channel' => 'مرئية',
                'type' => 'تجاري', 'priority' => 'عالية',
                'lawyer' => 'أ. سارة القحطاني', 'day' => 'الاثنين 29 يونيو', 'time' => '11:30 ص',
                'branch' => null, 'phone' => null, 'received_label' => 'اليوم 09:14 ص', 'mins' => 6,
                'status' => 'جديدة', 'session' => 'بانتظار الجلسة',
                'price' => 450, 'vat' => 68, 'total' => 518,
            ],
            [
                'ref' => 'CN-2026-1039', 'subject' => 'فصل تعسفي من العمل', 'channel' => 'هاتفية',
                'type' => 'عمالي', 'priority' => 'متوسطة',
                'lawyer' => 'أ. خالد المالكي', 'employee' => 'منيرة الحربي',
                'day' => 'الثلاثاء 30 يونيو', 'time' => '10:00 ص',
                'branch' => null, 'phone' => '05•••••12', 'received_label' => 'اليوم 08:40 ص', 'mins' => 35,
                'status' => 'قيد مراجعة الموظف', 'session' => 'بانتظار الجلسة',
                'audit' => [['user' => 'منيرة الحربي', 'field' => 'الحالة', 'before' => 'جديدة', 'after' => 'قيد مراجعة الموظف', 'time' => 'اليوم 08:42 ص']],
                'price' => 350, 'vat' => 53, 'total' => 403,
            ],
            [
                'ref' => 'CN-2026-1035', 'subject' => 'مراجعة عقد توريد', 'channel' => 'حضورية',
                'type' => 'تجاري', 'priority' => 'عادية',
                'lawyer' => 'أ. ريم الزهراني', 'employee' => 'منيرة الحربي',
                'day' => 'الأربعاء 01 يوليو', 'time' => '01:00 م',
                'branch' => 'الفرع الرئيسي — جدة · قاعة 1', 'phone' => null, 'received_label' => 'أمس 02:10 م', 'mins' => 120,
                'status' => 'بانتظار اعتماد الموظف', 'session' => 'بانتظار الجلسة',
                'ai_done' => true,
                'ai_class' => 'استشارة عقود تجارية',
                'ai_summary' => 'مراجعة بنود التوريد وتقييم مخاطر الإخلال واقتراح تعديلات تحمي الطرف.',
                'ai_lawyer' => 'أ. سارة القحطاني',
                'missing' => ['نسخة العقد الموقّعة'],
                'audit' => [['user' => 'النظام', 'field' => 'تحليل الفريق القانوني', 'before' => '—', 'after' => 'اكتمل', 'time' => 'أمس 02:30 م']],
                'price' => 600, 'vat' => 90, 'total' => 690,
            ],
            [
                'ref' => 'CN-2026-0987', 'subject' => 'مطالبة مالية', 'channel' => 'مرئية',
                'type' => 'تجاري', 'priority' => 'عادية',
                'lawyer' => 'أ. سارة القحطاني', 'employee' => 'منيرة الحربي',
                'day' => 'الخميس 25 يونيو', 'time' => '04:00 م',
                'branch' => null, 'phone' => null, 'received_label' => 'قبل يومين', 'mins' => 140,
                'status' => 'منتهية', 'session' => 'منتهية', 'duration_label' => '38:12',
                'ai_done' => true,
                'ai_class' => 'مطالبة مالية',
                'ai_summary' => 'تحويلها إلى قضية مطالبة بعد تعذّر الحل الودي.',
                'ai_lawyer' => 'أ. سارة القحطاني',
                'summary' => "ملخص استشارة — CN-2026-0987\n\nعزيزنا العميل،\n\nالوقائع: تمّ خلال الاستشارة بشأن «مطالبة مالية» تحديد محل النزاع والنقاط الجوهرية بناءً على ما قدّمتموه.\n\nالرأي القانوني: نرى توجيه إنذار رسمي للطرف الآخر، ثم تجهيز مذكرة دعوى احتياطية حال عدم الاستجابة خلال المهلة النظامية.\n\nالإجراءات المقترحة: صياغة خطاب المطالبة ومتابعة المهلة النظامية، مع تزويدنا بأي مستندات إضافية.",
                'price' => 450, 'vat' => 68, 'total' => 518,
            ],
        ];

        foreach ($consults as $c) {
            Consult::updateOrCreate(
                ['ref' => $c['ref']],
                $c + ['user_id' => $client->id, 'when_label' => $c['day'].' · '.$c['time']]
            );
        }
    }
}
