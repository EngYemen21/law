<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- أيقونة الموقع — مشتقّة من شعار «سلاسل بابل» (public/images/logo-svg.svg).
             كانت الصفحة بلا أيّ رابطٍ لأيقونة، فيسقط المتصفّح على /favicon.ico وهو
             شعار لارافل الافتراضيّ: التبويب يحمل هويّةً ليست هويّة المكتب. --}}
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="theme-color" content="#0E5C9C">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,400;1,700&family=Cairo:wght@400;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        {{-- عنوان التبويب قبل أن تعمل الواجهة: اسم المكتب من إعداده (`office_name`) — لا `APP_NAME`
             المبنيّ في البيئة ولا نصٌّ منقوش. وبعد التحميل يتولّاه `title` في `app.tsx` من الخاصيّة
             المشتركة `settings.office_name` نفسها، فمصدر الاسم واحدٌ في الطريقين. --}}
        <x-inertia::head>
            <title>{{ \App\Support\SettingsRegistry::str('office_name') }}</title>
        </x-inertia::head>
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>
