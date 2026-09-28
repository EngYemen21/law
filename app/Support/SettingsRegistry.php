<?php

namespace App\Support;

use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Models\Consult;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * **سجلّ متغيّرات النظام المُعلَن** — المصدر الواحد لاسم كلّ متغيّر ونوعه وافتراضه ومداه
 * وقواعد تحقّقه وأثر تغييره.
 *
 * كان النوع والافتراض مكرَّرين في كلّ موضع نداء (`(int) get('vat_rate', 15)` هنا،
 * و`trim((string) get('exec_working_days_from', '2026-10-28')) `هناك)، فإضافة متغيّرٍ أو
 * تعديل افتراضه تعني مطاردة نسخه في الشيفرة — ونسخةٌ تُنسى تعني افتراضين متباعدين.
 * الآن: سطرٌ واحد في `all()` يعرّف المتغيّر، والشاشةُ والتحقّقُ والقراءةُ تسأله كلُّها.
 *
 * **غلافٌ فوق `Setting::get/put` لا بديلٌ عنه**: `Setting::vatRate()` وأخواتها تبقى لشاشتي
 * الأسعار والذكاء، فلكلٍّ مالكُه ولا حقلان يكتبان مفتاحاً واحداً.
 *
 * **ولا مفاتيح أسرار هنا البتّة**: عمود `value` نصٌّ بلا تعمية، والأسرار في
 * `config/services.php` من البيئة — والسجلّ يرفضها بنيويّاً لأنّ الشاشة لا تعرض إلّا ما فيه.
 */
class SettingsRegistry
{
    /**
     * مفتاح الحاوية الذي يحمل قيم الطلب الواحد. **الحاوية لا خاصّيّةٌ ساكنة**: الخاصّيّة
     * الساكنة تعمّر بعمر العمليّة، فتحمل قيمة طلبٍ إلى طلبٍ في عامل الطابور، وقيمة اختبارٍ
     * إلى اختبارٍ في الحزمة. و`scoped` يُنسى بين وظيفتين وبين اختبارين — أي «مرّةً لكلّ طلب» فعلاً.
     */
    private const CACHE = 'support.settings-registry.values';

    /** مجموعات العرض — ترتيبها ترتيبُ البطاقات في الشاشة. */
    public static function groups(): array
    {
        return [
            'exec' => 'التنفيذ والأتعاب',
            'billing' => 'الفواتير والسداد',
            'consults' => 'الاستشارات والمواعيد',
            'alerts' => 'المهل والتنبيهات',
            'office' => 'بيانات المكتب في المستندات والبريد',
            'chat' => 'مسمّيات المتحدّثين في محادثات العميل',
        ];
    }

    /**
     * **الوصف الكامل لكلّ متغيّر.** والافتراض هنا هو الثابت المُعلَن في الشيفرة نفسه لا نسخةً
     * منه — فلا ينزلق أحدهما عن الآخر بصمت (ويحرسه `AdminSettingsTest`).
     *
     * `forwardOnly` يعني: التغيير يسري على ما يُنشأ بعده وحده، وما مضى محفوظٌ على صفّه.
     *
     * @return array<string, array{group:string,label:string,hint:string,type:'int'|'string'|'date',default:mixed,min?:int,max?:int,rules:array<int,string>,forwardOnly?:bool,gt?:string,defaultLabel?:string}>
     */
    public static function all(): array
    {
        return [
            // ── التنفيذ والأتعاب ──
            'exec_working_days_from' => [
                'group' => 'exec',
                'label' => 'بدء احتساب مهلة الوفاء بأيّام العمل',
                'hint' => 'قبل هذا التاريخ تُحسب المهلة أيّاماً تقويميّة، ومنه فصاعداً أيّامَ عملٍ (الجمعة والسبت عطلة) — تاريخ نفاذ نظام التنفيذ الجديد.',
                'type' => 'date',
                'default' => '2026-10-28',
                'rules' => ['required', 'date_format:Y-m-d'],
            ],
            'exec_pay_days' => [
                'group' => 'exec',
                'label' => 'مهلة الوفاء بأمر التنفيذ (أيّام)',
                'hint' => 'المهلة الممنوحة للمنفَّذ ضدّه بعد إبلاغه بأمر التنفيذ، وبعدها تُتَّخذ إجراءات عدم الوفاء.',
                'type' => 'int',
                'default' => ExecFlow::PAY_DAYS,
                'min' => 1,
                'max' => 30,
                'rules' => ['required', 'integer', 'min:1', 'max:30'],
                'forwardOnly' => true,
            ],
            'exec_max_collection_pct' => [
                'group' => 'exec',
                'label' => 'سقف نسبة الأتعاب من المحصّل (٪)',
                'hint' => 'أقصى نسبةٍ تُقبل في نموذج «نسبة من المحصّل» — ما فوقها يُردّ خطأَ إدخالٍ عند التسعير والاعتماد.',
                'type' => 'int',
                'default' => (int) ExecFee::MAX_PCT,
                'min' => 1,
                'max' => 100,
                'rules' => ['required', 'integer', 'min:1', 'max:100'],
            ],
            'installments_count' => [
                'group' => 'exec',
                'label' => 'عدد دفعات خطّة التقسيط',
                'hint' => 'عدد الفواتير التي تُقسَّم إليها الأتعاب في القضايا والتنفيذ. الخطط المفتوحة تبقى بعددها المحفوظ على ملفّها.',
                'type' => 'int',
                'default' => CaseFee::INSTALLMENTS,
                'min' => 2,
                'max' => 6,
                'rules' => ['required', 'integer', 'min:2', 'max:6'],
                'forwardOnly' => true,
            ],

            // ── الفواتير والسداد ──
            // **بداية سجلّ مستحقّات الموظّفين** (`Finance\StaffEarnings`): قبل هذا الشهر لم يكن المصروف
            // يُسجَّل، فحسابُ رواتب ونسبٍ سابقة كان سيُظهرها غيرَ مصروفةٍ وهي قد صُرفت خارج النظام.
            'payroll_start' => [
                'group' => 'billing',
                'label' => 'بداية سجلّ مستحقّات الموظّفين',
                'hint' => 'من هذا الشهر تُحسب الرواتب ونسب الأتعاب وأجور الجلسات ويُطرح منها المصروف المسجَّل؛ وما قبله يُعدّ مسوّىً خارج النظام.',
                'type' => 'date',
                'default' => '2026-09-01',
                'rules' => ['required', 'date_format:Y-m-d'],
            ],
            // مهلة كلّ فاتورةٍ كانت رقماً منقوشاً في موضع إصدارها ومعه نصٌّ عربيّ مكتوبٌ باليد
            // («خلال 3 أيام»). القارئ الوحيد الآن `Finance\InvoiceDue` — يبني التاريخ والنصّ معاً
            // من القيمة نفسها. وكلّها `forwardOnly`: الفاتورة تحمل `due_at` مجمَّداً لحظة إصدارها.
            'invoice_due_days_consult' => [
                'group' => 'billing',
                'label' => 'مهلة سداد فاتورة الاستشارة (أيّام)',
                'hint' => 'تُحسب من لحظة تسعير الاستشارة وإصدار فاتورتها.',
                'type' => 'int',
                'default' => 3,
                'min' => 1,
                'max' => 30,
                'rules' => ['required', 'integer', 'min:1', 'max:30'],
                'forwardOnly' => true,
            ],
            'invoice_due_days_case' => [
                'group' => 'billing',
                'label' => 'مهلة سداد فاتورة أتعاب القضيّة (أيّام)',
                'hint' => 'تُحسب من اعتماد الأتعاب حين يدفع العميل المبلغ كاملاً. مهلة الدفعة الأولى في التقسيط خانةٌ مستقلّة أدناه.',
                'type' => 'int',
                'default' => 14,
                'min' => 1,
                'max' => 60,
                'rules' => ['required', 'integer', 'min:1', 'max:60'],
                'forwardOnly' => true,
            ],
            'invoice_due_days_exec' => [
                'group' => 'billing',
                'label' => 'مهلة سداد فاتورة أتعاب التنفيذ (أيّام)',
                'hint' => 'تُحسب من قبول العميل عرض التنفيذ حين يدفع المبلغ كاملاً. مهلة الدفعة الأولى في التقسيط خانةٌ مستقلّة أدناه.',
                'type' => 'int',
                'default' => 3,
                'min' => 1,
                'max' => 30,
                'rules' => ['required', 'integer', 'min:1', 'max:30'],
                'forwardOnly' => true,
            ],
            'invoice_due_days_collection' => [
                'group' => 'billing',
                'label' => 'مهلة سداد فاتورة النسبة من المحصّل (أيّام)',
                'hint' => 'الفاتورة التي تصدر مع كلّ تحصيلٍ في ملفّ تنفيذٍ أتعابُه نسبةٌ من المحصّل.',
                'type' => 'int',
                'default' => 7,
                'min' => 1,
                'max' => 30,
                'rules' => ['required', 'integer', 'min:1', 'max:30'],
                'forwardOnly' => true,
            ],
            'installment_first_due_days' => [
                'group' => 'billing',
                'label' => 'مهلة سداد الدفعة الأولى في التقسيط (أيّام)',
                'hint' => 'حين يختار العميل التقسيط في قضيّةٍ أو تنفيذ: تُحسب من فتح خطّة التقسيط.',
                'type' => 'int',
                // ما كان منقوشاً قبل أن تصير المهل إعدادات — فلا يتغيّر شيءٌ حتى تغيّره الإدارة
                'default' => 3,
                'min' => 1,
                'max' => 60,
                'rules' => ['required', 'integer', 'min:1', 'max:60'],
                'forwardOnly' => true,
            ],
            'installment_interval_days' => [
                'group' => 'billing',
                'label' => 'الفاصل بين دفعات التقسيط (أيّام)',
                'hint' => 'الدفعة الثانية تستحقّ بعد هذا الفاصل من فتح الخطّة، والثالثة بعد ضعفه، وهكذا — في القضايا والتنفيذ.',
                'type' => 'int',
                'default' => 30,
                'min' => 7,
                'max' => 90,
                'rules' => ['required', 'integer', 'min:7', 'max:90'],
                'forwardOnly' => true,
            ],

            // ── الاستشارات والمواعيد ──
            // ساعات الحجز وطول الشريحة كانت ثوابتَ في `LawyerAvailability` ونُسخاً مختلفة في
            // الواجهة (09–22 في شبكة الموظّف و08–22 بنصف ساعة في المنتقي). المحرّك يقرأ هنا،
            // والشاشات تأخذ القيم من الخادم مشتركةً — فلا شبكة تعرض ساعةً لا يقبلها المحرّك.
            'consult_day_start' => [
                'group' => 'consults',
                'label' => 'بداية ساعات حجز الاستشارات',
                'hint' => 'الساعة (0–23) التي تبدأ منها أوّل شريحة حجز كلّ يوم. 0 = منتصف الليل.',
                'type' => 'int',
                'default' => LawyerAvailability::WORK_START,
                'min' => 0,
                'max' => 23,
                'rules' => ['required', 'integer', 'min:0', 'max:23'],
            ],
            'consult_day_end' => [
                'group' => 'consults',
                'label' => 'نهاية ساعات حجز الاستشارات',
                'hint' => 'الساعة (1–24) التي تنتهي عندها آخر شريحة — لا تبدأ شريحةٌ لا تنتهي قبلها. 24 = نهاية اليوم.',
                'type' => 'int',
                'default' => LawyerAvailability::WORK_END,
                'min' => 1,
                'max' => 24,
                'rules' => ['required', 'integer', 'min:1', 'max:24'],
                // علاقةٌ بين حقلين لا يعبّر عنها `gt:` وحده: البطاقة قد ترسل أحدهما، فيُقارن
                // بالمحفوظ للآخر — `relationErrors()` تتولّاه.
                'gt' => 'consult_day_start',
            ],
            // **مسافةُ حجزٍ لا عمرُ جلسة** (قرار المالك 2026-09-26): الجلسة تنتهي حين تُنهى، وهذا
            // الرقم يمنع حجز موكّلَين عند المحامي في الوقت نفسه، ويُمرَّر لـZoom والتقويم اسماً فقط.
            'consult_slot_minutes' => [
                'group' => 'consults',
                'label' => 'المسافة بين مواعيد الحجز (دقائق)',
                'hint' => 'طول شريحة الحجز: لا يُحجز للمحامي موعدان داخل هذه المسافة. لا يُنهي الجلسة — الاستشارة والاجتماع ينتهيان حين يُنهيهما الطاقم أو يُنهى اجتماع Zoom.',
                'type' => 'int',
                'default' => LawyerAvailability::SLOT_MIN,
                'min' => 15,
                'max' => 120,
                'rules' => ['required', 'integer', 'min:15', 'max:120'],
                'forwardOnly' => true,
            ],
            'consult_reschedule_limit' => [
                'group' => 'consults',
                'label' => 'سقف إعادة جدولة الاستشارة',
                'hint' => 'عدد المرّات التي يعيد فيها الطاقم جدولة استشارةٍ واحدة — وما بعدها للإدارة العليا وحدها. 0 = للإدارة العليا دائماً.',
                'type' => 'int',
                'default' => RescheduleConsult::LIMIT,
                'min' => 0,
                'max' => 10,
                'rules' => ['required', 'integer', 'min:0', 'max:10'],
            ],
            // **المهل بالدقائق** (قرار المالك 2026-09-26): كانت أربعٌ منها بالساعات، فلا تُضبط مهلةٌ
            // من ٥ أو ١٠ دقائق. والشاشة تأخذ دقائق، وكلُّ نصٍّ يقرؤه إنسانٌ يُصاغ منها بوحدتها
            // الطبيعيّة (`ArabicCount::duration`: ٩٠ ⇒ «ساعة ونصف») لا «٩٠ دقيقة». المحفوظ بالساعات
            // حُوِّل بمهاجرة `2026_09_26_130000_settings_hours_to_minutes`، ويحرس `HumanDurationTest`
            // ألّا يعود مفتاح مدّةٍ بالساعات.
            'consult_reschedule_notice_minutes' => [
                'group' => 'consults',
                'label' => 'أقلّ مهلة لطلب العميل تغيير موعده (دقائق)',
                'hint' => 'موعدٌ يبدأ خلال هذه الدقائق لا يطلب العميل تغييره من حسابه — يتّصل بالمكتب مباشرةً. 1440 = يوم واحد. 0 = يطلب في أيّ وقت.',
                'type' => 'int',
                'default' => Consult::RESCHEDULE_REQUEST_NOTICE_MINUTES,
                'min' => 0,
                'max' => 10080,
                'rules' => ['required', 'integer', 'min:0', 'max:10080'],
            ],

            // ── المهل والتنبيهات ──
            'ticket_escalate_minutes' => [
                'group' => 'alerts',
                'label' => 'تصعيد التذكرة غير المسندة (دقائق)',
                // الإسناد بشريّ (قرار المالك 2026-09-20)، فالمهلة نافذةُ عملٍ للطاقم لا سباقٌ مع
                // إسنادٍ آليّ: ساعتان بدل ١٥ دقيقة كانت تكفي فرق الطابور وحده.
                'hint' => 'عمر التذكرة المفتوحة بلا محامٍ مسنَد قبل تصعيدها إلى الإدارة العليا وإسنادها لها.',
                'type' => 'int',
                'default' => 120,
                'min' => 5,
                'max' => 240,
                'rules' => ['required', 'integer', 'min:5', 'max:240'],
            ],
            'consult_autoclose_minutes' => [
                'group' => 'alerts',
                'label' => 'إغلاق الاستشارة الفائتة بعد (دقائق)',
                'hint' => 'الدقائق بعد موعد الجلسة التي تُوسَم بعدها الاستشارة التي لم تُعقد «لم يحضر» آلياً. 720 = 12 ساعة.',
                'type' => 'int',
                'default' => 720,
                'min' => 5,
                'max' => 4320,
                'rules' => ['required', 'integer', 'min:5', 'max:4320'],
            ],
            // ── نهاية الجلسة حدثٌ لا حساب (قرار المالك 2026-09-26) — `SessionWindow` ──
            // الثلاثة تُقاس من **البداية**، ولا يُنهي أيٌّ منها جلسةً بدأت — وشبكةُ النسيان تنبّه
            // الطاقم ولا تُنهي (قرار المالك 2026-09-26).
            'session_missed_after_minutes' => [
                'group' => 'alerts',
                'label' => 'عدّ الجلسة التي لم تبدأ فائتةً بعد (دقائق)',
                'hint' => 'استشارةٌ أو اجتماعٌ لم يبدأ بعد هذه الدقائق من موعده يُعرض «فائتاً» ويُغلق باب دخوله، ويجوز تسجيل «لم يحضر». لا يمسّ جلسةً بدأت — تلك لا تنتهي إلّا بإنهائها.',
                'type' => 'int',
                'default' => SessionWindow::MISSED_AFTER_MINUTES,
                'min' => 10,
                'max' => 240,
                'rules' => ['required', 'integer', 'min:10', 'max:240'],
            ],
            'meeting_autoclose_minutes' => [
                'group' => 'alerts',
                'label' => 'إغلاق الاجتماع الذي لم ينعقد بعد (دقائق)',
                'hint' => 'الدقائق بعد موعد الاجتماع التي يُوسَم بعدها «لم ينعقد» آلياً إن لم يدخله أحد، وتنتهي صلاحية دعوته. 720 = 12 ساعة.',
                'type' => 'int',
                'default' => SessionWindow::MEETING_AUTOCLOSE_MINUTES,
                'min' => 5,
                'max' => 4320,
                'rules' => ['required', 'integer', 'min:5', 'max:4320'],
            ],
            'session_stale_minutes' => [
                'group' => 'alerts',
                'label' => 'إنهاء الجلسة المنسيّة بعد (دقائق)',
                'hint' => 'جلسةٌ بدأت ولم يُنهها أحد: بعد هذه الدقائق من بدئها يُنبَّه الطاقم وتُنهى الجلسة في النظام وفي Zoom. شبكة أمانٍ للنسيان، لا مدّةٌ للجلسة. 360 = 6 ساعات.',
                'type' => 'int',
                'default' => SessionWindow::STALE_AFTER_MINUTES,
                'min' => 5,
                'max' => 2880,
                'rules' => ['required', 'integer', 'min:5', 'max:2880'],
            ],
            'meeting_reminder_lead' => [
                'group' => 'alerts',
                'label' => 'تنبيه الاجتماع قبل (دقائق)',
                'hint' => 'كم دقيقة قبل موعد الاجتماع يصل تذكير البريد للعميل والمحامي المسنَد.',
                'type' => 'int',
                'default' => 60,
                'min' => 5,
                'max' => 1440,
                'rules' => ['required', 'integer', 'min:5', 'max:1440'],
            ],
            'consult_request_late_minutes' => [
                'group' => 'alerts',
                'label' => 'تأخّر طلب الاستشارة المفتوح بعد (دقائق)',
                'hint' => 'عمر الطلب المفتوح منذ استقباله الذي يُعدّ بعده «متأخّراً» في شاشتي الاستشارات وطلباتها.',
                'type' => 'int',
                // كانت الشاشتان تحملان حدّين مختلفين للطلبات نفسها (100 و120): فالطلب الواحد
                // «متأخّر» في شاشةٍ و«في الوقت» في جارتها. اختير 120 — ساعتان، ما تعلنه
                // تسمية فلتر «متأخرة (> ساعتين)» وما يطابق مهلة تصعيد التذكرة.
                'default' => 120,
                'min' => 15,
                'max' => 1440,
                'rules' => ['required', 'integer', 'min:15', 'max:1440'],
            ],

            // ── بيانات المكتب ──
            'office_name' => [
                'group' => 'office',
                // **اسمٌ واحد في كلّ مكان** (قرار المالك 2026-09-26): كان اسم المنصّة منقوشاً في
                // الصفحة الترويجيّة وصفحة الدخول وعنوان التبويب وشعار القائمة وتعليمات النموذج،
                // فلا تغيّره الإدارة إلّا بنشر كود. صار هذا الحقل مصدره الوحيد — لا حقل «اسم نظام» ثانٍ.
                'label' => 'اسم المكتب',
                'hint' => 'الاسم الواحد للمكتب في كلّ مكان: رأس مستندات PDF، ورسائل البريد، والصفحة الترويجيّة وصفحة الدخول، وعنوان تبويب المتصفّح، والقائمة الجانبيّة، والاسم الذي يُعرّف به المساعدُ الذكيّ المكتب.',
                'type' => 'string',
                // **النصّ المنقوش سابقاً لا `config('app.name')`.** الاثنان يختلفان بحرف:
                // الكود يكتبها «المحاماة» في خمسةٍ وأربعين موضعاً (ومنها رأس PDF قبل هذا
                // التغيير)، و`APP_NAME` في البيئة «المحاماه» — فالافتراض من الإعداد كان
                // يُحدث انحداراً في نصٍّ يقرؤه العميل على كلّ مستند حتى تُضبط القيمة يدوياً.
                'default' => 'النظام الإداري لمكاتب المحاماة',
                'rules' => ['required', 'string', 'max:120'],
            ],
            'office_phone' => [
                'group' => 'office',
                'label' => 'هاتف المكتب',
                'hint' => 'يظهر في رأس كلّ مستند PDF — كان منقوشاً في الشيفرة فتغييرُه يحتاج نشرَ كود.',
                'type' => 'string',
                'default' => '011 462 2277',
                'rules' => ['required', 'string', 'max:40'],
            ],
            'office_url' => [
                'group' => 'office',
                'label' => 'موقع المكتب',
                'hint' => 'يظهر في رأس مستندات PDF، وهو أصل روابط الصور في قوالب البريد حين يكون أصل التطبيق محلّياً.',
                'type' => 'string',
                'default' => 'https://salaselbabel.net/',
                'rules' => ['required', 'string', 'max:200', 'url'],
            ],
            'office_vat_number' => [
                'group' => 'office',
                'label' => 'الرقم الضريبيّ للمكتب',
                'hint' => 'يُطبع على الفاتورة الضريبيّة. اتركه فارغاً إن لم يكن المكتب مسجَّلاً في ضريبة القيمة المضافة — فلا يُطبع سطرُه أصلاً.',
                'type' => 'string',
                // **الافتراض فارغ، والحقل اختياريّ** — خلافاً لبقيّة بيانات المكتب. رقمٌ ضريبيٌّ
                // منقوشٌ افتراضاً يُطبع على مستندٍ رسميّ فيصير رقماً **كاذباً** باسم المكتب،
                // وهو أسوأ من غيابه. فإمّا الرقم الصحيح الذي تُدخله الإدارة، وإمّا لا سطر.
                'default' => '',
                // ما يقرؤه المدير على زرّ «إعادة إلى الافتراض» — الفراغ وحده لا يقول شيئاً
                'defaultLabel' => 'فارغ — لا يُطبع سطر الرقم الضريبيّ',
                'rules' => ['nullable', 'string', 'max:30'],
            ],
            // **العنوان والمدينة افتراضُهما من `config/office.php` لا نصٌّ هنا.** كانا يُقرآن من
            // البيئة (`OFFICE_ADDRESS`/`OFFICE_CITY`)، ونشرٌ ضبطهما هناك لا يصحّ أن يرتدّ إلى نصٍّ
            // منقوش حين يصير الإعداد في الجدول. فالترتيب: ما حفظته الإدارة ← البيئة ← افتراض
            // ملفّ الإعداد. والقارئ واحد (`SettingsRegistry::str`) — لا أحد يقرأ `config('office.*')` بعد اليوم.
            'office_address' => [
                'group' => 'office',
                'label' => 'عنوان المكتب',
                'hint' => 'مكان الاستشارة الحضوريّة حين لا يحمل موعدها مكاناً، ويظهر في التقويم وبطاقات المواعيد.',
                'type' => 'string',
                'default' => (string) config('office.address'),
                'rules' => ['required', 'string', 'max:200'],
            ],
            'office_city' => [
                'group' => 'office',
                'label' => 'مدينة المكتب',
                'hint' => 'تُكتب في صحيفة الدعوى المولّدة: «لدى المحكمة المختصّة بمدينة …».',
                'type' => 'string',
                'default' => (string) config('office.city'),
                'rules' => ['required', 'string', 'max:60'],
            ],
            'office_email' => [
                'group' => 'office',
                'label' => 'بريد المكتب في دعوات التقويم',
                'hint' => 'البريد الذي يظهر «منظِّماً» في ملفّ دعوة التقويم المرفق بالمواعيد.',
                'type' => 'string',
                'default' => 'no-reply@salasel.sa',
                'rules' => ['required', 'string', 'max:120', 'email'],
            ],

            // ── مسمّيات المتحدّثين (طلب المالك 2026-09-25) ──
            // ما يقرؤه **العميل** فوق كلّ رسالة في التذكرة والقضيّة والتنفيذ؛ الطاقم يرى الأسماء
            // الحقيقيّة. القارئ الوحيد `ChatSenderLabel`. والحقول اختياريّة: تفريغُ حقلٍ يعيد افتراضه.
            ChatSenderLabel::EMPLOYEE => [
                'group' => 'chat',
                'label' => 'الموظّف',
                'hint' => 'الاسم الذي يظهر للعميل فوق ردود موظّفي المكتب. اتركه فارغاً ليظهر «الفريق القانوني».',
                'type' => 'string',
                'default' => ChatSenderLabel::OFFICE,
                'rules' => ['nullable', 'string', 'max:40'],
            ],
            ChatSenderLabel::LAWYER => [
                'group' => 'chat',
                'label' => 'المحامي',
                'hint' => 'اسمٌ بديل للمحامي يراه العميل في كلّ مكان: فوق الردود، وفي لوحته، وتفاصيل الجلسة، والمحضر، وتقارير PDF، والبريد. اتركه فارغاً ليظهر اسم المحامي مختصراً «محمد. ب».',
                'type' => 'string',
                // **الفراغ هنا معنىً لا غياب**: يعني «الاسم المختصر لكلّ محامٍ»، فلا افتراضَ نصّيّاً يحلّ محلّه
                'default' => '',
                'defaultLabel' => 'فارغ — يظهر اسم كلّ محامٍ مختصراً',
                'rules' => ['nullable', 'string', 'max:40'],
            ],
            ChatSenderLabel::ADMIN => [
                'group' => 'chat',
                'label' => 'الإدارة العليا',
                'hint' => 'الاسم الذي يظهر للعميل فوق ردود الإدارة العليا. اتركه فارغاً ليظهر «الفريق القانوني».',
                'type' => 'string',
                'default' => ChatSenderLabel::OFFICE,
                'rules' => ['nullable', 'string', 'max:40'],
            ],
            ChatSenderLabel::AI => [
                'group' => 'chat',
                'label' => 'الذكاء الاصطناعي',
                'hint' => 'الاسم الذي يظهر للعميل فوق الردود الآليّة. اتركه فارغاً ليظهر «خدمة العملاء».',
                'type' => 'string',
                'default' => 'خدمة العملاء',
                'rules' => ['nullable', 'string', 'max:40'],
            ],
        ];
    }

    /** وصف متغيّرٍ بعينه — الاستدعاء بمفتاحٍ خارج السجلّ خطأُ برمجةٍ لا إدخالُ مستخدم. */
    public static function field(string $key): array
    {
        $field = self::all()[$key] ?? null;

        if ($field === null) {
            throw new \InvalidArgumentException("مفتاح إعدادٍ خارج السجلّ: {$key}");
        }

        return $field;
    }

    /**
     * **عددٌ مقيَّدٌ بمداه.** القيد عند القراءة لا عند الكتابة وحدها: قيمةٌ فاسدة في القاعدة
     * (تعديلٌ مباشر، أو مدىً ضُيّق بعد كتابتها) لا يصحّ أن توقف ميزةً — نمط
     * `Setting::aiAutoAcceptThreshold()` نفسه.
     */
    public static function int(string $key): int
    {
        $field = self::field($key);
        $value = (int) (self::stored()[$key] ?? $field['default']);

        return max((int) ($field['min'] ?? PHP_INT_MIN), min((int) ($field['max'] ?? PHP_INT_MAX), $value));
    }

    /** نصٌّ غير فارغ — والفراغ في القاعدة يعود إلى الافتراض لا إلى سطرٍ خالٍ في المستند. */
    public static function str(string $key): string
    {
        $field = self::field($key);
        $value = trim((string) (self::stored()[$key] ?? ''));

        return $value !== '' ? $value : (string) $field['default'];
    }

    /** تاريخ `Y-m-d` — وما لا يُقرأ تاريخاً يعود إلى الافتراض بدل أن يرمي عند التحليل. */
    public static function date(string $key): string
    {
        $field = self::field($key);
        $value = trim((string) (self::stored()[$key] ?? ''));

        try {
            return $value !== '' ? Carbon::parse($value)->toDateString() : (string) $field['default'];
        } catch (\Throwable) {
            return (string) $field['default'];
        }
    }

    /**
     * قواعد `validate` مشتقّةً من السجلّ. كلّها `sometimes` لأنّ لكلّ بطاقةٍ زرَّ حفظٍ
     * مستقلّاً فترسل حقولها وحدها — وإلزامُ الكلّ يجعل حفظ بطاقةٍ يشكو من حقول جارتها.
     *
     * @return array<string, array<int, string>>
     */
    public static function rulesFor(): array
    {
        $rules = [];

        foreach (self::all() as $key => $field) {
            $rules[$key] = array_merge(['sometimes'], $field['rules']);
        }

        return $rules;
    }

    /**
     * **أخطاء العلاقة بين حقلين** (`gt`: هذا أكبر من ذاك) — بعد تحقّق كلّ حقلٍ بمفرده.
     *
     * `gt:` في قواعد لارافيل يفشل إن غاب الحقل الآخر عن الطلب، والبطاقة قد ترسل أحدهما
     * وحده؛ فالمقارنة هنا بالقيمة **النافذة** للآخر: المرسَلة إن أُرسلت، وإلّا المحفوظة.
     * وإلّا حُفظت نهايةٌ قبل البداية فيولّد المحرّك يوماً بلا شريحة واحدة بصمت.
     *
     * @param  array<string, mixed>  $data  المُتحقَّق منه
     * @return array<string, string> مفتاح ← رسالة
     */
    public static function relationErrors(array $data): array
    {
        $current = self::values();
        $errors = [];

        foreach (self::all() as $key => $field) {
            $other = $field['gt'] ?? null;

            if ($other === null || (! array_key_exists($key, $data) && ! array_key_exists($other, $data))) {
                continue;
            }

            $mine = (int) ($data[$key] ?? $current[$key]);
            $theirs = (int) ($data[$other] ?? $current[$other]);

            if ($mine <= $theirs) {
                $errors[$key] = '«'.$field['label'].'» يجب أن تكون بعد «'.self::field($other)['label'].'» ('.$theirs.').';
            }
        }

        return $errors;
    }

    /**
     * القيم الحاليّة كلّها مقروءةً بأنواعها — للشاشة وللفرق في سجلّ التدقيق.
     *
     * @return array<string, int|string>
     */
    public static function values(): array
    {
        $out = [];

        foreach (self::all() as $key => $field) {
            $out[$key] = match ($field['type']) {
                'int' => self::int($key),
                'date' => self::date($key),
                default => self::str($key),
            };
        }

        return $out;
    }

    /**
     * **استعلامٌ واحد لكلّ طلب.** كان كلّ `Setting::get` استعلاماً مستقلّاً، و`ExecFlow`
     * يُقرأ في حلقة التنبيهات الساعيّة — فعشرة مفاتيح تعني عشرة استعلامات في كلّ مرور.
     *
     * @return array<string, string>
     */
    private static function stored(): array
    {
        $app = app();

        if (! $app->bound(self::CACHE)) {
            $app->scoped(self::CACHE, fn () => Setting::whereIn('key', array_keys(self::all()))
                ->pluck('value', 'key')
                ->all());
        }

        return $app->make(self::CACHE);
    }

    /** يُنسي القيم المحفوظة — يناديه الحافظ بعد الكتابة كي يقرأ الطلبُ نفسُه ما كتب. */
    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE);
    }
}
