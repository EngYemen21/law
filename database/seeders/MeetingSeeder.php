<?php

namespace Database\Seeders;

use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class MeetingSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس روح FULL_MEETINGS في التصميم الأصلي — تنوّع الأنواع والحالات والاعتماد
        $meetings = [
            [
                'ref' => 'M-26101', 'title' => 'استشارة مرئية — نزاع تجاري', 'type' => 'اجتماع مع عميل',
                'client_name' => $client->name, 'user_id' => $client->id,
                'when_label' => 'الاثنين 29 يونيو · 11:30 ص', 'status' => 'قادم', 'priority' => 'عالية',
                'conf' => 'سري', 'dur' => '45 دقيقة', 'approve' => 'بانتظار اعتماد الإدارة',
                'case_ref' => 'SB-2026-1042 — تذكرة', 'created_by' => 'منيرة الحربي',
                'before_items' => ['مراجعة التذكرة SB-2026-1042', 'قراءة عقد التوريد والمراسلات', 'تجهيز ملخص أولي للوقائع'],
                'during_items' => ['تسجيل الجلسة', 'تحويل الصوت إلى نص', 'تحديد المتحدثين (المستشار/العميل)'],
                'after_items' => ['ملخص الجلسة جاهز', 'محضر الاجتماع منشأ', 'مهام مستخرجة: 3', 'قرارات مستخرجة: 2'],
                'is_up' => true, 'has_link' => true,
            ],
            [
                'ref' => 'M-26102', 'title' => 'اجتماع فريق قضية — عمالي', 'type' => 'اجتماع مرتبط بقضية',
                'client_name' => 'داخلي', 'user_id' => null,
                'when_label' => 'الأحد 28 يونيو · 09:00 ص', 'status' => 'منتهٍ', 'priority' => 'متوسطة',
                'conf' => 'عادي', 'dur' => '60 دقيقة', 'attend' => 92, 'approve' => 'معتمد', 'sum_approved' => true,
                'case_ref' => 'ق-2026-0118 — قضية', 'created_by' => 'الإدارة العليا',
                'participants' => 'أ. سارة القحطاني، أ. خالد المالكي',
                'before_items' => ['مراجعة ملف القضية ق-2026-0118', 'قراءة مذكرة الرد'],
                'during_items' => ['تسجيل النقاش', 'تفريغ نصي', 'توزيع المهام'],
                'after_items' => ['ملخص الاجتماع جاهز', 'المحضر معتمد', 'مهام مستخرجة: 4', 'قرارات مستخرجة: 1'],
                'summary' => 'ملخص اجتماع فريق القضية العمالية: استُعرضت مذكرة الرد وتوزّعت المهام على الفريق.',
                'minutes' => "محضر اجتماع: اجتماع فريق قضية — عمالي\nأبرز ما دار:\n- تسجيل النقاش\n- توزيع المهام\nالقرارات: تجهيز مذكرة الرد خلال 3 أيام.",
                'is_up' => false, 'has_minutes' => true, 'has_summary' => true,
            ],
            [
                'ref' => 'M-26103', 'title' => 'اجتماع داخلي — توزيع الأعباء', 'type' => 'اجتماع متعدد الموظفين',
                'client_name' => 'داخلي', 'user_id' => null,
                'when_label' => 'الأحد 28 يونيو · 04:00 م', 'status' => 'مؤجل', 'priority' => 'عادية',
                'conf' => 'عادي', 'dur' => '90 دقيقة', 'approve' => 'بانتظار اعتماد الإدارة',
                'created_by' => 'الإدارة العليا',
                'before_items' => ['حصر التذاكر المفتوحة', 'مراجعة طاقة كل مستشار'],
                'during_items' => ['مناقشة التوزيع', 'تسجيل القرارات'],
                'after_items' => ['محضر معتمد', 'قرارات مستخرجة: 3'],
                'is_up' => true,
            ],
            [
                'ref' => 'M-26104', 'title' => 'استشارة مرئية — نزاع عقاري', 'type' => 'اجتماع مع عميل',
                'client_name' => $client->name, 'user_id' => $client->id,
                'when_label' => 'الجمعة 12 يونيو · 10:00 ص', 'status' => 'منتهٍ', 'priority' => 'متوسطة',
                'conf' => 'عادي', 'dur' => '45 دقيقة', 'attend' => 86, 'approve' => 'معتمد', 'sum_approved' => true,
                'created_by' => 'منيرة الحربي',
                'before_items' => ['مراجعة صك الملكية', 'قراءة المراسلات'],
                'during_items' => ['تسجيل الجلسة', 'تحويل الصوت إلى نص'],
                'after_items' => ['ملخص الجلسة جاهز', 'المحضر معتمد'],
                'summary' => 'ملخص اجتماع: استشارة مرئية — نزاع عقاري. نوصي بتوجيه إنذار رسمي ثم رفع دعوى إخلاء عند عدم الاستجابة.',
                'minutes' => "محضر اجتماع: استشارة مرئية — نزاع عقاري\nالتاريخ: الجمعة 12 يونيو\nأبرز ما دار:\n- عرض العميل وقائع النزاع\n- مراجعة صك الملكية\nالقرارات: إعداد خطاب إنذار خلال يومين.",
                'is_up' => false, 'has_minutes' => true, 'has_summary' => true,
            ],
        ];

        foreach ($meetings as $m) {
            Meeting::updateOrCreate(['ref' => $m['ref']], $m);
        }

        // دعوات الاجتماعات (MR_FLOW) — واحدة بانتظار تأكيد العميل وأخرى مؤكّدة برابط
        $m1 = Meeting::where('ref', 'M-26101')->first();
        $requests = [
            [
                'ref' => 'MR-1042', 'user_id' => $client->id, 'service' => 'نزاع تجاري',
                'type' => 'استشارة مرئية', 'case_ref' => 'SB-2026-1042 — تذكرة',
                'day' => 'الاثنين 29 يونيو', 'time' => '11:30 ص',
                'sent_by' => 'منيرة الحربي (خدمة العملاء)', 'stage' => 0,
            ],
            [
                'ref' => 'MR-1035', 'user_id' => $client->id, 'service' => 'مراجعة عقد',
                'type' => 'استشارة حضورية', 'case_ref' => 'CN-2026-1035 — استشارة',
                'day' => 'الأربعاء 01 يوليو', 'time' => '01:00 م',
                'sent_by' => 'الإدارة العليا (الإدارة العليا)', 'stage' => 1,
                'meeting_id' => $m1?->id,
            ],
        ];

        foreach ($requests as $r) {
            MeetRequest::updateOrCreate(['ref' => $r['ref']], $r);
        }
    }
}
