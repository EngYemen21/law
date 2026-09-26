<?php

namespace App\Support;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * **الخطأ رسالةٌ لا صفحة — المُحوِّل الواحد من الاستثناء إلى الردّ** (يُنادى من `bootstrap/app.php`).
 *
 * كان الرفض يُحوَّل رسالةً لزيارات Inertia وحدها وبالرموز ٤٠٣/٤٠٩/٤٢٢ وحدها — فما عداها يسقط
 * صاحبه على صفحة لارافل الخام: فتحُ رابط غرفةٍ انتهت («Unprocessable Content»، رُصد في المتصفّح
 * 2026-09-26)، أو تنزيلٌ لا يُسمح به («Forbidden»)، أو سجلٌّ حُذف («Not Found» بالإنجليزيّة)، أو
 * جلسةٌ انتهت («Page Expired»). والإصلاح هنا لا في مئتين وخمسين `abort` — فكلّ حارسٍ جديد يُشرح
 * تلقائيّاً. التصنيف بحسب **من ينتظر الردّ**:
 *
 * - **نداء JSON** (axios/fetch بـ`Accept: application/json`): الرمز نفسه و`{message}` عربيّة — المتّصل
 *   يقرأ `message` ويعرضها، والتحويل كان يُقرأ ٢٠٠ فيُظنّ الإجراء نجح.
 * - **فعلُ Inertia** (نشرٌ/تعديلٌ/حذفٌ من زرّ): عودةٌ إلى الصفحة بـ`errors.message` — عقد `onError`
 *   القائم، وتعرضه الواجهة إشعاراً من موضعٍ واحد (`ServerFeedback.tsx`) ولو لم يكتب الزرّ `onError`.
 * - **فتحُ صفحة** (رابطٌ في تبويب، إعادة تحميل، نقرةُ Inertia): الرفضُ (٤٠٣/٤٢٢/…) يعود بصاحبه إلى حيث
 *   جاء — أو إلى لوحته — بإشعارٍ بالسبب؛ وما لا يوجد (٤٠٤/٤٠٥) أو عطلُ الخادم صفحةُ خطأٍ عربيّة داخل
 *   التطبيق (`pages/error.tsx`) بالرمز الصحيح.
 * - **ما سوى ذلك** (نشرٌ بلا Inertia: webhooks واختبارات، وجلبُ وسائط `<video>`): الرمز كما هو —
 *   وصفحته العربيّة من `resources/views/errors/4xx|5xx` إن عُرضت.
 *
 * وأثرُ العطل الحقيقيّ (5xx) في التطوير يبقى صفحةَ التتبّع كما هي — المطوّر يحتاجها — ويُسجَّل
 * في كلّ حال (العرض لا يمسّ التسجيل).
 */
final class ErrorResponse
{
    /**
     * **الرسالة الافتراضيّة لكلّ رمز — الخريطة الواحدة.** تُستعمل حين يأتي الاستثناء بلا نصٍّ عربيّ:
     * `abort(403)` بلا رسالة، أو نصّ الإطار الإنجليزيّ («No query results for model…»، «CSRF token
     * mismatch.»، «Too Many Attempts.»). نصُّ الحارس العربيّ يُقدَّم عليها دائماً — هو السبب الحقيقيّ.
     *
     * @var array<int, string>
     */
    public const MESSAGES = [
        400 => 'تعذّر فهم الطلب — حدّث الصفحة وأعد المحاولة.',
        401 => 'انتهت جلسة الدخول — سجّل الدخول من جديد للمتابعة.',
        403 => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
        404 => 'الصفحة أو السجلّ المطلوب غير موجود — ربّما حُذف أو تغيّر رابطه.',
        405 => 'هذا الإجراء غير متاحٍ بهذا الرابط — حدّث الصفحة واستعمل الزرّ المخصّص له.',
        409 => 'تغيّرت حالة السجلّ قبل إتمام الإجراء — حدّث الصفحة وأعد المحاولة.',
        410 => 'هذا الرابط لم يعد متاحاً.',
        413 => 'حجم الملفّ أو البيانات أكبر من المسموح.',
        419 => 'انتهت صلاحيّة الصفحة لطول بقائها مفتوحة — أعد المحاولة.',
        422 => 'تعذّر تنفيذ الإجراء في حالته الحاليّة.',
        423 => 'السجلّ مقفلٌ حاليّاً — أعد المحاولة بعد قليل.',
        429 => 'محاولاتٌ كثيرة في وقتٍ قصير — انتظر قليلاً ثمّ أعد المحاولة.',
        500 => 'حدث خطأٌ غير متوقّع في الخادم — أعد المحاولة بعد قليل، وإن تكرّر فأبلغ الإدارة.',
        502 => 'تعذّر الاتصال بخدمةٍ خارجيّة — أعد المحاولة بعد قليل.',
        503 => 'النظام في صيانةٍ قصيرة — أعد المحاولة بعد دقائق.',
        504 => 'استغرق الطلب وقتاً أطول من المسموح — أعد المحاولة بعد قليل.',
    ];

    /** عنوان صفحة الخطأ — ما يقرؤه الزائر أوّلاً قبل السبب. */
    public const TITLES = [
        400 => 'طلبٌ غير مفهوم',
        403 => 'غير مسموح',
        404 => 'الصفحة غير موجودة',
        405 => 'رابطٌ غير متاح',
        410 => 'الرابط لم يعد متاحاً',
        419 => 'انتهت صلاحيّة الصفحة',
        429 => 'محاولاتٌ كثيرة',
        503 => 'صيانةٌ قصيرة',
    ];

    /** رسالة أيّ رمزٍ ٤xx لا تسمّيه الخريطة. */
    private const FALLBACK_4XX = 'تعذّر تنفيذ الطلب — حدّث الصفحة وأعد المحاولة.';

    /**
     * رموزٌ تعني «لا شيء هنا» لا «مرفوض» — فتحُ رابطها يعرض صفحة الخطأ بدل العودة (مع كلّ 5xx).
     * ما عداها رفضٌ لسببٍ يعرفه صاحبه ويستطيع تصحيحه، فيعود من حيث جاء ومعه السبب.
     */
    private const PAGE_STATUSES = [400, 404, 405, 410];

    /**
     * **نصّ الرسالة المعروضة**: نصّ الحارس إن كان عربيّاً — وإلّا رسالة الرمز من الخريطة.
     *
     * الفحص بوجود حرفٍ عربيّ لا بخلوّ النصّ: نصوصُ الإطار الإنجليزيّة ليست فارغة، وكانت تصل
     * الشاشة كما هي («This action is unauthorized.»).
     */
    public static function message(int $status, ?string $raw = null): string
    {
        $raw = trim((string) $raw);

        if ($raw !== '' && preg_match('/\p{Arabic}/u', $raw) === 1) {
            return $raw;
        }

        return self::MESSAGES[$status] ?? ($status >= 500 ? self::MESSAGES[500] : self::FALLBACK_4XX);
    }

    public static function title(int $status): string
    {
        return self::TITLES[$status] ?? ($status >= 500 ? 'خطأٌ غير متوقّع' : 'تعذّر إكمال الطلب');
    }

    /**
     * **هل ينتظر المتّصل JSON؟** — المصدر الواحد لـ`shouldRenderJsonWhen` ولهذا المُحوِّل.
     *
     * زيارات Inertia مستثناة وإن أرسلت `X-Requested-With`: تنتظر صفحةً أو تحويلاً، لا جسم خطأ.
     */
    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || (! $request->inertia() && $request->expectsJson());
    }

    /**
     * **فتحُ صفحة** لا جلبُ بيانات: GET من شريط العنوان أو رابطٍ أو تبويبٍ جديد أو نقرة Inertia.
     *
     * `Sec-Fetch-Mode` يفرّق التنقّل (`navigate`) عن `fetch()` بلا ترويسة JSON وعن جلب الوسائط
     * (`no-cors` لعنصر `<video>`): أولئك ينتظرون الرمز نفسه — التحويل يجعل `fetch` يقرأ صفحةً
     * بـ٢٠٠ ويجعل المشغّل يحاول تشغيل HTML. وغيابُ الترويسة (متصفّحٌ قديم، عميل الاختبار) يُعامل تنقّلاً.
     *
     * والتنقّل يطلب HTML: المتصفّح يرسل `text/html` في `Accept` لكلّ صفحة، وعميلُ آلةٍ (تطبيق تقويمٍ
     * يجلب `.ics`، `curl`) لا يطلبها — فيأخذ الرمز لا تحويلاً إلى صفحةٍ لا يقرؤها.
     */
    public static function isPageVisit(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->inertia()) {
            return true;
        }

        return in_array($request->headers->get('Sec-Fetch-Mode'), [null, 'navigate'], true)
            && str_contains((string) $request->headers->get('Accept'), 'text/html');
    }

    /**
     * المُحوِّل — `null` يعني «اترك الإطار يتصرّف» (التحقّق من الحقول، إعادة الدخول، تتبّع التطوير).
     */
    public static function render(Throwable $e, Request $request): ?Response
    {
        // التحقّق من الحقول له شكله الذي تقرؤه النماذج (حقيبة الأخطاء/422)، والردّ الجاهز ردٌّ مقصود
        if ($e instanceof ValidationException || $e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof AuthenticationException) {
            // الصفحات تُحوَّل إلى الدخول (سلوك الإطار)؛ ونداء JSON يأخذ السبب بالعربيّة لا «Unauthenticated.»
            return self::wantsJson($request) ? response()->json(['message' => self::message(401)], 401) : null;
        }

        $http = $e instanceof HttpExceptionInterface;

        // عطلٌ حقيقيّ في التطوير: صفحة التتبّع هي ما يحتاجه المطوّر — لا رسالةٌ مهذّبة تُخفيه
        if (! $http && config('app.debug')) {
            return null;
        }

        $status = $http ? $e->getStatusCode() : 500;
        $message = self::message($status, $http ? $e->getMessage() : null);
        $headers = $http ? $e->getHeaders() : [];

        if (self::wantsJson($request)) {
            return response()->json(['message' => $message], $status, $headers);
        }

        // قبل بدء الجلسة (حجم رفعٍ زائد، وضع الصيانة): لا تحويلَ يحمل رسالة. طلبُ Inertia يأخذ JSON
        // تقرؤه `ServerFeedback.tsx` إشعاراً بدل نافذة HTML؛ وغيره صفحة `errors/4xx|5xx` العربيّة.
        if (! $request->hasSession()) {
            return $request->inertia() ? response()->json(['message' => $message], $status, $headers) : null;
        }

        if (! self::isPageVisit($request)) {
            return $request->inertia() ? self::backWithError($message) : null;
        }

        if ($status >= 500 || in_array($status, self::PAGE_STATUSES, true)) {
            return self::page($request, $status, $message);
        }

        return self::redirectWithError($request, $message) ?? self::page($request, $status, $message);
    }

    /**
     * **فعلٌ مرفوض ⇒ العودة إلى الصفحة نفسها بالسبب** في `errors.message` — العقد الذي تقرؤه
     * معالجات `onError` في الشاشات، ويعرضه `ServerFeedback.tsx` لمن لم يكتب معالجاً.
     *
     * ٣٠٣ لا ٣٠٢: الرفض قد يقع قبل وسيط Inertia (ربط سجلٍّ محذوف، انتهاء الجلسة) فلا يحوّله هو،
     * والمتصفّح يتبع ٣٠٢ بعد PUT/DELETE بالطريقة نفسها فيطلب الصفحة بـDELETE.
     */
    public static function backWithError(string $message): RedirectResponse
    {
        return redirect()->to(url()->previous(), 303)->withErrors(['message' => $message]);
    }

    /**
     * **صفحةٌ مرفوضة ⇒ إلى حيث جاء صاحبها، أو إلى لوحته، ومعه السبب إشعاراً** (`flash.error`).
     *
     * «حيث جاء» من ترويسة `Referer` وحدها — لا من «الصفحة السابقة» في الجلسة: الجلسة تحفظ الرابط
     * المرفوض نفسه عند فتحه، فإعادة تحميله تعود إليه ⇒ حلقة. والمُحيل من موقعٍ آخر (رابطٌ في بريد)
     * أو الصفحة نفسها ليس عودة. و`null` حين تكون اللوحة نفسها المرفوضة — فتُعرض صفحة الخطأ.
     */
    public static function redirectWithError(Request $request, string $message, ?string $to = null): ?RedirectResponse
    {
        $to ??= self::referer($request) ?? ($request->user()?->role->home() ?? '/');

        if (self::samePath($to, $request)) {
            return null;
        }

        return redirect()->to($to, 303)->with('error', $message);
    }

    /** صفحة الخطأ العربيّة داخل التطبيق بالرمز الصحيح (`pages/error.tsx`). */
    public static function page(Request $request, int $status, string $message): ?Response
    {
        try {
            self::shareAppProps($request);

            return Inertia::render('error', [
                'status' => $status,
                'title' => self::title($status),
                'message' => $message,
            ])->toResponse($request)->setStatusCode($status);
        } catch (Throwable) {
            // تعذّر بناء صفحة التطبيق نفسها (قاعدة بيانات متوقّفة مثلاً): الصفحة الساكنة العربيّة
            return null;
        }
    }

    /**
     * **الخصائص المشتركة لصفحة الخطأ** — ليظهر الشريط الجانبيّ وتبقى الجلسة.
     *
     * رفضُ ربط السجلّ (`{consult}` محذوفة) يقع في `SubstituteBindings` قبل وسيط Inertia، فلا تُشارَك
     * الخصائص ولا تُضبط النسخة. فيُنادى الوسيط نفسه هنا — لا نسخةٌ ثانية من قائمته.
     */
    private static function shareAppProps(Request $request): void
    {
        if (Inertia::getShared('auth') !== null || ! $request->hasSession()) {
            return;
        }

        $middleware = app(HandleInertiaRequests::class);
        Inertia::version(fn () => $middleware->version($request));
        Inertia::share($middleware->share($request));
    }

    private static function referer(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer');

        if ($referer === '' || parse_url($referer, PHP_URL_HOST) !== $request->getHost()) {
            return null;
        }

        return self::samePath($referer, $request) ? null : $referer;
    }

    private static function samePath(string $url, Request $request): bool
    {
        return '/'.trim((string) parse_url($url, PHP_URL_PATH), '/') === '/'.trim($request->path(), '/');
    }
}
