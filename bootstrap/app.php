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
    })->create();
