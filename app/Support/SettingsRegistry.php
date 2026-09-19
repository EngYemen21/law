<?php

namespace App\Support;

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
            'alerts' => 'المهل والتنبيهات',
            'office' => 'بيانات المكتب في المستندات والبريد',
        ];
    }

    /**
     * **الوصف الكامل لكلّ متغيّر.** والافتراض هنا هو الثابت المُعلَن في الشيفرة نفسه لا نسخةً
     * منه — فلا ينزلق أحدهما عن الآخر بصمت (ويحرسه `AdminSettingsTest`).
     *
     * `forwardOnly` يعني: التغيير يسري على ما يُنشأ بعده وحده، وما مضى محفوظٌ على صفّه.
     *
     * @return array<string, array{group:string,label:string,hint:string,type:'int'|'string'|'date',default:mixed,min?:int,max?:int,rules:array<int,string>,forwardOnly?:bool}>
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
            'consult_autoclose_hours' => [
                'group' => 'alerts',
                'label' => 'إغلاق الاستشارة الفائتة بعد (ساعات)',
                'hint' => 'المدّة بعد موعد الجلسة التي تُوسَم بعدها الاستشارة التي لم تُعقد «لم يحضر» آلياً.',
                'type' => 'int',
                'default' => 12,
                'min' => 1,
                'max' => 72,
                'rules' => ['required', 'integer', 'min:1', 'max:72'],
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

            // ── بيانات المكتب ──
            'office_name' => [
                'group' => 'office',
                'label' => 'اسم المكتب',
                'hint' => 'يظهر في رأس كلّ مستند PDF وفي تذييل كلّ رسالة بريد.',
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
