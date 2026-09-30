<?php

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MarksConversationReply;
use App\Support\ErrorResponse;
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
            // مسارات الردّ وحدها تنقل مسؤوليّة المحادثة (قرار المالك 2026-09-25) — انظر ConversationHandler
            'conversation.reply' => MarksConversationReply::class,
        ]);

        // إشعارات Zoom وبوّابات الدفع لا ترسل رمز CSRF؛ محميّة بتوقيع/سرّ في المتحكّم
        $middleware->validateCsrfTokens(except: ['webhooks/zoom', 'webhooks/moyasar', 'webhooks/payments/*']);

        // الثقة بترويسات الوسيط (X-Forwarded-*) — **من الخادم نفسه وحده.**
        //
        // تُحتاج خلف ngrok في التطوير ليبني Laravel روابط https صحيحة (رابط عودة ميسّر) — وngrok
        // يتّصل من الجهاز نفسه. أمّا الإنتاج فـnginx يسلّم php-fpm مباشرةً (`fastcgi_pass`، انظر
        // DEPLOYMENT_AR.md)، فعنوان الاتّصال **هو** عنوان الزائر، ولا وسيطَ يُصدَّق.
        //
        // كانت الثقة بـ`*`: فأيّ زائرٍ يرسل `X-Forwarded-For` بعنوانٍ يختاره فيصدّقه النظام —
        // فيُزوَّر العنوان في سجلّ التدقيق وفي عنوان مُرسِل الرسائل. (حدود رمز الدخول لم تتأثّر:
        // بُنيت على الجلسة والهويّة لا الـIP لهذا السبب نفسه.) وإن وُضع أمام الخادم وسيطٌ يوماً
        // (Cloudflare مثلاً) تُضاف نطاقاته هنا — لا `*`.
        $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // نداءات axios في الواجهة (Accept: application/json) تحتاج جسم خطأ حقيقياً (422/403).
        // كانت تُعاد توجيهاً فيتبعه axios ويقرأ 200 فيشتعل then() ويظهر توست «✅ تم…» بلا تنفيذ.
        // زيارات Inertia مستثناة: تعتمد على التحويل مع أخطاء الجلسة (onError).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => ErrorResponse::wantsJson($request));

        /*
         * **الخطأ رسالةٌ لا صفحة — من موضعٍ واحد** (`App\Support\ErrorResponse`).
         *
         * كان التحويل هنا لزيارات Inertia وحدها وبالرموز ٤٠٣/٤٠٩/٤٢٢ وحدها، فبقي فتحُ رابطٍ مرفوض
         * في تبويب (غرفةٌ انتهت، تنزيلٌ لا يُسمح به) صفحةَ لارافل الخام «Unprocessable Content»،
         * والسجلّ المحذوف «Not Found» بالإنجليزيّة، و`abort(403)` بلا نصٍّ «Forbidden». الآن يُصنَّف
         * الطلب مرّةً (JSON · فعل Inertia · فتح صفحة) وكلّ رمزٍ يأخذ رسالته العربيّة من خريطةٍ واحدة —
         * فأيّ `abort` جديد يُشرح تلقائيّاً. التفصيل والأسباب في رأس الصنف.
         *
         * ونشرُ النماذج بلا `X-Inertia` (webhooks، واختبارات الحرّاس) يبقى برمزه — فتبقى تأكيدات
         * `->post(...)->assertStatus(422)` تقيس الحارس نفسه.
         */
        $exceptions->render(fn (Throwable $e, Request $request) => ErrorResponse::render($e, $request));
    })->create();
