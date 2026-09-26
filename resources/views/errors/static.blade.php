{{--
    **صفحة الخطأ الساكنة — الملاذ الأخير، بالعربيّة.**

    ما يستطيعه التطبيق يُعرض داخله (`pages/error.tsx` عبر `App\Support\ErrorResponse`). هذه للحالات
    التي لا تُبنى فيها صفحة التطبيق: خطأٌ قبل بدء الجلسة (رفعٌ أكبر من المسموح، وضع الصيانة)، أو نشرٌ
    بلا Inertia، أو تعذّر بناء صفحة التطبيق نفسها (قاعدة بيانات متوقّفة). لذا بلا قاعدة بيانات
    ولا Vite ولا خطوط خارجيّة — HTML وحده. والنصّ من الخريطة الواحدة نفسها لا نسخةٌ هنا.
--}}
@php
    $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 500;
    $message = \App\Support\ErrorResponse::message($status, $status < 500 ? $exception->getMessage() : null);
    $title = \App\Support\ErrorResponse::title($status);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: Tahoma, 'Segoe UI', sans-serif; background: #f4f7fb; color: #1b2a3a; }
        main { max-width: 420px; margin: 16px; padding: 32px 28px; background: #fff; border-radius: 16px;
               box-shadow: 0 8px 30px rgba(14, 92, 156, .12); text-align: center; }
        .code { font-size: 44px; font-weight: 700; color: #0e5c9c; margin: 0 0 8px; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { font-size: 15px; line-height: 1.8; color: #4a5a6a; margin: 0 0 24px; }
        a { display: inline-block; padding: 10px 22px; border-radius: 10px; background: #0e5c9c; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <main data-error-message="{{ $message }}">
        <div class="code">{{ $status }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <a href="/">العودة إلى الصفحة الرئيسيّة</a>
    </main>
</body>
</html>
