{{--
    صفحة التحقّق من الوثيقة — يفتحها رمز الاستجابة المطبوع (`DocumentVerificationController`).

    Blade مستقلّة لا صفحة Inertia: تُفتح من كاميرا جوّال بلا جلسة، فلا يلزمها تطبيق الواجهة ولا
    حزمة Vite — صفحةٌ خفيفة تعمل ولو لم تُبنَ الواجهة بعد نشرٍ جديد.
    لا بيانات شخصيّة هنا: ما في `$facts` حدٌّ أدنى يبنيه `DocumentVerification::facts`.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>التحقّق من وثيقة — {{ $office }}</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;background:#EEF2F6;font-family:'Tajawal',Tahoma,Arial,sans-serif;color:#13314F;display:flex;align-items:center;justify-content:center;padding:16px}
        .card{background:#fff;border:1px solid #D3E2F0;border-radius:14px;max-width:460px;width:100%;overflow:hidden}
        .hd{background:#0E5C9C;color:#fff;padding:14px 18px;font-weight:800;font-size:15px}
        .bd{padding:18px}
        .state{display:flex;gap:10px;align-items:flex-start;border-radius:10px;padding:12px 14px;font-size:14px;line-height:1.8;font-weight:700}
        .ok{background:#E6F6EF;color:#1E7A55}
        .bad{background:#FDECEA;color:#A93226}
        .warn{background:#FBF1E3;color:#8A5A12}
        table{width:100%;border-collapse:collapse;margin-top:14px;font-size:13.5px}
        td{padding:9px 4px;border-bottom:1px solid #E7EFF6;vertical-align:top}
        td:first-child{color:#607689;width:42%}
        td:last-child{font-weight:700}
        .note{margin-top:14px;font-size:12px;color:#607689;line-height:1.8}
    </style>
</head>
<body>
    <main class="card">
        <div class="hd">{{ $office }} — التحقّق من وثيقة</div>
        <div class="bd">
            @if ($state === 'valid')
                <div class="state ok">✓ وثيقةٌ صادرة من {{ $office }}: {{ $facts['label'] }}</div>
                <table>
                    @foreach ($facts['rows'] as [$label, $value])
                        <tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
                    @endforeach
                </table>
                <p class="note">الحالة المعروضة هي حالة الوثيقة الآن في سجلّات المكتب، لا يوم طباعتها. وإن خالفت ما في الورقة فالمعتمد ما هنا.</p>
            @elseif ($state === 'missing')
                <div class="state warn">لا توجد في سجلّات المكتب وثيقةٌ بهذا المرجع — ربّما أُلغيت أو حُذفت.</div>
                <p class="note">للاستفسار تواصل مع المكتب مباشرةً.</p>
            @else
                <div class="state bad">✕ تعذّر التحقّق: رابط التحقّق غير صالح أو عُدِّل بعد إصداره، فلا يمكن تأكيد أنّ هذه الوثيقة صادرة من المكتب.</div>
                <p class="note">امسح الرمز المطبوع على الوثيقة نفسها مرّةً أخرى دون تعديل الرابط، أو تواصل مع المكتب.</p>
            @endif
        </div>
    </main>
</body>
</html>
