<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\CaseMessage;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. إنشاء وتحديث حسابات المستخدمين (محامون، موظفون، عملاء) ──
        $password = Hash::make('password');

        // المحامون
        $lawyer1 = User::updateOrCreate(
            ['email' => 'fahad.lawyer@salasel.sa'],
            [
                'name' => 'أ. فهد السبيعي',
                'national_id' => '1000000012',
                'phone' => '+966501110012',
                'role' => Role::Lawyer,
                'title' => 'أ.',
                'job_title' => 'مستشار قضايا الشركات والاستثمار',
                'department' => 'قضايا الشركات',
                'work_start' => '09:00',
                'work_end' => '17:00',
                'avatar_initials' => 'ف س',
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );
        $lawyer1->syncRoles(['محامٍ']);
        $lawyer1->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['lawyer'])->get());

        $lawyer2 = User::updateOrCreate(
            ['email' => 'sara.lawyer@salasel.sa'],
            [
                'name' => 'أ. سارة المنصور',
                'national_id' => '1000000013',
                'phone' => '+966501110013',
                'role' => Role::Lawyer,
                'title' => 'أ.',
                'job_title' => 'مستشارة النزاعات العقارية والمقاولات',
                'department' => 'العقارات والمقاولات',
                'work_start' => '08:30',
                'work_end' => '16:30',
                'avatar_initials' => 'س م',
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );
        $lawyer2->syncRoles(['محامٍ']);
        $lawyer2->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['lawyer'])->get());

        $lawyer3 = User::updateOrCreate(
            ['email' => 'turki.lawyer@salasel.sa'],
            [
                'name' => 'أ. تركي الشمري',
                'national_id' => '1000000014',
                'phone' => '+966501110014',
                'role' => Role::Lawyer,
                'title' => 'أ.',
                'job_title' => 'مستشار القضايا العمالية والتنفيذ',
                'department' => 'القضايا العمالية',
                'work_start' => '09:00',
                'work_end' => '17:00',
                'avatar_initials' => 'ت ش',
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );
        $lawyer3->syncRoles(['محامٍ']);
        $lawyer3->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['lawyer'])->get());

        $lawyer4 = User::updateOrCreate(
            ['email' => 'reem.lawyer@salasel.sa'],
            [
                'name' => 'أ. ريم الخالدي',
                'national_id' => '1000000015',
                'phone' => '+966501110015',
                'role' => Role::Lawyer,
                'title' => 'أ.',
                'job_title' => 'مستشارة الأحوال الشخصية والتركات',
                'department' => 'الأحوال الشخصية',
                'work_start' => '09:00',
                'work_end' => '17:00',
                'avatar_initials' => 'ر خ',
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );
        $lawyer4->syncRoles(['محامٍ']);
        $lawyer4->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['lawyer'])->get());

        // موظفو خدمة العملاء والتنسيق
        $emp1 = User::updateOrCreate(
            ['email' => 'nasser.emp@salasel.sa'],
            [
                'name' => 'ناصر الحربي',
                'national_id' => '1000000021',
                'phone' => '+966501110021',
                'role' => Role::Employee,
                'job_title' => 'مسؤول استقبال وتنسيق الجلسات',
                'department' => 'خدمة العملاء',
                'work_start' => '08:00',
                'work_end' => '16:00',
                'avatar_initials' => 'ن ح',
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );
        $emp1->syncRoles(['خدمة عملاء']);
        $emp1->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get());

        $emp2 = User::updateOrCreate(
            ['email' => 'munira.emp@salasel.sa'],
            [
                'name' => 'منيرة العتيبي',
                'national_id' => '1000000022',
                'phone' => '+966501110022',
                'role' => Role::Employee,
                'job_title' => 'أخصائية جدولة وتوزيع التذاكر',
                'department' => 'خدمة العملاء',
                'work_start' => '08:00',
                'work_end' => '16:00',
                'avatar_initials' => 'م ع',
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );
        $emp2->syncRoles(['خدمة عملاء']);
        $emp2->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get());

        // العملاء
        $clientDefault = User::where('national_id', '1000000004')->first();
        if (! $clientDefault) {
            $clientDefault = User::create([
                'name' => 'عبدالله العتيبي',
                'email' => 'm.bander.it@gmail.com',
                'role' => Role::Client,
                'national_id' => '1000000004',
                'phone' => '+966551234567',
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]);
        }

        $client1 = User::updateOrCreate(
            ['email' => 'saad.madar@client.sa'],
            [
                'name' => 'سعد التميمي (شركة المدار القابضة)',
                'national_id' => '1000000031',
                'phone' => '+966502220031',
                'role' => Role::Client,
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );

        $client2 = User::updateOrCreate(
            ['email' => 'khalid.ofuq@client.sa'],
            [
                'name' => 'خالد بن سلطان (شركة الأفق العقارية)',
                'national_id' => '1000000032',
                'phone' => '+966502220032',
                'role' => Role::Client,
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );

        $client3 = User::updateOrCreate(
            ['email' => 'faisal.benaa@client.sa'],
            [
                'name' => 'فيصل العلي (مؤسسة البناء للمقاولات)',
                'national_id' => '1000000033',
                'phone' => '+966502220033',
                'role' => Role::Client,
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );

        $client4 = User::updateOrCreate(
            ['email' => 'fatima.zahrani@client.sa'],
            [
                'name' => 'د. فاطمة الزهراني',
                'national_id' => '1000000034',
                'phone' => '+966502220034',
                'role' => Role::Client,
                'status' => 'active',
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );

        $mainLawyer = User::where('national_id', '1000000002')->first() ?? $lawyer1;

        // ── 2. إنشاء تذاكر متنوعة ومكتملة البيانات ──
        $ticketsData = [
            [
                'number' => 'SB-2026-101',
                'user_id' => $client1->id,
                'type' => 'تأسيس شركات',
                'department' => 'قضايا الشركات',
                'subject' => 'صياغة اتفاقية شركاء وتعديل عقد تأسيس شركة ذات مسؤولية محدودة',
                'priority' => 'عالية',
                'status' => 'قيد المعالجة',
                'tone' => 'b-blue',
                'assigned_lawyer' => $lawyer1->name,
                'assigned_lawyer_id' => $lawyer1->id,
                'last_message' => 'تم استلام مسودة العقد ونعمل على مراجعة بنود التخارج وحصص الأرباح.',
                'attachments' => 3,
            ],
            [
                'number' => 'SB-2026-102',
                'user_id' => $client2->id,
                'type' => 'نزاع عقاري',
                'department' => 'العقارات والمقاولات',
                'subject' => 'مطالبة بإلغاء شرط جزائي في عقد تطوير عقاري بمدينة الرياض',
                'priority' => 'عالية',
                'status' => 'بانتظار مستندات',
                'tone' => 'b-amber',
                'assigned_lawyer' => $lawyer2->name,
                'assigned_lawyer_id' => $lawyer2->id,
                'last_message' => 'يرجى تزويدنا بالمخطط المعتمد وإشعار الاستلام من المطور العقاري.',
                'attachments' => 1,
            ],
            [
                'number' => 'SB-2026-103',
                'user_id' => $client3->id,
                'type' => 'مطالبة مالية',
                'department' => 'القضايا العمالية',
                'subject' => 'تسوية مستحقات عمالية مجمعة وإنهاء عقود تشغيلية',
                'priority' => 'متوسطة',
                'status' => 'محالة للقسم القانوني',
                'tone' => 'b-cyan',
                'assigned_lawyer' => $lawyer3->name,
                'assigned_lawyer_id' => $lawyer3->id,
                'last_message' => 'أحيلت التذكرة إلى المستشار تركي لدراسة مطابقة التسوية لنظام العمل.',
                'attachments' => 2,
            ],
            [
                'number' => 'SB-2026-104',
                'user_id' => $client4->id,
                'type' => 'قسمة تركات',
                'department' => 'الأحوال الشخصية',
                'subject' => 'حصر إرث وقسمة رضائية لعقارات ومحافظ استثمارية',
                'priority' => 'متوسطة',
                'status' => 'موعد مؤكد',
                'tone' => 'b-green',
                'assigned_lawyer' => $lawyer4->name,
                'assigned_lawyer_id' => $lawyer4->id,
                'last_message' => 'تم تأكيد موعد الاستشارة الحضورية بالمكتب يوم غد.',
                'attachments' => 4,
            ],
            [
                'number' => 'SB-2026-105',
                'user_id' => $clientDefault->id,
                'type' => 'نزاع تجاري',
                'department' => 'قضايا الشركات',
                'subject' => 'دعوى إخلال بالتزامات عقد توريد برمجيات ومطالبة بالتعويض',
                'priority' => 'عالية',
                'status' => 'مكتملة',
                'tone' => 'b-green',
                'assigned_lawyer' => $mainLawyer->name,
                'assigned_lawyer_id' => $mainLawyer->id,
                'last_message' => 'صدرت اللائحة المعتمدة وتم تحويل الملف إلى قضية جارية بالمحكمة التجارية.',
                'attachments' => 5,
            ],
            [
                'number' => 'SB-2026-106',
                'user_id' => $client1->id,
                'type' => 'استشارة تجارية',
                'department' => 'قضايا الشركات',
                'subject' => 'مراجعة الامتثال للائحة حوكمة الشركات المدرجة',
                'priority' => 'منخفضة',
                'status' => 'جديدة',
                'tone' => 'b-amber',
                'assigned_lawyer' => null,
                'assigned_lawyer_id' => null,
                'last_message' => 'تم فتح التذكرة بانتظار التوجيه والإسناد إلى المستشار المختص.',
                'attachments' => 1,
            ],
            [
                'number' => 'SB-2026-107',
                'user_id' => $client3->id,
                'type' => 'تحكيم تجاري',
                'department' => 'العقارات والمقاولات',
                'subject' => 'طلب تشكيل هيئة تحكيم في نزاع مشروع أبراج الفيروز',
                'priority' => 'عالية',
                'status' => 'قيد المعالجة',
                'tone' => 'b-blue',
                'assigned_lawyer' => $lawyer2->name,
                'assigned_lawyer_id' => $lawyer2->id,
                'last_message' => 'تم إعداد وثيقة التحكيم المبدئية ومراسلة المركز السعودي للتحكيم.',
                'attachments' => 3,
            ],
        ];

        foreach ($ticketsData as $td) {
            $t = Ticket::updateOrCreate(['number' => $td['number']], $td);

            // إضافة رسائل حوارية واقعية لكل تذكرة
            if ($t->messages()->count() === 0) {
                TicketMessage::create([
                    'ticket_id' => $t->id,
                    'who' => 'client',
                    'name' => $t->user?->name ?? 'العميل',
                    'role' => 'العميل',
                    'body' => e($t->subject).'<br>نرجو الاطلاع والإفادة بالرأي القانوني وتوجيهنا بالإجراءات النظامية.',
                    'time_label' => '09:15 ص',
                ]);

                TicketMessage::create([
                    'ticket_id' => $t->id,
                    'who' => 'ai',
                    'name' => 'المساعد الذكي',
                    'role' => 'استقبال وتحليل',
                    'body' => 'تم استلام طلبكم وتصنيفه تحت اختصاص «'.e($t->department).'». جاري الفرز وتوجيه المعاملة للمستشار المختص.',
                    'time_label' => '09:16 ص',
                ]);

                if ($t->assigned_lawyer) {
                    TicketMessage::create([
                        'ticket_id' => $t->id,
                        'who' => 'staff',
                        'name' => $t->assigned_lawyer,
                        'role' => 'المستشار القانوني',
                        'body' => 'مرحباً بكم. اطلعنا على حيثيات الطلب والمرفقات الأولية، ونعمل على استكمال الدراسة وإعداد التوصيات اللازمة.',
                        'time_label' => '10:30 ص',
                    ]);
                }
            }
        }

        // ── 3. إنشاء ملفات القضايا والجلسات القضائية الحية ──
        $casesData = [
            [
                'number' => 'CASE-2026-701',
                'user_id' => $clientDefault->id,
                'type' => 'تجاري',
                'department' => 'قضايا الشركات',
                'assigned_lawyer' => $mainLawyer->name,
                'assigned_lawyer_id' => $mainLawyer->id,
                'status' => 'منظورة',
                'tone' => 'b-blue',
                'pleading_status' => 'approved',
                'update_text' => 'تم تقديم المذكرة الجوابية وإرفاق كشوف الحسابات المصرفية.',
                'fee' => 45000,
                'fee_status' => 'paid',
                'court_name' => 'المحكمة التجارية بالرياض - الدائرة الرابعة',
            ],
            [
                'number' => 'CASE-2026-702',
                'user_id' => $client2->id,
                'type' => 'عقاري',
                'department' => 'العقارات والمقاولات',
                'assigned_lawyer' => $lawyer2->name,
                'assigned_lawyer_id' => $lawyer2->id,
                'status' => 'منظورة',
                'tone' => 'b-blue',
                'pleading_status' => 'approved',
                'update_text' => 'تم إيداع تقرير الخبير الهندسي المعتمد لدى قلم المحكمة.',
                'fee' => 60000,
                'fee_status' => 'installments',
                'court_name' => 'المحكمة العامة بالرياض - الدائرة العقارية الثانية',
            ],
            [
                'number' => 'CASE-2026-703',
                'user_id' => $client3->id,
                'type' => 'عمالي',
                'department' => 'القضايا العمالية',
                'assigned_lawyer' => $lawyer3->name,
                'assigned_lawyer_id' => $lawyer3->id,
                'status' => 'قيد التحضير',
                'tone' => 'b-blue',
                // مفعَّلةٌ بانتظار اعتماد اللائحة — كانت `none` فتعلق: لا اعتماد ولا حكم
                'pleading_status' => 'pending_lawyer',
                'update_text' => 'جاري مراجعة عقود العمل وسجلات الحضور والانصراف لإيداع اللائحة.',
                'fee' => 25000,
                'fee_status' => 'paid',
                'court_name' => 'المحكمة العمالية بجدة - الدائرة السادسة',
            ],
            [
                'number' => 'CASE-2026-704',
                'user_id' => $client1->id,
                'type' => 'شركات',
                'department' => 'قضايا الشركات',
                'assigned_lawyer' => $lawyer1->name,
                'assigned_lawyer_id' => $lawyer1->id,
                'status' => 'صدر الحكم',
                'tone' => 'b-cyan',
                'pleading_status' => 'approved',
                'update_text' => 'صدر حكم قطعي لصالح موكلنا بإلزام المدعى عليه بسداد كامل المبلغ مع أتعاب المحاماة.',
                'ruling' => 'إلزام المدعى عليه بدفع مبلغ 1,250,000 ريال سعودي ومبلغ 80,000 ريال أتعاب محاماة.',
                'fee' => 80000,
                'fee_status' => 'paid',
                'court_name' => 'المحكمة التجارية بالدمام - الدائرة الأولى',
            ],
        ];

        foreach ($casesData as $cd) {
            $courtName = $cd['court_name'];
            unset($cd['court_name']);

            $c = LegalCase::updateOrCreate(['number' => $cd['number']], $cd);

            // إنشاء جلسات محكمة حية
            if ($c->hearings()->count() === 0) {
                if ($c->status === 'منظورة') {
                    // جلسة قادمة اليوم أو قريباً
                    CaseHearing::create([
                        'case_id' => $c->id,
                        'title' => 'جلسة المرافعة وتقديم المستندات الختامية',
                        'day' => 'اليوم',
                        'time' => '10:30 ص',
                        'court' => $courtName,
                        'status' => 'مجدولة',
                        'starts_at' => now()->setHour(10)->setMinute(30),
                    ]);

                    CaseHearing::create([
                        'case_id' => $c->id,
                        'title' => 'جلسة تحرير الدعوى والجواب',
                        'day' => 'الأسبوع الماضي',
                        'time' => '09:00 ص',
                        'court' => $courtName,
                        'status' => 'منعقدة',
                        'outcome' => 'تم استلام رد المدعى عليه وتحديد مهلة 10 أيام للتعقيب.',
                        'starts_at' => now()->subDays(7)->setHour(9)->setMinute(0),
                    ]);
                }

                CaseMessage::create([
                    'case_id' => $c->id,
                    'who' => 'staff',
                    'name' => $c->assigned_lawyer,
                    'role' => 'المستشار القانوني',
                    'body' => e($c->update_text),
                    'time_label' => '11:00 ص',
                ]);
            }
        }

        // ── 4. إنشاء استشارات ومواعيد حية (حضورية، مرئية، هاتفية) ──
        $todayAt11 = now()->setHour(11)->setMinute(0);
        $todayAt14 = now()->setHour(14)->setMinute(30);
        $tomorrowAt10 = now()->addDay()->setHour(10)->setMinute(0);

        // موعد 1: مرئي اليوم
        $appt1 = Appointment::updateOrCreate(
            ['ext_id' => 'APT-2026-801'],
            [
                'user_id' => $client1->id,
                'type' => 'استشارة مرئية',
                'ico' => 'video',
                'lawyer' => $lawyer1->name,
                'lawyer_id' => $lawyer1->id,
                'day' => 'اليوم',
                'time' => '11:00 ص',
                'starts_at' => $todayAt11,
                'duration_min' => 45,
                'place' => 'غرفة الاجتماعات المرئية (عن بُعد)',
                'status' => 'مؤكد',
                'tone' => 'b-green',
                'when_kind' => 'today',
            ]
        );

        Consult::updateOrCreate(
            ['appointment_id' => $appt1->id],
            [
                'user_id' => $client1->id,
                'ref' => 'CN-2026-801',
                'subject' => 'استشارة تنظيمية في الاستحواذ والاندماج التجاري',
                'type' => 'قضايا الشركات',
                'channel' => 'مرئية',
                'priority' => 'عالية',
                'lawyer' => $lawyer1->name,
                'assigned_lawyer_id' => $lawyer1->id,
                'specialty' => 'قضايا الشركات',
                'employee' => $emp1->name,
                'day' => 'اليوم',
                'time' => '11:00 ص',
                'starts_at' => $todayAt11,
                'duration_min' => 45,
                'meet_link' => 'https://meet.google.com/law-salasel-meet',
                'host_link' => 'https://meet.google.com/law-salasel-meet',
                'link_released_at' => now()->subMinutes(10),
                'status' => 'مؤكد',
                'session' => 'بانتظار الجلسة',
                'price' => 750,
                'vat' => 112,
                'total' => 862,
                'paid_at' => now()->subHours(2),
            ]
        );

        // موعد 2: حضوري اليوم
        $appt2 = Appointment::updateOrCreate(
            ['ext_id' => 'APT-2026-802'],
            [
                'user_id' => $client2->id,
                'type' => 'استشارة حضورية',
                'ico' => 'office',
                'lawyer' => $lawyer2->name,
                'lawyer_id' => $lawyer2->id,
                'day' => 'اليوم',
                'time' => '02:30 م',
                'starts_at' => $todayAt14,
                'duration_min' => 60,
                'place' => 'المقر الرئيسي — قاعة المستشارين (الدور 4)',
                'status' => 'مؤكد',
                'tone' => 'b-green',
                'when_kind' => 'today',
            ]
        );

        Consult::updateOrCreate(
            ['appointment_id' => $appt2->id],
            [
                'user_id' => $client2->id,
                'ref' => 'CN-2026-802',
                'subject' => 'دراسة عقود المقاولات ومسؤولية المقاول الرئيسي',
                'type' => 'العقارات والمقاولات',
                'channel' => 'حضورية',
                'priority' => 'عالية',
                'lawyer' => $lawyer2->name,
                'assigned_lawyer_id' => $lawyer2->id,
                'specialty' => 'العقارات والمقاولات',
                'employee' => $emp2->name,
                'day' => 'اليوم',
                'time' => '02:30 م',
                'starts_at' => $todayAt14,
                'duration_min' => 60,
                'status' => 'مؤكد',
                'session' => 'بانتظار الجلسة',
                'price' => 1000,
                'vat' => 150,
                'total' => 1150,
                'paid_at' => now()->subHours(5),
            ]
        );

        // موعد 3: هاتفي غداً
        $appt3 = Appointment::updateOrCreate(
            ['ext_id' => 'APT-2026-803'],
            [
                'user_id' => $client4->id,
                'type' => 'استشارة هاتفية',
                'ico' => 'phone',
                'lawyer' => $lawyer4->name,
                'lawyer_id' => $lawyer4->id,
                'day' => 'غداً',
                'time' => '10:00 ص',
                'starts_at' => $tomorrowAt10,
                'duration_min' => 30,
                'place' => 'اتصال هاتفي مباشر',
                'status' => 'مؤكد',
                'tone' => 'b-cyan',
                'when_kind' => 'upcoming',
            ]
        );

        Consult::updateOrCreate(
            ['appointment_id' => $appt3->id],
            [
                'user_id' => $client4->id,
                'ref' => 'CN-2026-803',
                'subject' => 'استشارة في شروط الوصية وحصر الأنصبة الشرعية',
                'type' => 'الأحوال الشخصية',
                'channel' => 'هاتفية',
                'priority' => 'متوسطة',
                'lawyer' => $lawyer4->name,
                'assigned_lawyer_id' => $lawyer4->id,
                'specialty' => 'الأحوال الشخصية',
                'employee' => $emp1->name,
                'day' => 'غداً',
                'time' => '10:00 ص',
                'starts_at' => $tomorrowAt10,
                'duration_min' => 30,
                'phone' => $client4->phone,
                'status' => 'مؤكد',
                'session' => 'بانتظار الجلسة',
                'price' => 500,
                'vat' => 75,
                'total' => 575,
                'paid_at' => now()->subHours(1),
            ]
        );

        // ── 5. إنشاء ملفات التنفيذ القضائي ──
        Execution::updateOrCreate(
            ['number' => 'EXEC-2026-901'],
            [
                'user_id' => $client1->id,
                'number' => 'EXEC-2026-901',
                'subject' => 'تنفيذ سند لأمر صادر ضد شركة عبر الخليج للتجارة',
                'court' => 'محكمة التنفيذ بالرياض - الدائرة الخامسة',
                'assigned_lawyer' => $lawyer3->name,
                'assigned_lawyer_id' => $lawyer3->id,
                'defendant' => 'شركة عبر الخليج للتجارة العامة',
                'amount' => 850000,
                'sanad' => 'سند لأمر واجب النفاذ رقم 4410293',
                'stage' => 4,
                'status' => 'قيد التنفيذ',
                'tone' => 'b-cyan',
                'last_action' => 'تم صدور قرار المادة 46 بإيقاف الخدمات والحجز على الحسابات.',
                'fee' => 35000,
                'vat' => 5250,
                'paid' => true,
                'paid_at' => now()->subDays(10),
            ]
        );

        Execution::updateOrCreate(
            ['number' => 'EXEC-2026-902'],
            [
                'user_id' => $client3->id,
                'number' => 'EXEC-2026-902',
                'subject' => 'تنفيذ حكم قضائي نهائي بالتعويض عن تأخير المشروع',
                'court' => 'محكمة التنفيذ بالدمام - الدائرة الثانية',
                'assigned_lawyer' => $lawyer3->name,
                'assigned_lawyer_id' => $lawyer3->id,
                'defendant' => 'مؤسسة الرواد للمقاولات العامة',
                'amount' => 420000,
                'sanad' => 'صك حكم قطعي صادر من المحكمة التجارية',
                'stage' => 3,
                'status' => 'قيد التنفيذ',
                'tone' => 'b-blue',
                'last_action' => 'تم تبليغ المنفذ ضده بالقرار 34 عبر منصة إيفاء.',
                'fee' => 20000,
                'vat' => 3000,
                'paid' => true,
                'paid_at' => now()->subDays(5),
            ]
        );

        // ── 6. إنشاء فواتير نظامية ──
        Invoice::updateOrCreate(
            ['number' => 'INV-2026-501'],
            [
                'user_id' => $client1->id,
                'number' => 'INV-2026-501',
                'description' => 'أتعاب استشارة مرئية ومراجعة عقود الاندماج (CN-2026-801)',
                'amount' => 862,
                'status' => 'مسددة',
                'tone' => 'b-green',
                'due_label' => 'مسددة',
                'due_at' => now(),
                'paid' => true,
            ]
        );

        Invoice::updateOrCreate(
            ['number' => 'INV-2026-502'],
            [
                'user_id' => $client2->id,
                'number' => 'INV-2026-502',
                'description' => 'أتعاب استشارة حضورية متخصصة في نزاعات المقاولات (CN-2026-802)',
                'amount' => 1150,
                'status' => 'مسددة',
                'tone' => 'b-green',
                'due_label' => 'مسددة',
                'due_at' => now(),
                'paid' => true,
            ]
        );
    }
}
