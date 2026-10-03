<?php

namespace App\Support;

use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Domain\Journey\Transitions\LegalCase\RecordRuling;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Setting;
use App\Support\Finance\LawyerShare;
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
 * **غلافٌ فوق `Setting::get/put` لا بديلٌ عنه**: قرّاء المفاتيح المسمّاة (`Setting::vatRate()`) يسألون
 * السجلّ هنا؛ ومعايرة الذكاء تبقى لشاشتها — فلكلٍّ مالكُه ولا حقلان يكتبان مفتاحاً واحداً.
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

    /**
     * **سقوفٌ واسعة لا حدودٌ تشغيليّة** (قرار المالك 2026-10-02): المدد والأعداد تقبل من 1 فما فوق،
     * والسقف يصدّ خطأ الكتابة وحده (999999 بدل 99). والصفر مرفوض لأنّه يعطّل المنطق لا لأنّه اختيار:
     * شريحةٌ صفريّة لا تولّد موعداً، ومهلةٌ صفريّة تؤخّر الفاتورة لحظة صدورها.
     * أمّا ما للصفر فيه معنى (سقف إعادة الجدولة، مهلة الإشعار) والنسب والساعات فلها حدودها في حقلها.
     */
    private const MAX_DAYS = 365;

    private const MAX_MINUTES = 525600; // سنة

    private const MINUTES_PER_DAY = 1440;

    private const MAX_COUNT = 1000;

    /** مجموعات العرض — ترتيبها ترتيبُ البطاقات في الشاشة. */
    public static function groups(): array
    {
        return [
            'exec' => 'التنفيذ والأتعاب',
            // مهلة الاستئناف (قرار المالك 2026-10-01) — كانت ٣٠ يوماً منقوشةً في تسجيل الحكم
            'cases' => 'القضايا والأحكام',
            'billing' => 'الفواتير والسداد',
            'consults' => 'الاستشارات والمواعيد',
            // إعدادات الاجتماع مجتمعةً (قرار المالك 2026-10-01) — كانت موزّعةً بين «الاستشارات» و«المهل»
            'meetings' => 'الاجتماعات',
            'alerts' => 'المهل والتنبيهات',
            'lawyers' => 'عبء المحامين ونصيبهم',
            // صلاحيّة رمز التحقّق (قرار المالك 2026-10-01) — كانت ١٠ منقوشةً في ثلاثة مواضع
            'security' => 'الدخول والتحقّق',
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
     * العدد (`int`) يُعلن `min`/`max` ويُشتقّ تحقّقه منهما (`rulesFor`)، وغيره يُعلن `rules`.
     *
     * @return array<string, array{group:string,label:string,hint:string,type:'int',default:mixed,min:int,max:int,forwardOnly?:bool,gt?:string,gte?:string,defaultLabel?:string}|array{group:string,label:string,hint:string,type:'string'|'date'|'days'|'bool',default:mixed,rules:array<int,string>,forwardOnly?:bool,defaultLabel?:string}>
     */
    public static function all(): array
    {
        return [
            // ── الدخول والتحقّق ──
            'otp_ttl_minutes' => [
                'group' => 'security',
                'label' => 'صلاحيّة رمز التحقّق (دقائق)',
                'hint' => 'بعدها يُرفض الرمز المُرسَل ويُطلب رمزٌ جديد — لرمز الجوال في الدخول والتسجيل وتغيير الجوال، ولرمز البريد. من دقيقة إلى خمس دقائق.',
                'type' => 'int',
                'default' => OtpService::TTL_MINUTES,
                // سقفٌ أمنيّ لا سقفُ خطأ كتابة (قرار المالك 2026-10-02): رمزٌ يعيش أطول نافذةُ تخمينٍ أوسع
                'min' => 1,
                'max' => 5,
            ],

            // ── القضايا والأحكام ──
            'appeal_deadline_days' => [
                'group' => 'cases',
                'label' => 'مهلة الاستئناف بعد صدور الحكم (أيّام)',
                'hint' => 'تُحسب منها «تنتهي مهلة تقديم لائحة الاعتراض بتاريخ…» عند تسجيل الحكم، ويُنبَّه بالمتبقّي منها. يسري على الأحكام التي تُسجَّل بعد التغيير؛ والمسجَّلة قبله تحفظ تاريخها.',
                'type' => 'int',
                'default' => RecordRuling::APPEAL_DAYS,
                'min' => 1,
                'max' => self::MAX_DAYS,
                'forwardOnly' => true,
            ],

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
                'max' => self::MAX_DAYS,
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
            ],
            'installments_count' => [
                'group' => 'exec',
                'label' => 'عدد دفعات خطّة التقسيط',
                'hint' => 'عدد الفواتير التي تُقسَّم إليها الأتعاب في القضايا والتنفيذ. الخطط المفتوحة تبقى بعددها المحفوظ على ملفّها.',
                'type' => 'int',
                'default' => CaseFee::INSTALLMENTS,
                'min' => 2,
                'max' => self::MAX_COUNT,
                'forwardOnly' => true,
            ],

            // ── الفواتير والسداد ──
            // **نسبة الضريبة هنا لا في شاشةٍ خاصّة** (قرار المالك 2026-09-29): كانت تُدخل في تبويب «أسعار
            // الاستشارات» وحده، وحُذف التبويب — التسعير يتمّ لكلّ استشارةٍ على حدة. قارئها الواحد
            // `Setting::vatRate()`، والفاتورة تنسخها يوم إصدارها (`Finance\InvoiceFactory`) فلا يمسّها تغييرٌ لاحق.
            'vat_rate' => [
                'group' => 'billing',
                'label' => 'نسبة ضريبة القيمة المضافة (%)',
                'hint' => 'تُحسب بها الضريبة عند تسعير الاستشارات وإصدار الفواتير. الفواتير الصادرة تبقى على نسبتها يوم إصدارها.',
                'type' => 'int',
                'default' => 15,
                'min' => 0,
                'max' => 100,
                'forwardOnly' => true,
            ],
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
                'max' => self::MAX_DAYS,
                'forwardOnly' => true,
            ],
            'invoice_due_days_case' => [
                'group' => 'billing',
                'label' => 'مهلة سداد فاتورة أتعاب القضيّة (أيّام)',
                'hint' => 'تُحسب من اعتماد الأتعاب حين يدفع العميل المبلغ كاملاً. مهلة الدفعة الأولى في التقسيط خانةٌ مستقلّة أدناه.',
                'type' => 'int',
                'default' => 14,
                'min' => 1,
                'max' => self::MAX_DAYS,
                'forwardOnly' => true,
            ],
            'invoice_due_days_exec' => [
                'group' => 'billing',
                'label' => 'مهلة سداد فاتورة أتعاب التنفيذ (أيّام)',
                'hint' => 'تُحسب من قبول العميل عرض التنفيذ حين يدفع المبلغ كاملاً. مهلة الدفعة الأولى في التقسيط خانةٌ مستقلّة أدناه.',
                'type' => 'int',
                'default' => 3,
                'min' => 1,
                'max' => self::MAX_DAYS,
                'forwardOnly' => true,
            ],
            'invoice_due_days_collection' => [
                'group' => 'billing',
                'label' => 'مهلة سداد فاتورة النسبة من المحصّل (أيّام)',
                'hint' => 'الفاتورة التي تصدر مع كلّ تحصيلٍ في ملفّ تنفيذٍ أتعابُه نسبةٌ من المحصّل.',
                'type' => 'int',
                'default' => 7,
                'min' => 1,
                'max' => self::MAX_DAYS,
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
                'max' => self::MAX_DAYS,
                'forwardOnly' => true,
            ],
            'installment_interval_days' => [
                'group' => 'billing',
                'label' => 'الفاصل بين دفعات التقسيط (أيّام)',
                'hint' => 'الدفعة الثانية تستحقّ بعد هذا الفاصل من فتح الخطّة، والثالثة بعد ضعفه، وهكذا — في القضايا والتنفيذ.',
                'type' => 'int',
                'default' => 30,
                'min' => 1,
                'max' => self::MAX_DAYS,
                'forwardOnly' => true,
                'gt' => 'installment_first_due_days',
            ],

            // ── عبء المحامين ونصيبهم ──
            'lawyer_default_share_pct' => [
                'group' => 'lawyers',
                'label' => 'نصيب المحامي الافتراضيّ من الأتعاب (٪)',
                'hint' => 'يُقترح عند اعتماد أتعاب قضيّةٍ أو ملفّ تنفيذ لمحامٍ ليس في ملفّه نسبةٌ خاصّة (أو راتبه ثابت). النسبة تُحفظ على كلّ ملفٍّ عند اعتماده، فتغييرها لا يمسّ ما اعتُمد.',
                'type' => 'int',
                'default' => LawyerShare::DEFAULT_PCT,
                'min' => 1,
                'max' => 100,
                'forwardOnly' => true,
            ],
            // عتبتا الحِمل في صفحة المحامين وشاشة التوزيع ولوحة الإدارة — تعريفٌ واحد (`LawyerWorkload`)؛
            // كانت اللوحة تحسبه بأوزانٍ وعتباتٍ أخرى (قرار المالك 2026-09-30: يُعتمد `LawyerWorkload`).
            'workload_moderate_from' => [
                'group' => 'lawyers',
                'label' => 'حِمل «متوسّط» من (نقاط)',
                'hint' => 'نقاط الحِمل: تذكرة مفتوحة = 1، قضيّة نشطة = 2، ملفّ تنفيذ = 2، استشارة مفتوحة = 1. ما دون هذا الحدّ «متاح للتوزيع».',
                'type' => 'int',
                'default' => LawyerWorkload::MODERATE_FROM,
                'min' => 1,
                'max' => self::MAX_COUNT,
            ],
            'workload_busy_from' => [
                'group' => 'lawyers',
                'label' => 'حِمل «مشغول» من (نقاط)',
                'hint' => 'من هذا الحدّ فما فوقه يُعرض المحامي «مشغولاً» في صفحة المحامين وشاشة التوزيع ولوحة الإدارة.',
                'type' => 'int',
                'default' => LawyerWorkload::BUSY_FROM,
                'min' => 1,
                'max' => self::MAX_COUNT,
                'gt' => 'workload_moderate_from',
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
            ],
            'consult_day_end' => [
                'group' => 'consults',
                'label' => 'نهاية ساعات حجز الاستشارات',
                'hint' => 'الساعة (1–24) التي تنتهي عندها آخر شريحة — لا تبدأ شريحةٌ لا تنتهي قبلها. 24 = نهاية اليوم.',
                'type' => 'int',
                'default' => LawyerAvailability::WORK_END,
                'min' => 1,
                'max' => 24,
                // علاقةٌ بين حقلين لا يعبّر عنها `gt:` وحده: البطاقة قد ترسل أحدهما، فيُقارن
                // بالمحفوظ للآخر — `relationErrors()` تتولّاه.
                'gt' => 'consult_day_start',
            ],
            // أيّام الدوام كانت ثابتاً في `LawyerAvailability` (الأسبوع كلّه) — صارت إعداداً مع الساعات
            'consult_work_days' => [
                'group' => 'consults',
                'label' => 'أيّام دوام المكتب',
                'hint' => 'الأيّام التي يُحجز فيها موعد الاستشارة. لا شرائح حجز في غيرها.',
                'type' => 'days',
                'default' => implode(',', LawyerAvailability::WORK_DAYS),
                'rules' => ['required', 'string', 'regex:/^[0-6](,[0-6]){0,6}$/'],
            ],
            // قرار المالك 2026-09-28: خيار «نعم/لا» — الافتراض «لا» يُبقي الحجز المتداخل مرفوضاً كما كان
            'consult_allow_overlap' => [
                'group' => 'consults',
                'label' => 'السماح بحجز استشارةٍ لمحامٍ مشغول في الوقت نفسه',
                'hint' => '«نعم»: يُقبل الحجز فوق موعدٍ أو اجتماعٍ آخر للمحامي مع تنبيه للحاجز. جلسة المحكمة تمنع الحجز دائماً. لا يشمل دعوات الاجتماعات ولا الإسناد التلقائيّ (يختار متفرّغاً).',
                'type' => 'bool',
                'default' => 0,
                'rules' => ['required', 'boolean'],
            ],
            // قرار المالك 2026-09-28: خيار «نعم/لا» — الافتراض «لا» يُبقي الحجز خارج الدوام مرفوضاً كما كان
            'consult_allow_outside_office' => [
                'group' => 'consults',
                'label' => 'السماح بحجز استشارةٍ خارج أوقات الدوام',
                'hint' => '«نعم»: يُقبل موعدٌ في يوم عطلة أو خارج ساعات الحجز مع تنبيه للحاجز (يُختار الوقت من «تحديد دقيقة مخصّصة»). لا يشمل دعوات الاجتماعات.',
                'type' => 'bool',
                'default' => 0,
                'rules' => ['required', 'boolean'],
            ],
            // **مسافةُ حجزٍ لا عمرُ جلسة** (قرار المالك 2026-09-26): الجلسة تنتهي حين تُنهى، وهذا
            // الرقم يمنع حجز موكّلَين عند المحامي في الوقت نفسه، ويُمرَّر لـZoom والتقويم اسماً فقط.
            'consult_slot_minutes' => [
                'group' => 'consults',
                'label' => 'المسافة بين مواعيد الحجز (دقائق)',
                'hint' => 'طول شريحة الحجز: لا يُحجز للمحامي موعدان داخل هذه المسافة. لا يُنهي الجلسة — الاستشارة والاجتماع ينتهيان حين يُنهيهما الطاقم أو يُنهى اجتماع Zoom.',
                'type' => 'int',
                'default' => LawyerAvailability::SLOT_MIN,
                'min' => 1,
                'max' => self::MINUTES_PER_DAY,
                'forwardOnly' => true,
            ],
            'meeting_reschedule_limit' => [
                'group' => 'meetings',
                'label' => 'سقف إعادة جدولة الاجتماع',
                'hint' => 'عدد المرّات التي يُغيَّر فيها موعد اجتماعٍ واحد (بطلب العميل أو من الطاقم) — وما بعدها للإدارة العليا وحدها، ولا يطلب العميل تغييراً آخر. 0 = للإدارة العليا دائماً.',
                'type' => 'int',
                'default' => Meeting::RESCHEDULE_LIMIT,
                'min' => 0,
                'max' => 10,
            ],
            'consult_reschedule_limit' => [
                'group' => 'consults',
                'label' => 'سقف إعادة جدولة الاستشارة',
                'hint' => 'عدد المرّات التي يعيد فيها الطاقم جدولة استشارةٍ واحدة — وما بعدها للإدارة العليا وحدها. 0 = للإدارة العليا دائماً.',
                'type' => 'int',
                'default' => RescheduleConsult::LIMIT,
                'min' => 0,
                'max' => 10,
            ],
            // **المهل بالدقائق** (قرار المالك 2026-09-26): كانت أربعٌ منها بالساعات، فلا تُضبط مهلةٌ
            // من ٥ أو ١٠ دقائق. والشاشة تأخذ دقائق، وكلُّ نصٍّ يقرؤه إنسانٌ يُصاغ منها بوحدتها
            // الطبيعيّة (`ArabicCount::duration`: ٩٠ ⇒ «ساعة ونصف») لا «٩٠ دقيقة». المحفوظ بالساعات
            // حُوِّل بمهاجرة `2026_09_26_130000_settings_hours_to_minutes`، ويحرس `HumanDurationTest`
            // ألّا يعود مفتاح مدّةٍ بالساعات.
            'consult_reschedule_notice_minutes' => [
                'group' => 'consults',
                'label' => 'أقلّ مهلة لطلب العميل تغيير موعده (دقائق)',
                // للاستشارة والاجتماع معاً (قرار المالك 2026-10-01) — `Meeting::changeRequestBlocker`
                'hint' => 'استشارةٌ أو اجتماعٌ يبدأ خلال هذه الدقائق لا يطلب العميل تغيير موعده من حسابه — يتّصل بالمكتب مباشرةً. 1440 = يوم واحد. 0 = يطلب في أيّ وقت.',
                'type' => 'int',
                'default' => Consult::RESCHEDULE_REQUEST_NOTICE_MINUTES,
                'min' => 0,
                'max' => 10080,
            ],

            // ── المهل والتنبيهات ──
            'ticket_escalate_minutes' => [
                'group' => 'alerts',
                'label' => 'تصعيد التذكرة غير المسندة (دقائق)',
                // الإسناد بشريّ (قرار المالك 2026-09-20)، فالمهلة نافذةُ عملٍ للطاقم لا سباقٌ مع
                // إسنادٍ آليّ: ساعتان بدل ١٥ دقيقة كانت تكفي فرق الطابور وحده.
                'hint' => 'عمر التذكرة المفتوحة بلا محامٍ مسنَد قبل تصعيدها إلى الإدارة العليا وإسنادها لها.',
                'type' => 'int',
                'default' => 30,
                'min' => 1,
                'max' => self::MAX_MINUTES,
            ],
            'consult_autoclose_minutes' => [
                'group' => 'alerts',
                'label' => 'إغلاق الاستشارة الفائتة بعد (دقائق)',
                'hint' => 'الدقائق بعد موعد الجلسة التي تُوسَم بعدها الاستشارة التي لم تُعقد «لم يحضر» آلياً. لا تقلّ عن «عدّ الجلسة التي لم تبدأ فائتةً بعد» — فلا تُغلق قبل أن تُعدّ فائتة.',
                'type' => 'int',
                'default' => SessionWindow::MISSED_AFTER_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gte' => 'session_missed_after_minutes',
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
                'min' => 1,
                'max' => self::MAX_MINUTES,
            ],
            'meeting_autoclose_minutes' => [
                'group' => 'meetings',
                'label' => 'إغلاق الاجتماع الذي لم ينعقد بعد (دقائق)',
                'hint' => 'الدقائق بعد موعد الاجتماع التي يُوسَم بعدها «لم ينعقد» آلياً إن لم يدخله أحد. لا تقلّ عن «عدّ الجلسة التي لم تبدأ فائتةً بعد» — فلا تُغلق قبل أن تُعدّ فائتة.',
                'type' => 'int',
                'default' => SessionWindow::MEETING_AUTOCLOSE_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gte' => 'session_missed_after_minutes',
            ],
            'session_stale_minutes' => [
                'group' => 'alerts',
                'label' => 'إنهاء الجلسة المنسيّة بعد (دقائق)',
                'hint' => 'جلسةٌ بدأت ولم يُنهها أحد: بعد هذه الدقائق من بدئها يُنبَّه الطاقم وتُنهى الجلسة في النظام وفي Zoom. شبكة أمانٍ للنسيان، لا مدّةٌ للجلسة. 360 = 6 ساعات.',
                'type' => 'int',
                'default' => SessionWindow::STALE_AFTER_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
            ],
            // ── طبقتا تذكير الاجتماع (قرار المالك 2026-10-01) — نظيرُ طبقتي الاستشارة ──
            'meeting_reminder_lead' => [
                'group' => 'meetings',
                'label' => 'تذكير الاجتماع الأوّل بالبريد قبل (دقائق)',
                'hint' => 'بريدٌ للعميل والمحامي المسنَد والمشاركين من الكادر. يجب أن يسبق التذكير الثاني.',
                'type' => 'int',
                'default' => Meeting::REMINDER_FAR_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gt' => 'meeting_reminder_near_minutes',
            ],
            'meeting_reminder_near_minutes' => [
                'group' => 'meetings',
                'label' => 'تذكير الاجتماع الثاني للعميل قبل (دقائق)',
                'hint' => 'إشعارٌ في حساب العميل. والرسالة النصّيّة واحدةٌ عند «فتح الدخول» فيها تاريخ الاجتماع ووقته ورابطه. يجب أن يكون قبل «فتح الدخول».',
                'type' => 'int',
                'default' => Meeting::REMINDER_NEAR_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gt' => 'session_join_opens_minutes',
            ],
            // ── نوافذ الدخول وطبقات التذكير — افتراضاتها ما كان منقوشاً (`SessionWindow` وأوامر التذكير) ──
            'session_join_opens_minutes' => [
                'group' => 'alerts',
                'label' => 'فتح الدخول للجلسة المرئيّة قبل الموعد (دقائق)',
                'hint' => 'متى يُفعَّل زرّ الدخول ويُرسل رابط الجلسة للعميل — بالبريد وبرسالةٍ نصّيّة فيها تاريخ الجلسة ووقتها ورابطها، للاستشارة المرئيّة والاجتماع. والنصوص التي تعلنها للعميل تُبنى من هذه القيمة.',
                'type' => 'int',
                'default' => SessionWindow::JOIN_OPENS_BEFORE_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
            ],
            'consult_staff_start_minutes' => [
                'group' => 'alerts',
                // للاستشارة والاجتماع معاً (قرار المالك 2026-10-01) — `StartMeeting::guard`. المفتاح باقٍ كما هو
                // (قيمه المحفوظة تُقرأ به)، والاسم المعروض هو ما تغيّر.
                'label' => 'بدء الجلسة للطاقم قبل الموعد (دقائق)',
                'hint' => 'متى يستطيع المحامي أو الموظّف بدء الاستشارة أو الاجتماع — لا قبل ذلك. لا تقلّ عن «فتح الدخول» — فلا يصل العميلُ غرفةً لا يستطيع الطاقم بدأها.',
                'type' => 'int',
                'default' => SessionWindow::STAFF_START_BEFORE_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gte' => 'session_join_opens_minutes',
            ],
            'consult_reminder_far_minutes' => [
                'group' => 'alerts',
                'label' => 'تذكير الاستشارة الأوّل بالبريد قبل (دقائق)',
                'hint' => 'للاستشارات المسدَّدة ذات الموعد. 1440 = 24 ساعة. يجب أن يسبق التذكير النصّيّ.',
                'type' => 'int',
                'default' => 720,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gt' => 'consult_reminder_near_minutes',
            ],
            'consult_reminder_near_minutes' => [
                'group' => 'alerts',
                'label' => 'تذكير الاستشارة الثاني قبل (دقائق)',
                'hint' => 'للاستشارة المسدَّدة: المرئيّة يصلها إشعارٌ في الحساب (ورسالتها النصّيّة رسالةُ الرابط عند «فتح الدخول»)، والحضوريّة والهاتفيّة تصلها رسالةٌ نصّيّة بالموعد والمكان. يجب أن يكون قبل «فتح الدخول».',
                'type' => 'int',
                'default' => Consult::REMINDER_NEAR_MINUTES,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gt' => 'session_join_opens_minutes',
            ],
            'hearing_reminder_far_minutes' => [
                'group' => 'alerts',
                'label' => 'تذكير جلسة المحكمة الأوّل قبل (دقائق)',
                'hint' => 'إشعار داخليّ وبريد للعميل والمحامي المسنَد. 1440 = 24 ساعة. يجب أن يسبق التذكير الثاني.',
                'type' => 'int',
                'default' => 1440,
                'min' => 1,
                'max' => self::MAX_MINUTES,
                'gt' => 'hearing_reminder_near_minutes',
            ],
            'hearing_reminder_near_minutes' => [
                'group' => 'alerts',
                'label' => 'تذكير جلسة المحكمة الثاني قبل (دقائق)',
                'hint' => 'إشعار داخليّ وبريد ثانٍ قريبٌ من الموعد.',
                'type' => 'int',
                'default' => 60,
                'min' => 1,
                'max' => self::MAX_MINUTES,
            ],
            // ── ما كان منقوشاً في أوامر المجدول وتوليد المهامّ (تدقيق الإعدادات — المرحلة ٣) ──
            'hearing_lapse_after_minutes' => [
                'group' => 'alerts',
                'label' => 'عدّ جلسة المحكمة فائتةً بلا نتيجة بعد (دقائق)',
                'hint' => 'بعد هذه الدقائق من موعد الجلسة المجدولة التي لم تُسجَّل نتيجتها تصير «بانتظار تسجيل النتيجة» ويُنبَّه المحامي المسنَد. 1440 = 24 ساعة.',
                'type' => 'int',
                'default' => 1440,
                'min' => 1,
                'max' => self::MAX_MINUTES,
            ],
            'meet_invite_expire_minutes' => [
                'group' => 'meetings',
                // تأكيد العميل أُلغي — الدعوة المعلّقة تنتظر **موافقة الإدارة** (قرار المالك 2026-10-01)
                'label' => 'انتهاء الدعوة التي لم توافق عليها الإدارة بعد (دقائق)',
                'hint' => 'دعوةٌ أرسلها الموظّف أو المحامي ولم توافق عليها الإدارة تصير «منتهية الصلاحيّة» بعد هذه الدقائق من موعدها، ويُعاد إرسالها بموعدٍ جديد. ولا تُعتمد دعوةٌ فات موعدها في أيّ حال. 0 = عند موعده.',
                'type' => 'int',
                'default' => 0,
                'min' => 0,
                'max' => self::MAX_MINUTES,
            ],
            'decision_task_due_days' => [
                'group' => 'meetings',
                'label' => 'استحقاق مهامّ قرارات الاجتماع (أيّام)',
                'hint' => 'المهامّ التي تُولَّد للمحامي من قرارات محضر الاجتماع تستحقّ بعد هذه الأيّام، ويُكتب نصّ استحقاقها منها. يسري على المهامّ الجديدة.',
                'type' => 'int',
                'default' => 7,
                'min' => 1,
                'max' => self::MAX_DAYS,
                'forwardOnly' => true,
            ],
            'consult_request_late_minutes' => [
                'group' => 'alerts',
                'label' => 'تأخّر طلب الاستشارة المفتوح بعد (دقائق)',
                'hint' => 'عمر الطلب المفتوح منذ استقباله الذي يُعدّ بعده «متأخّراً» في شاشتي الاستشارات وطلباتها.',
                'type' => 'int',
                // كانت الشاشتان تحملان حدّين مختلفين للطلبات نفسها (100 و120): فالطلب الواحد
                // «متأخّر» في شاشةٍ و«في الوقت» في جارتها. اختير 120 — ساعتان، ما تعلنه
                // تسمية فلتر «متأخرة (> ساعتين)» وما يطابق مهلة تصعيد التذكرة.
                'default' => 5,
                'min' => 1,
                'max' => self::MAX_MINUTES,
            ],

            // ── بيانات المكتب ──
            'office_name' => [
                'group' => 'office',
                // **اسمٌ واحد في كلّ مكان** (قرار المالك 2026-09-26): كان اسم المنصّة منقوشاً في
                // الصفحة الترويجيّة وصفحة الدخول وعنوان التبويب وشعار القائمة وتعليمات النموذج،
                // فلا تغيّره الإدارة إلّا بنشر كود. صار هذا الحقل مصدره الوحيد — لا حقل «اسم نظام» ثانٍ.
                'label' => 'اسم المكتب',
                'hint' => 'الاسم الواحد للمكتب في كلّ مكان: رأس مستندات PDF وترويسة المستندات القانونيّة، ونصّ رسائل البريد وعناوينها (أمّا «اسم المرسل» الذي يظهر في صندوق البريد فمن شاشة مفاتيح الخدمات الخارجيّة)، والصفحة الترويجيّة وصفحة الدخول، وعنوان تبويب المتصفّح، والقائمة الجانبيّة، والاسم الذي يُعرّف به المساعدُ الذكيّ المكتب.',
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
            'office_name_en' => [
                'group' => 'office',
                'label' => 'اسم المكتب بالإنجليزيّة',
                'hint' => 'يظهر تحت الاسم العربيّ في ترويسة المستندات القانونيّة الجديدة (ويعدّله المحرّر لكلّ مستند).',
                'type' => 'string',
                // ما كان منقوشاً في `LegalDocument::defaultHeader`
                'default' => 'Law Office',
                'rules' => ['required', 'string', 'max:120'],
            ],
            'office_license_no' => [
                'group' => 'office',
                'label' => 'رقم ترخيص المكتب',
                'hint' => 'يُطبع «ترخيص رقم: …» في ترويسة المستندات القانونيّة الجديدة. اتركه فارغاً فلا يُطبع سطره.',
                'type' => 'string',
                // فارغٌ كالرقم الضريبيّ: رقمٌ رسميّ منقوشٌ افتراضاً يصير رقماً كاذباً باسم المكتب
                'default' => '',
                'defaultLabel' => 'فارغ — لا يُطبع سطر الترخيص',
                'rules' => ['nullable', 'string', 'max:40'],
            ],
            'office_arrival_minutes' => [
                'group' => 'office',
                'label' => 'الحضور قبل الموعد الحضوريّ (دقائق)',
                'hint' => 'يُكتب في بطاقة الموعد (الشاشة وملفّ PDF): «يُرجى الحضور قبل الموعد بـ…».',
                'type' => 'int',
                'default' => 15,
                'min' => 1,
                'max' => self::MAX_MINUTES,
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

    /** خيار «نعم/لا» — المخزَّن «1» أو «0»، والغائب يعود إلى الافتراض. */
    public static function bool(string $key): bool
    {
        return (bool) (int) (self::stored()[$key] ?? self::field($key)['default']);
    }

    /** نصٌّ غير فارغ — والفراغ في القاعدة يعود إلى الافتراض لا إلى سطرٍ خالٍ في المستند. */
    public static function str(string $key): string
    {
        $field = self::field($key);
        $value = trim((string) (self::stored()[$key] ?? ''));

        return $value !== '' ? $value : (string) $field['default'];
    }

    /**
     * **أيّام الأسبوع** (الأحد=0 … السبت=6) من نصٍّ «0,1,2» — مرتّبةً بلا تكرار. والفارغ أو الفاسد
     * يعود إلى الافتراض: أسبوعٌ بلا يوم دوامٍ واحد يُغلق الحجز بصمت.
     *
     * @return list<int>
     */
    public static function days(string $key): array
    {
        $days = self::parseDays((string) (self::stored()[$key] ?? ''));

        return $days !== [] ? $days : self::parseDays((string) self::field($key)['default']);
    }

    /**
     * «4,0,1,1» ← [0,1,4]: أرقام الأيّام الصحيحة وحدها، مرتّبةً بلا تكرار — للقراءة وللحفظ معاً.
     *
     * @return list<int>
     */
    public static function parseDays(string $csv): array
    {
        $days = array_map('intval', array_filter(array_map('trim', explode(',', $csv)), fn (string $d) => preg_match('/^[0-6]$/', $d) === 1));
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
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
            // العدد يُشتقّ تحقّقه من `min`/`max` نفسيهما — مصدرٌ واحد للشاشة والتحقّق والقيد عند القراءة
            $rules[$key] = array_merge(['sometimes'], $field['type'] === 'int'
                ? ['required', 'integer', 'min:'.$field['min'], 'max:'.$field['max']]
                : $field['rules']);
        }

        return $rules;
    }

    /**
     * أسماء الحقول في رسائل `validate` — تسميةُ كلّ متغيّرٍ من السجلّ نفسه. كانت الرسالة تطبع
     * المفتاح («يجب أن تكون قيمة exec pay days 1 على الأقلّ.»).
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return array_map(fn (array $field) => $field['label'], self::all());
    }

    /**
     * **أخطاء العلاقة بين حقلين** (`gt`: هذا أكبر من ذاك · `gte`: لا يقلّ عنه) — بعد تحقّق كلّ حقلٍ بمفرده،
     * ثمّ اتّساع طول الشريحة في ساعات الحجز.
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

        $value = fn (string $key): int => (int) ($data[$key] ?? $current[$key]);
        $touched = fn (string ...$keys): bool => array_intersect($keys, array_keys($data)) !== [];

        foreach (self::all() as $key => $field) {
            foreach (['gt', 'gte'] as $relation) {
                $other = $field[$relation] ?? null;
                if ($other === null || ! $touched($key, $other)) {
                    continue;
                }

                $mine = $value($key);
                $theirs = $value($other);
                if ($relation === 'gt' && $mine <= $theirs) {
                    $errors[$key] = '«'.$field['label'].'» يجب أن تكون بعد «'.self::field($other)['label'].'» ('.$theirs.').';
                } elseif ($relation === 'gte' && $mine < $theirs) {
                    $errors[$key] = '«'.$field['label'].'» لا تقلّ عن «'.self::field($other)['label'].'» ('.$theirs.') — ما دونها بلا أثر.';
                }
            }
        }

        // **طول الشريحة يتّسع في ساعات الحجز**: شريحة 120 دقيقة في نافذة ساعةٍ واحدة كانت تُحفظ ثمّ لا يُعرض موعدٌ واحد
        // في أيّ يوم بلا أيّ تنبيه (تدقيق الإعدادات 2026-09-30).
        if ($touched('consult_slot_minutes', 'consult_day_start', 'consult_day_end')
            && ! isset($errors['consult_day_end'])
            && $value('consult_slot_minutes') > ($value('consult_day_end') - $value('consult_day_start')) * 60) {
            $errors['consult_slot_minutes'] = '«'.self::field('consult_slot_minutes')['label'].'» أطول من ساعات الحجز ('
                .$value('consult_day_start').'–'.$value('consult_day_end').') — لن يُعرض أيّ موعد.';
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
                'days' => implode(',', self::days($key)),
                'bool' => (int) self::bool($key),
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
