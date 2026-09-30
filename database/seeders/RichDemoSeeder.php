<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\CaseMessage;
use App\Models\Consult;
use App\Models\Document;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\AppEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * بذرة غزيرة لكل تبويبات المنصة — بيانات متنوّعة تغطّي دورات الحياة كاملةً.
 *
 * تكمّل DemoDataSeeder (لا تحلّ محلّه): تضيف كمّاً وتنوّعاً — حالات ماضية وفائتة
 * وجارية وقادمة وملغاة — كي تظهر كل شاشة بواقعها لا بصفّ واحد.
 *
 * معتمِدة (idempotent): كل سجلّ بمفتاح فريد (number/ref/ext_id) عبر updateOrCreate،
 * فإعادة تشغيلها آمنة ولا تكرّر شيئاً.
 *
 * التشغيل: php artisan db:seed --class=RichDemoSeeder
 * (تفترض تشغيل DatabaseSeeder مسبقاً — الحسابات الأساسية 1000000001..1000000004)
 */
class RichDemoSeeder extends Seeder
{
    public function run(): void
    {
        // بياناتٌ تجريبيّة (حسابات بكلمة مرورٍ موحّدة وسجلّاتٌ وهميّة) — لا تُحقن في قاعدة الإنتاج أبداً
        if (AppEnvironment::isProduction()) {
            $this->command->error('بيئة إنتاج: البيانات التجريبيّة لا تُبذَر هنا.');

            return;
        }

        // ── 0. الحسابات المرجعية ──
        $mainLawyer = User::where('national_id', '1000000002')->firstOrFail();
        $employee = User::where('national_id', '1000000003')->firstOrFail();
        $mainClient = User::where('national_id', '1000000004')->firstOrFail();

        // محامو DemoDataSeeder إن وُجدوا — وإلا المحامي الأساسي
        $lawyers = collect([
            User::where('email', 'fahad.lawyer@salasel.sa')->first(),
            User::where('email', 'sara.lawyer@salasel.sa')->first(),
            User::where('email', 'turki.lawyer@salasel.sa')->first(),
        ])->filter()->values();
        if ($lawyers->isEmpty()) {
            $lawyers = collect([$mainLawyer]);
        }
        $L = fn (int $i): User => $lawyers[$i % $lawyers->count()];

        // ── 1. ستة عملاء إضافيون ──
        $password = Hash::make('password');
        $clientRows = [
            ['rich1@demo.sa', 'شركة أفق النقل اللوجستي', '1000000041', '+966501230031'],
            ['rich2@demo.sa', 'عبدالله بن ناصر الحربي', '1000000042', '+966501230032'],
            ['rich3@demo.sa', 'مؤسسة البنيان للتطوير العقاري', '1000000043', '+966501230033'],
            ['rich4@demo.sa', 'نورة بنت سعد القحطاني', '1000000044', '+966501230034'],
            ['rich5@demo.sa', 'شركة المذاق الأصيل للأغذية', '1000000045', '+966501230035'],
            ['rich6@demo.sa', 'خالد بن فيصل العتيبي', '1000000046', '+966501230036'],
        ];
        $clients = collect($clientRows)->map(fn (array $r) => User::updateOrCreate(
            ['email' => $r[0]],
            [
                'name' => $r[1], 'national_id' => $r[2], 'phone' => $r[3],
                'role' => Role::Client, 'status' => 'active',
                'avatar_initials' => mb_substr($r[1], 0, 1),
                'password' => $password, 'email_verified_at' => now(),
            ]
        ))->prepend($mainClient)->values();
        $C = fn (int $i): User => $clients[$i % $clients->count()];

        // ── 2. التذاكر: 14 تذكرة عبر كل الحالات ──
        $ticketDefs = [
            // [الحالة، النغمة، النوع، القسم، الموضوع، الأولوية، محامٍ؟]
            ['جديدة', 'b-amber', 'استشارة تجارية', 'قضايا الشركات', 'مراجعة اتفاقية امتياز تجاري لعلامة مطاعم', 'متوسطة', false],
            ['جديدة', 'b-amber', 'نزاع إيجاري', 'العقارات والمقاولات', 'نزاع على إعادة تأهيل مستودعات مؤجرة بعقد إيجار تمويلي', 'عالية', false],
            ['قيد المعالجة', 'b-blue', 'صياغة عقود', 'قضايا الشركات', 'صياغة عقد شراكة استراتيجية مع مستثمر أجنبي', 'عالية', true],
            ['قيد المعالجة', 'b-blue', 'قضية عمالية', 'القضايا العمالية', 'دعوى فصل تعسفي ومطالبة ببدل إشعار ومكافأة نهاية خدمة', 'عالية', true],
            ['قيد المعالجة', 'b-blue', 'حوكمة', 'قضايا الشركات', 'إعداد لائحة حوكمة داخلية وسياسة تعارض المصالح', 'منخفضة', true],
            ['بانتظار مستندات', 'b-amber', 'نزاع عقاري', 'العقارات والمقاولات', 'مطالبة بفروقات مساحة في وحدة سكنية على الخارطة', 'متوسطة', true],
            ['بانتظار مستندات', 'b-amber', 'قسمة تركات', 'الأحوال الشخصية', 'حصر ورثة وقسمة أسهم شركة عائلية قابضة', 'عالية', true],
            ['محالة للقسم القانوني', 'b-cyan', 'مطالبة مالية', 'القضايا العمالية', 'تحصيل مستحقات مقاول باطن من مقاول رئيسي متعثّر', 'عالية', true],
            ['محالة للقسم القانوني', 'b-cyan', 'تحكيم تجاري', 'قضايا الشركات', 'تفعيل شرط التحكيم في نزاع توريد معدات صناعية', 'متوسطة', true],
            ['موعد مؤكد', 'b-green', 'استشارة عقارية', 'العقارات والمقاولات', 'استشارة في تسجيل اتحاد ملّاك وتنظيم رسوم الخدمات', 'منخفضة', true],
            ['موعد مؤكد', 'b-green', 'استشارة أحوال', 'الأحوال الشخصية', 'استشارة في اشتراطات الوصية الواجبة وتوثيقها', 'متوسطة', true],
            ['مكتملة', 'b-green', 'نزاع تجاري', 'قضايا الشركات', 'إنهاء نزاع شيكات مرتجعة بالصلح وتوثيق اتفاقية سداد', 'عالية', true],
            ['مكتملة', 'b-green', 'صياغة عقود', 'العقارات والمقاولات', 'صياغة عقد مقاولة بنظام تسليم المفتاح لمجمع سكني', 'متوسطة', true],
            ['مغلقة', 'b-grey', 'استشارة عامة', 'خدمة العملاء', 'استفسار عام عن إجراءات توثيق وكالة تجارية', 'منخفضة', false],
        ];
        foreach ($ticketDefs as $i => $d) {
            $lw = $d[6] ? $L($i) : null;
            $t = Ticket::updateOrCreate(['number' => sprintf('SB-2026-2%02d', $i + 1)], [
                'user_id' => $C($i)->id,
                'type' => $d[2], 'department' => $d[3], 'subject' => $d[4],
                'priority' => $d[5], 'status' => $d[0], 'tone' => $d[1],
                'assigned_lawyer' => $lw?->name, 'assigned_lawyer_id' => $lw?->id,
                'last_message' => 'آخر تحديث: '.mb_substr($d[4], 0, 80),
                'attachments' => ($i % 4) + 1,
                'date_label' => now()->subDays(21 - $i)->format('Y-m-d'),
            ]);
            if ($t->messages()->count() === 0) {
                TicketMessage::create([
                    'ticket_id' => $t->id, 'who' => 'client', 'name' => $t->user?->name ?? 'العميل',
                    'role' => 'العميل', 'body' => e($d[4]).'<br>نأمل دراسة الطلب وإفادتنا بالإجراء النظامي.',
                    'time_label' => '09:00 ص',
                ]);
                TicketMessage::create([
                    'ticket_id' => $t->id, 'who' => 'ai', 'name' => 'المساعد الذكي', 'role' => 'استقبال وتحليل',
                    'body' => 'تم التصنيف تحت «'.e($d[3]).'» وجارٍ توجيه المعاملة.', 'time_label' => '09:01 ص',
                ]);
                if ($lw) {
                    TicketMessage::create([
                        'ticket_id' => $t->id, 'who' => 'staff', 'name' => $lw->name, 'role' => 'المستشار القانوني',
                        'body' => 'تم الاطلاع على الطلب والمرفقات، وسنوافيكم بالدراسة الأولية خلال يومي عمل.',
                        'time_label' => '11:20 ص',
                    ]);
                }
            }
        }

        // ── 3. القضايا: 8 قضايا + جلسات ماضية (منعقدة/فائتة) وقادمة ──
        $caseDefs = [
            // [الحالة، النغمة، النوع، القسم، الأتعاب، حالة الأتعاب، المحكمة، حكم؟]
            ['قيد التحضير', 'b-amber', 'تجاري', 'قضايا الشركات', 30000, 'بانتظار السداد', 'المحكمة التجارية بالرياض - الدائرة الثانية', null],
            ['قيد التحضير', 'b-amber', 'عقاري', 'العقارات والمقاولات', 55000, 'دفعة أولى مسددة', 'المحكمة العامة بجدة - الدائرة العقارية الأولى', null],
            ['منظورة', 'b-cyan', 'عمالي', 'القضايا العمالية', 22000, 'مسددة بالكامل', 'المحكمة العمالية بالرياض - الدائرة الثالثة', null],
            ['منظورة', 'b-cyan', 'تجاري', 'قضايا الشركات', 90000, 'دفعة أولى مسددة', 'المحكمة التجارية بالرياض - الدائرة التاسعة', null],
            ['منظورة', 'b-cyan', 'عقاري', 'العقارات والمقاولات', 70000, 'مسددة بالكامل', 'المحكمة العامة بالدمام - الدائرة العقارية الثالثة', null],
            ['منظورة', 'b-cyan', 'أحوال شخصية', 'الأحوال الشخصية', 18000, 'مسددة بالكامل', 'محكمة الأحوال الشخصية بالرياض - الدائرة الخامسة', null],
            ['صدر الحكم', 'b-green', 'تجاري', 'قضايا الشركات', 65000, 'مسددة بالكامل', 'المحكمة التجارية بجدة - الدائرة السادسة', 'إلزام المدعى عليه بسداد 2,400,000 ريال مع أتعاب المحاماة.'],
            ['صدر الحكم', 'b-green', 'عمالي', 'القضايا العمالية', 15000, 'مسددة بالكامل', 'المحكمة العمالية بالدمام - الدائرة الأولى', 'إلزام المنشأة بصرف مكافأة نهاية الخدمة وبدل الإجازات 84,500 ريال.'],
        ];
        foreach ($caseDefs as $i => $d) {
            $lw = $L($i);
            $c = LegalCase::updateOrCreate(['number' => sprintf('CASE-2026-8%02d', $i + 1)], [
                'user_id' => $C($i + 2)->id,
                'type' => $d[2], 'department' => $d[3],
                'assigned_lawyer' => $lw->name, 'assigned_lawyer_id' => $lw->id,
                'status' => $d[0], 'tone' => $d[1],
                'update_text' => 'تحديث دوري: الملف يسير وفق الخطة الإجرائية المعتمدة.',
                'fee' => $d[4], 'fee_status' => $d[5],
                'ruling' => $d[7],
            ]);
            if ($c->hearings()->count() === 0) {
                // جلسة منعقدة (ماضية بنتيجة)
                CaseHearing::create([
                    'case_id' => $c->id, 'title' => 'جلسة تحرير الدعوى والجواب',
                    'day' => now()->subDays(20 + $i)->format('Y-m-d'), 'time' => '09:30 ص',
                    'court' => $d[6], 'status' => 'منعقدة',
                    'outcome' => 'تم تبادل المذكرات وتحديد الجلسة القادمة.',
                    'starts_at' => now()->subDays(20 + $i)->setTime(9, 30),
                ]);
                if ($d[0] === 'منظورة') {
                    // جلسة فائتة (مجدولة في الماضي — تظهر «فائتة — بانتظار النتيجة»)
                    CaseHearing::create([
                        'case_id' => $c->id, 'title' => 'جلسة استكمال المرافعة',
                        'day' => now()->subDays(3)->format('Y-m-d'), 'time' => '10:00 ص',
                        'court' => $d[6], 'status' => 'مجدولة',
                        'starts_at' => now()->subDays(3)->setTime(10, 0),
                    ]);
                    // جلسة قادمة
                    CaseHearing::create([
                        'case_id' => $c->id, 'title' => 'جلسة المرافعة الختامية وسماع البيّنات',
                        'day' => now()->addDays(4 + $i * 3)->format('Y-m-d'), 'time' => '11:00 ص',
                        'court' => $d[6], 'status' => 'مجدولة',
                        'starts_at' => now()->addDays(4 + $i * 3)->setTime(11, 0),
                    ]);
                }
                CaseMessage::create([
                    'case_id' => $c->id, 'who' => 'staff', 'name' => $lw->name,
                    'role' => 'المستشار القانوني', 'body' => e($c->update_text), 'time_label' => '01:00 م',
                ]);
            }
        }

        // ── 4. الاستشارات: 12 عبر دورة الحياة كاملة ──
        $consultDefs = [
            // [ref، القناة، الحالة، الجلسة، الموعد (أيام من الآن أو null)، مسعّرة، مدفوعة]
            ['CN-2026-901', 'مرئية', 'بانتظار التسعير', 'بانتظار الجلسة', null, false, false],
            ['CN-2026-902', 'هاتفية', 'بانتظار التسعير', 'بانتظار الجلسة', null, false, false],
            ['CN-2026-903', 'حضورية', 'بانتظار التسعير', 'بانتظار الجلسة', null, false, false],
            ['CN-2026-904', 'مرئية', 'بانتظار السداد', 'بانتظار الجلسة', null, true, false],
            ['CN-2026-905', 'حضورية', 'بانتظار السداد', 'بانتظار الجلسة', null, true, false],
            // **`'مؤكد'` لا يكتبها أيّ مسارٍ على الاستشارة** — تُكتب على الموعد
            // المرافق، والاستشارة تُضبط «جديدة» (`ConsultBooking:308`). فكانت البذرة
            // تُولّد حالةً ميتةً يقارنها الكانبان ولا تصل إليها المنظومة أبداً.
            ['CN-2026-906', 'مرئية', 'جاهزة للمحامي', 'بانتظار الجلسة', 2, true, true],
            ['CN-2026-907', 'حضورية', 'محالة للمحامي', 'بانتظار الجلسة', 5, true, true],
            ['CN-2026-908', 'هاتفية', 'محالة للمحامي', 'بانتظار الجلسة', 9, true, true],
            ['CN-2026-909', 'مرئية', 'منتهية', 'منتهية', -6, true, true],
            ['CN-2026-910', 'حضورية', 'منتهية', 'منتهية', -14, true, true],
            ['CN-2026-911', 'مرئية', 'محالة للمحامي', 'بانتظار الجلسة', -1, true, true], // فائتة — تُشتق «لم يحضر» حيّاً
            ['CN-2026-912', 'هاتفية', 'ملغاة', 'بانتظار الجلسة', null, false, false],
        ];
        $subjects = [
            'استشارة في هيكلة شراكة تقنية ناشئة', 'استشارة في مخالفات نظام العمل ولائحة الجزاءات',
            'استشارة في ضمانات عقود التطوير العقاري', 'استشارة في تحصيل ديون تجارية متعثرة',
            'استشارة في صياغة اتفاقية سرّية وعدم منافسة', 'استشارة في تسوية نزاع شركاء بالتراضي',
            'استشارة في حقوق المستأجر التجاري عند الإخلاء', 'استشارة في التزامات المقاول بعد التسليم',
            'استشارة في إجراءات الحضانة والنفقة', 'استشارة في توثيق الوقف الأهلي وشروطه',
            'استشارة في المسؤولية عن عيوب المبيع', 'استشارة في اعتراض على قرار لجنة عمالية',
        ];
        foreach ($consultDefs as $i => $d) {
            [$ref, $channel, $status, $session, $days, $priced, $paid] = $d;
            $lw = $L($i);
            $cl = $C($i);
            $at = $days === null ? null : now()->addDays($days)->setTime(10 + ($i % 5), 0);
            $price = 400 + ($i % 4) * 150;

            $apptId = null;
            if ($at !== null && $status !== 'ملغاة') {
                // موعد مرافق للاستشارات المجدولة — كما ينشئه الحجز الفعلي
                $appt = Appointment::updateOrCreate(['ext_id' => 'AP-'.$ref], [
                    'user_id' => $cl->id,
                    'type' => 'استشارة '.$channel,
                    'ico' => $channel === 'مرئية' ? 'video' : ($channel === 'هاتفية' ? 'phone' : 'office'),
                    'lawyer' => $lw->name, 'lawyer_id' => $lw->id,
                    'day' => $at->format('Y-m-d'), 'time' => $at->format('h:i A'),
                    'starts_at' => $at, 'duration_min' => 45,
                    'place' => $channel === 'حضورية' ? 'المقر الرئيسي — قاعة المستشارين' : 'جلسة عن بُعد',
                    'status' => 'مؤكد', 'tone' => 'b-green',
                    'when_kind' => $days >= 0 ? 'up' : 'past',
                ]);
                $apptId = $appt->id;
            }

            Consult::updateOrCreate(['ref' => $ref], [
                'user_id' => $cl->id,
                'appointment_id' => $apptId,
                'subject' => $subjects[$i], 'type' => 'استشارة قانونية',
                'channel' => $channel, 'priority' => ['عالية', 'متوسطة', 'منخفضة'][$i % 3],
                // العمود NOT NULL — قبل التسعير يُكتب النائب «مستشار» كما في مسار الطلب الفعلي
                'lawyer' => $status === 'بانتظار التسعير' ? 'مستشار' : $lw->name,
                'assigned_lawyer_id' => $status === 'بانتظار التسعير' ? null : $lw->id,
                'specialty' => $lw->department,
                'employee' => $employee->name,
                'day' => $at?->format('Y-m-d'), 'time' => $at?->format('h:i A'),
                'when_label' => $at ? $at->format('Y-m-d').' · '.$at->format('h:i A') : null,
                'starts_at' => $at, 'duration_min' => 45,
                'phone' => $channel === 'هاتفية' ? $cl->phone : null,
                // **المعرّف مع الرابط.** كان الرابط وحده يُبذَر، وويبهوك Zoom يُوجَّه
                // بـ`meet_id` — فصفٌّ يحمل رابطاً لا يصله حدثٌ قطّ، وهي حالةٌ لا
                // ينتجها أيّ مسار (العمودان يُكتبان معاً من مخرج `createMeeting`).
                'meet_id' => $channel === 'مرئية' && $at ? '8'.str_pad((string) (100000000 + $i), 10, '0', STR_PAD_LEFT) : null,
                'meet_link' => $channel === 'مرئية' && $at ? 'https://zoom.us/j/demo'.$i : null,
                'status' => $status, 'session' => $session,
                // **ملخّصٌ مبذور معتمَدٌ مبذور.** كان يُبذر نصٌّ يقول «وأُرسل الملخص
                // للعميل» بلا `summary_approved_at` — وهي حالةٌ **لا يبلغها أيّ مسار**:
                // محجوبةٌ عن العميل، وبلا قيدٍ في `ai_runs` فلا تظهر في صندوق المراجعة.
                // فكانت البذرة تُولّد ثلاث استشارات في مأزقٍ لا مخرج منه، ونصُّها يدّعي
                // إرسالاً لم يقع.
                'summary' => $session === 'منتهية' ? 'تمت الجلسة وقُدّمت التوصيات النظامية، وأُرسل الملخص للعميل.' : null,
                'summary_approved_at' => $session === 'منتهية' ? now()->subDays(max(abs($days ?? 1) - 1, 0)) : null,
                'summary_approved_by' => $session === 'منتهية' ? $lw->id : null,
                // **التسعير يسبق السداد دائماً.** `markPaid()` لا يُبلَغ إلّا بعد
                // `setPrice()` الذي يختم `priced_at`؛ فصفٌّ مدفوعٌ بلا تسعير يعرض
                // للعميل رحلةً مقلوبة: «سُدِّد» مضيء و«سُعِّر» مطفأ.
                'priced_at' => ($priced || $paid) ? now()->subDays(abs($days ?? 2) + 1) : null,
                'paid_at' => $paid ? now()->subDays(abs($days ?? 2)) : null,
            ] + (($priced || $paid) ? [
                // الأعمدة NOT NULL بافتراضي 0 — تُترك على الافتراضي قبل التسعير (لا NULL صريح)
                'price' => $price,
                'vat' => (int) round($price * 0.15),
                'total' => $price + (int) round($price * 0.15),
            ] : []));

            /*
             * **فاتورةٌ لكلّ مسعَّرة — كما يفعل المسار الحيّ.**
             *
             * `ConsultBooking::setPrice` يُنشئ الفاتورة **داخل المعاملة نفسها** التي
             * تكتب `priced_at`، وكذلك `create()`. فلا يقع صفٌّ مسعَّرٌ بلا فاتورة.
             * وكانت البذرة لا تُنشئ فاتورةً قطّ: عشرُ استشاراتٍ «مدفوعة» بلا فاتورة
             * يفتحها العميل، وأيّ تقرير إيرادٍ مبنيٍّ على `invoices` يُسقطها.
             */
            if ($priced || $paid) {
                $consult = Consult::where('ref', $ref)->first();
                $total = $price + (int) round($price * 0.15);

                if ($consult) {
                    Invoice::updateOrCreate(['consult_id' => $consult->id], [
                        'user_id' => $cl->id,
                        'number' => 'INV-DEMO-'.substr($ref, -4),
                        'description' => "استشارة {$ref} — {$channel}",
                        'amount' => $total,
                        'status' => $paid ? 'مدفوعة' : 'مستحقة',
                        'tone' => $paid ? 'b-green' : 'b-amber',
                        'due_label' => $paid ? '—' : 'خلال 3 أيام',
                        'due_at' => $paid ? null : now()->addDays(3)->toDateString(),
                        'paid' => $paid,
                    ]);
                }
            }
        }

        // ── 5. مواعيد مستقلّة (بلا استشارة): 6 ──
        foreach ([
            ['AP-2026-951', 'توقيع عقد أتعاب', 'office', 3, 'مؤكد', 'b-green', 'up'],
            ['AP-2026-952', 'استلام مستندات قضية', 'office', 1, 'مؤكد', 'b-green', 'up'],
            ['AP-2026-953', 'جلسة تعريفية بالمنصة', 'video', 7, 'مؤكد', 'b-cyan', 'up'],
            ['AP-2026-954', 'مراجعة مسودة لائحة', 'office', -4, 'مؤكد', 'b-green', 'past'],
            ['AP-2026-955', 'تسليم أصل صك الحكم', 'office', -10, 'مؤكد', 'b-green', 'past'],
            ['AP-2026-956', 'اجتماع متابعة تنفيذ', 'video', 12, 'مؤكد', 'b-cyan', 'up'],
        ] as $i => $d) {
            $at = now()->addDays($d[3])->setTime(9 + $i, 30);
            Appointment::updateOrCreate(['ext_id' => $d[0]], [
                'user_id' => $C($i + 1)->id, 'type' => $d[1], 'ico' => $d[2],
                'lawyer' => $L($i)->name, 'lawyer_id' => $L($i)->id,
                'day' => $at->format('Y-m-d'), 'time' => $at->format('h:i A'),
                'starts_at' => $at, 'duration_min' => 30,
                'place' => $d[2] === 'video' ? 'جلسة عن بُعد' : 'المقر الرئيسي',
                'status' => $d[4], 'tone' => $d[5], 'when_kind' => $d[6],
            ]);
        }

        // ── 6. الاجتماعات: 12 عبر كل الحالات ──
        $meetingDefs = [
            // [ref، العنوان، الحالة، أيام، عميل؟، اعتماد]
            ['MT-2026-601', 'اجتماع انطلاق قضية أبراج الوسام', 'قادم', 1, true, 'معتمد'],
            ['MT-2026-602', 'مراجعة استراتيجية الترافع الفصلية', 'قادم', 3, false, 'معتمد'],
            ['MT-2026-603', 'اجتماع تفاوض تسوية ودّية', 'قادم', 6, true, 'بانتظار الاعتماد'],
            ['MT-2026-604', 'عرض نتائج الفحص النافي للجهالة', 'قادم', 10, true, 'معتمد'],
            ['MT-2026-605', 'اجتماع الطوارئ — قرار الحجز التحفظي', 'جارٍ', 0, true, 'معتمد'],
            ['MT-2026-606', 'جلسة إغلاق ملف التحكيم', 'منتهٍ', -2, true, 'معتمد'],
            ['MT-2026-607', 'مراجعة أداء القسم العمالي', 'منتهٍ', -7, false, 'معتمد'],
            ['MT-2026-608', 'اجتماع توقيع اتفاقية الشراكة', 'منتهٍ', -15, true, 'معتمد'],
            ['MT-2026-609', 'عرض خطة تحصيل الذمم', 'لم ينعقد', -5, true, 'معتمد'],
            ['MT-2026-610', 'اجتماع تحديث لوائح الحوكمة', 'مؤجل', 8, false, 'معتمد'],
            ['MT-2026-611', 'لقاء تمهيدي مع عميل محتمل', 'مؤجل', 4, true, 'بانتظار الاعتماد'],
            ['MT-2026-612', 'اجتماع أُلغي — تعارض مواعيد', 'ملغى', 2, true, 'معتمد'],
        ];
        foreach ($meetingDefs as $i => $d) {
            $at = now()->addDays($d[3])->setTime(9 + ($i % 7), $i % 2 ? 30 : 0);
            Meeting::updateOrCreate(['ref' => $d[0]], [
                'user_id' => $C($i)->id,
                'title' => $d[1], 'type' => $d[4] ? 'مع عميل' : 'داخلي',
                'client_name' => $d[4] ? $C($i)->name : null,
                'when_label' => $at->format('Y-m-d').' · '.$at->format('h:i A'),
                'starts_at' => $at,
                'status' => $d[2], 'priority' => ['عالية', 'متوسطة'][$i % 2],
                'conf' => 'قاعة الاجتماعات '.(($i % 3) + 1),
                'attend' => 3 + ($i % 5), 'dur' => (30 + ($i % 3) * 30).' دقيقة',
                'approve' => $d[5],
                'created_by' => $employee->name,
                'assigned_lawyer_id' => $L($i)->id,
                'summary' => $d[2] === 'منتهٍ' ? 'نوقشت بنود جدول الأعمال كاملة واعتُمدت التوصيات.' : null,
            ]);
        }

        // ── 7. دعوات الاجتماعات: 8 عبر كل المراحل ──
        $stageDefs = [
            [MeetRequest::STAGE_SENT, 2], [MeetRequest::STAGE_SENT, 5],
            [MeetRequest::STAGE_CONFIRMED, 1], [MeetRequest::STAGE_CONFIRMED, 4],
            [MeetRequest::STAGE_EXECUTED, -3], [MeetRequest::STAGE_APPROVED, -8],
            [MeetRequest::STAGE_EXPIRED, -12], [MeetRequest::STAGE_CANCELLED, 3],
        ];
        foreach ($stageDefs as $i => $d) {
            $at = now()->addDays($d[1])->setTime(11 + ($i % 4), 0);
            MeetRequest::updateOrCreate(['ref' => sprintf('MR-2026-7%02d', $i + 1)], [
                'user_id' => $C($i + 2)->id,
                'service' => ['متابعة قضية', 'استشارة تعاقدية', 'تسوية ودّية', 'عرض مستجدات'][$i % 4],
                'type' => $i % 2 ? 'مرئي' : 'حضوري',
                'day' => $at->format('Y-m-d'), 'time' => $at->format('h:i A'),
                'sent_by' => $employee->name, 'sent_by_id' => $employee->id,
                'assigned_lawyer_id' => $L($i)->id, 'duration_min' => 30,
                'stage' => $d[0],
            ]);
        }

        // ── 8. التنفيذ القضائي: 6 ملفات عبر المراحل ──
        $execDefs = [
            // [المرحلة، الحالة، النغمة، المبلغ، آخر إجراء]
            [1, 'قيد الفحص', 'b-amber', 180000, 'استُلم طلب التنفيذ وجارٍ فحص السند التنفيذي.'],
            [2, 'قيد الفحص', 'b-amber', 95000, 'اكتمل الفحص الأولي وجارٍ إعداد عرض الأتعاب.'],
            [4, 'قيد التنفيذ', 'b-cyan', 650000, 'صدر أمر التنفيذ 34 وتم تبليغ المنفذ ضده.'],
            [6, 'قيد التنفيذ', 'b-cyan', 1200000, 'صدر قرار 46: إيقاف خدمات وحجز على الحسابات.'],
            [8, 'قيد التنفيذ', 'b-blue', 310000, 'جارٍ بيع المحجوزات بالمزاد وتحصيل المبالغ.'],
            [10, 'منفّذ', 'b-green', 275000, 'اكتمل التنفيذ وصُرفت المبالغ للمستفيد وأُغلق الملف.'],
        ];
        foreach ($execDefs as $i => $d) {
            Execution::updateOrCreate(['number' => sprintf('EXEC-2026-9%02d', $i + 10)], [
                'user_id' => $C($i + 1)->id,
                'subject' => 'تنفيذ '.['سند لأمر', 'شيك مرتجع', 'حكم قضائي نهائي', 'عقد موثّق', 'قرار لجنة', 'صك حكم'][$i].' — ملف رقم '.($i + 10),
                'court' => 'محكمة التنفيذ ب'.['الرياض', 'جدة', 'الدمام'][$i % 3].' - الدائرة '.(($i % 5) + 1),
                'assigned_lawyer' => $L($i)->name, 'assigned_lawyer_id' => $L($i)->id,
                'defendant' => 'المنفذ ضده — '.['شركة الروابي التجارية', 'مؤسسة النخبة للمقاولات', 'شركة آفاق الصناعة'][$i % 3],
                'amount' => $d[3], 'sanad' => 'سند تنفيذي رقم '.(4410300 + $i),
                'stage' => $d[0], 'status' => $d[1], 'tone' => $d[2],
                'last_action' => $d[4],
                'fee' => (int) ($d[3] * 0.05), 'vat' => (int) ($d[3] * 0.05 * 0.15),
                'paid' => $d[0] >= 4, 'paid_at' => $d[0] >= 4 ? now()->subDays(30 - $d[0]) : null,
            ]);
        }

        // ── 9. الفواتير: 12 (مسددة · مستحقة · متأخرة · بانتظار مراجعة الإثبات) ──
        $invoiceDefs = [
            // [الوصف، المبلغ، مدفوعة، استحقاق بالأيام، إثبات؟]
            ['أتعاب استشارة هيكلة شراكة (CN-2026-906)', 862, true, 0, false],
            ['أتعاب استشارة عقود تطوير (CN-2026-907)', 1150, true, 0, false],
            ['دفعة أولى — قضية CASE-2026-803', 11000, true, -10, false],
            ['دفعة أولى — قضية CASE-2026-804', 45000, true, -20, false],
            ['أتعاب تنفيذ EXEC-2026-913', 32500, true, -15, false],
            ['أتعاب استشارة تحصيل ديون (CN-2026-908)', 632, false, 6, false],
            ['الدفعة الثانية — قضية CASE-2026-802', 27500, false, 12, false],
            ['أتعاب إعداد لائحة حوكمة', 8500, false, 20, false],
            ['أتعاب استشارة منتهية (CN-2026-909)', 747, false, -5, false],
            ['الدفعة الختامية — قضية CASE-2026-807', 32500, false, -12, false],
            ['رسوم متابعة تنفيذ EXEC-2026-911', 4750, false, -25, false],
            ['دفعة مقدّمة — نزاع إيجاري (تحويل بنكي)', 15000, false, 3, true],
        ];
        foreach ($invoiceDefs as $i => $d) {
            $proofPath = null;
            if ($d[4]) {
                // ملفّ إثبات فعلي — كي لا يكون زرّ التنزيل طريقاً إلى 404
                $proofPath = 'proofs/demo-proof-'.($i + 1).'.txt';
                if (! Storage::exists($proofPath)) {
                    Storage::put($proofPath, 'إيصال تحويل بنكي تجريبي — فاتورة INV-2026-6'.sprintf('%02d', $i + 1));
                }
            }
            Invoice::updateOrCreate(['number' => sprintf('INV-2026-6%02d', $i + 1)], [
                'user_id' => $C($i)->id,
                'description' => $d[0], 'amount' => $d[1],
                'status' => $d[4] ? 'بانتظار مراجعة الإثبات' : ($d[2] ? 'مسددة' : 'مستحقة'),
                'tone' => $d[2] ? 'b-green' : ($d[3] < 0 ? 'b-red' : 'b-amber'),
                'due_label' => $d[2] ? 'مسددة' : 'تستحق '.now()->addDays($d[3])->format('Y-m-d'),
                'due_at' => now()->addDays($d[3]),
                'paid' => $d[2],
                'proof_path' => $proofPath,
                'proof_uploaded_at' => $proofPath ? now()->subHours(6) : null,
            ]);
        }

        // ── 10. المستندات: 8 (واردة وصادرة بملفّات فعلية) ──
        $docDefs = [
            ['صك ملكية الأرض — مخطط 2841', 'in'], ['عقد تأسيس الشركة موثّقاً', 'in'],
            ['كشف حساب بنكي — الربع الثاني', 'in'], ['صورة الهوية والسجل التجاري', 'in'],
            ['مذكرة الدفاع الجوابية — نهائية', 'out'], ['اتفاقية أتعاب موقّعة', 'out'],
            ['اللائحة الاعتراضية على الحكم', 'out'], ['ملخص الاستشارة والتوصيات', 'out'],
        ];
        foreach ($docDefs as $i => $d) {
            $path = 'documents/demo-doc-'.($i + 1).'.txt';
            if (! Storage::exists($path)) {
                Storage::put($path, 'مستند تجريبي: '.$d[0]);
            }
            Document::updateOrCreate(
                ['user_id' => $C($i)->id, 'name' => $d[0]],
                [
                    'meta' => ($d[1] === 'in' ? 'وارد من العميل' : 'صادر من المكتب').' · '.now()->subDays($i * 4)->format('Y-m-d'),
                    'direction' => $d[1], 'path' => $path, 'mime' => 'text/plain', 'size' => Storage::size($path),
                ]
            );
        }

        // ── 11. الإشعارات: لكل حساب أساسي ──
        $notifDefs = [
            [$mainClient->id, 'bell', 'b-blue', 'تم تحديد موعد جلستك القادمة — راجع تبويب التقويم والمواعيد.'],
            [$mainClient->id, 'card', 'b-amber', 'لديك فاتورة مستحقة تقترب من موعد استحقاقها.'],
            [$mainClient->id, 'doc', 'b-green', 'تم رفع مستند جديد إلى ملفك من قِبل المكتب.'],
            [$mainLawyer->id, 'cal', 'b-blue', 'أُسندت إليك استشارة جديدة — راجع جدولك.'],
            [$mainLawyer->id, 'scale', 'b-amber', 'جلسة قضية خلال 48 ساعة — جهّز المذكرة الختامية.'],
            [$employee->id, 'bell', 'b-cyan', 'دعوة اجتماع بانتظار موافقة الإدارة العليا.'],
            [$employee->id, 'check', 'b-green', 'تم اعتماد محضر اجتماع الأسبوع الماضي.'],
        ];
        foreach ($notifDefs as $i => $d) {
            UserNotification::updateOrCreate(
                ['user_id' => $d[0], 'body' => $d[3]],
                ['icon' => $d[1], 'tone' => $d[2], 'time_label' => 'قبل '.($i + 1).' ساعة', 'is_read' => $i % 3 === 0]
            );
        }

        // ── 12. المهامّ: 8 للمحامين ──
        $taskDefs = [
            ['إيداع المذكرة الجوابية — CASE-2026-803', 2, 'مفتوحة', 'b-amber'],
            ['مراجعة تقرير الخبير الهندسي', 4, 'مفتوحة', 'b-blue'],
            ['إعداد لائحة دعوى النزاع الإيجاري', 6, 'مفتوحة', 'b-blue'],
            ['متابعة قرار 46 لدى محكمة التنفيذ', 1, 'مفتوحة', 'b-red'],
            ['صياغة اتفاقية التسوية النهائية', -2, 'مفتوحة', 'b-red'],
            ['تسليم ملخص استشارة CN-2026-909', -1, 'مكتملة', 'b-green'],
            ['تحديث العميل بنتيجة الجلسة', -4, 'مكتملة', 'b-green'],
            ['أرشفة ملف التحكيم المنتهي', -7, 'مكتملة', 'b-green'],
        ];
        foreach ($taskDefs as $i => $d) {
            Task::updateOrCreate(
                ['title' => $d[0]],
                [
                    'assigned_to' => $L($i)->id, 'ref' => 'TSK-2026-'.(300 + $i),
                    'due' => $d[1] >= 0 ? 'خلال '.max($d[1], 1).' أيام' : 'متأخرة',
                    'due_at' => now()->addDays($d[1]),
                    'status' => $d[2], 'tone' => $d[3],
                    'completed_at' => $d[2] === 'مكتملة' ? now()->addDays($d[1])->subHours(5) : null,
                ]
            );
        }

        $this->command?->info('RichDemoSeeder: اكتملت البذرة الغزيرة لكل التبويبات.');
    }
}
