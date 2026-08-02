<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#EAEEF1;font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;color:#13314F;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preview ?? '' }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EAEEF1;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(10,42,85,.10);">
                    {{-- الترويسة --}}
                    <tr>
                        <td style="background:linear-gradient(135deg,#0A2A55 0%,#0E5C9C 55%,#11A0C8 130%);padding:24px 28px;text-align:center;">
                            <div style="font-size:20px;font-weight:800;color:#ffffff;">{{ config('app.name') }}</div>
                            <div style="font-size:12px;color:#cfe3f2;margin-top:4px;">{{ $subtitle ?? 'المنصّة القانونية' }}</div>
                        </td>
                    </tr>

                    {{-- المحتوى --}}
                    <tr>
                        <td style="padding:28px;line-height:1.9;font-size:14px;color:#33415c;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- التذييل --}}
                    <tr>
                        <td style="padding:16px 28px;background:#F6F8FA;border-top:1px solid #EDF2F6;text-align:center;font-size:11px;color:#90A2B2;">
                            هذه رسالة آليّة من {{ config('app.name') }} — يُرجى عدم الردّ عليها.<br>
                            © {{ date('Y') }} {{ config('app.name') }} — جميع الحقوق محفوظة.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
