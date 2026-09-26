<?php

namespace App\Services\Ai;

/**
 * أسباب الفشل المعياريّة — قيم قصيرة ثابتة تُخزَّن في `ai_runs.failure_code`
 * وتُسجَّل في الـlogs. **لا تحمل أسراراً ولا محتوى مستندات ولا نصوص محادثات**؛
 * التشخيص يجري بالسبب و`trace_id` لا بإفشاء المدخلات.
 */
final class AiFailure
{
    /** لا مزوّد مهيّأ أصلاً (لا مفاتيح) أو كلّهم قيد التهدئة. */
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** نُودي المزوّد وردّ بخطأ أو بنصّ فارغ. */
    public const PROVIDER_ERROR = 'provider_error';

    /** ردّ النموذج ليس JSON صالحاً أو تعذّر استخلاصه. */
    public const INVALID_JSON = 'invalid_json';

    /** JSON صالح لكنه لا يطابق العقد المطلوب (حقول ناقصة/قيم خارج المسموح). */
    public const INVALID_STRUCTURE = 'invalid_structure';

    public const QUOTA_EXHAUSTED = 'quota_exhausted';

    /**
     * أوقفه المكتب لتجاوز الميزانيّة — لا عطل مزوّد.
     * التمييز مقصود: عطلُ المزوّد يُصلَح بالانتظار، وهذا يُصلَح بقرارٍ في اللوحة.
     */
    public const BUDGET_EXCEEDED = 'budget_exceeded';

    /**
     * أطفأ المكتب هذا المسار — لا عطل.
     * التمييز مقصود: العطل يُنتظَر زواله، وهذا قرارٌ يُرفع في اللوحة.
     */
    public const TASK_DISABLED = 'task_disabled';

    public const RATE_LIMITED = 'rate_limited';

    public const UNAUTHORIZED = 'unauthorized';

    public const BAD_REQUEST = 'bad_request';

    public const SERVER_ERROR = 'server_error';

    public const CONTENT_BLOCKED = 'content_blocked';

    /**
     * **اسم السبب كما يقرؤه المدير** — بجوار الرموز لا في كلّ شاشة. كان الصندوق ولوحة التشغيل
     * يعرضان الرمز الخام (`provider_error`)، فرمزٌ يُضاف هنا يصل الشاشات بلا تعديلٍ فيها.
     */
    private const LABELS = [
        self::PROVIDER_UNAVAILABLE => 'لا مزوّد متاح',
        self::PROVIDER_ERROR => 'خطأ من المزوّد أو ردّ فارغ',
        self::INVALID_JSON => 'ردّ النموذج بصيغة غير صالحة',
        self::INVALID_STRUCTURE => 'ردّ النموذج لا يطابق العقد المطلوب',
        self::QUOTA_EXHAUSTED => 'نفدت حصّة المزوّد',
        self::BUDGET_EXCEEDED => 'أُوقف لتجاوز الميزانيّة',
        self::TASK_DISABLED => 'المسار مُطفأ من اللوحة',
        self::RATE_LIMITED => 'تجاوز حدّ النداءات لدى المزوّد',
        self::UNAUTHORIZED => 'مفتاح المزوّد مرفوض',
        self::BAD_REQUEST => 'طلب مرفوض من المزوّد',
        self::SERVER_ERROR => 'عطل في خادم المزوّد',
        self::CONTENT_BLOCKED => 'حجبه المزوّد لسياسة المحتوى',
    ];

    /** اسم السبب بالعربيّة؛ الرمز المجهول يُقال «غير مصنَّف» ولا يُعرض رمزه الإنجليزيّ. */
    public static function label(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::LABELS[$code] ?? 'سبب غير مصنَّف';
    }

    /** كلّ الرموز المعلنة — لحارس الاختبار: رمزٌ بلا اسمٍ عربيّ يُسقطه. @return list<string> */
    public static function codes(): array
    {
        return array_values(array_filter(
            (new \ReflectionClass(self::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC),
            'is_string',
        ));
    }

    /**
     * تصنيف فشل المزوّد إلى رمز معياريّ — **بلا تسريب جسم الاستجابة**.
     *
     * كان يُسجَّل `$response->body()` كاملاً في ثلاثة مواضع. واستجابات الخطأ من
     * المزوّدين — خاصّةً حجب السلامة وأخطاء التحقّق — تُعيد أجزاءً من الطلب نفسه،
     * أي نصوص مستندات العملاء ومحادثاتهم. فتهبط في `laravel.log` بلا حاجة، وهو ما
     * تمنعه الخطة نصّاً: «لا تضع نص الملف الكامل في exception أو log».
     *
     * الجسم يُفحص هنا في الذاكرة ولا يخرج منها: يُعاد **رمزٌ** لا نصّ.
     */
    public static function classify(int $status, string $body): string
    {
        return match (true) {
            $status === 401 || $status === 403 => self::UNAUTHORIZED,
            $status === 429 => self::RATE_LIMITED,
            str_contains($body, 'RESOURCE_EXHAUSTED'), str_contains($body, '1113') => self::QUOTA_EXHAUSTED,
            str_contains($body, 'SAFETY'), str_contains($body, 'blocked') => self::CONTENT_BLOCKED,
            $status >= 500 => self::SERVER_ERROR,
            $status >= 400 => self::BAD_REQUEST,
            default => self::PROVIDER_ERROR,
        };
    }
}
