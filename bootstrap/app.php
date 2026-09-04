<?php

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'permission' => EnsurePermission::class,
            'active' => EnsureActive::class,
        ]);

        // Zoom/Moyasar webhooks لا ترسل رمز CSRF؛ محميّة بتوقيع/سرّ في المتحكّم
        $middleware->validateCsrfTokens(except: ['webhooks/zoom', 'webhooks/moyasar']);

        // الثقة بترويسات الوسيط (X-Forwarded-*) — خلف ngrok/Reverse proxy يبني Laravel
        // روابط https صحيحة (رابط عودة ميسّر callback_url آمن)، ويكتشف بروتوكول الطلب الحقيقي.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // نداءات axios في الواجهة (Accept: application/json) تحتاج جسم خطأ حقيقياً (422/403).
        // كانت تُعاد توجيهاً فيتبعه axios ويقرأ 200 فيشتعل then() ويظهر توست «✅ تم…» بلا تنفيذ.
        // زيارات Inertia مستثناة: تعتمد على التحويل مع أخطاء الجلسة (onError).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || (! $request->inertia() && $request->expectsJson()),
        );

        /*
         * **رسالةُ الرفض تصل صاحبها.**
         *
         * التعليق أعلاه يَعِد بأن زيارات Inertia «تعتمد على التحويل مع أخطاء الجلسة
         * (onError)» — ولم يكن شيءٌ يُحقّق ذلك. فـ`abort(422, '…')` يرمي
         * `HttpException` لا `ValidationException`، والردّ صفحةُ HTML بلا ترويسة
         * `X-Inertia` وبلا تحويل ⇒ **`onError` لا يُنادى قطّ**. قِستُه بطلبٍ حيّ.
         *
         * فثلاثة عشر حارساً في متحكّم الاستشارات وحده تمنع الضرر ولا تشرح: يرى
         * الموظّف نافذة خطأ خام بدل «فات موعد هذه الجلسة — سجّل لم يحضر أو أعد
         * جدولتها». الحارس يعمل والرسالة تضيع.
         *
         * **والتحويل هنا لا في ثلاثة عشر موضعاً** — فأيّ `abort` جديد يُشرح تلقائياً.
         *
         * ويقتصر على ٤٠٣/٤٠٩/٤٢٢: رفضٌ يعرف المستخدم سببه ويستطيع تصحيحه. أمّا ٤٠٤
         * و٤١٩ و5xx فتبقى كما هي — صفحةٌ لا توجد ليست إجراءً مرفوضاً، و«انتهت الجلسة»
         * لها معالجتها في Inertia.
         *
         * ولا يمسّ الاختبارات: عميل الاختبار لا يرسل `X-Inertia`، فتبقى تأكيدات
         * `assertStatus(422)` صحيحةً على حالها.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->inertia() || ! in_array($e->getStatusCode(), [403, 409, 422], true)) {
                return null;
            }

            return back()->withErrors([
                'message' => $e->getMessage() ?: 'تعذّر تنفيذ الإجراء في حالته الحاليّة.',
            ]);
        });
    })->create();
