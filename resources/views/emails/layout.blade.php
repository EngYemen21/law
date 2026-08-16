<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $title ?? 'النظام الإداري لمكاتب المحاماه' }}</title>
    <style>
        /* Google Fonts & Reset */
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap');

        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }

        body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            min-width: 100%;
            background-color: #EEF2F6;
            font-family: 'Tajawal', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #13314F;
            direction: rtl;
            text-align: right;
        }

        @media only screen and (max-width: 620px) {
            .email-container {
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 0 !important;
            }
            .content-cell {
                padding: 24px 18px !important;
            }
            .header-cell {
                padding: 26px 20px 22px !important;
            }
            .footer-cell {
                padding: 22px 18px !important;
            }
            .meta-table td {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }
            .meta-table td.meta-label {
                padding-bottom: 4px !important;
                border-bottom: none !important;
            }
            .meta-table td.meta-value {
                padding-top: 0 !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#EEF2F6;font-family:'Segoe UI',Tahoma,Arial,sans-serif;direction:rtl;text-align:right;-webkit-font-smoothing:antialiased;">

    @php
        $appUrl = rtrim((string) config('app.url', 'https://salaselbabel.net'), '/');
        if (str_contains($appUrl, 'localhost') || str_contains($appUrl, '.test') || str_contains($appUrl, '127.0.0.1')) {
            $appUrl = 'https://salaselbabel.net';
        }
        $logoSrc = $appUrl.'/images/021.png';
    @endphp

    @if(!empty($preview))
    <div style="display:none;font-size:1px;color:#EEF2F6;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
        {{ $preview }}
    </div>
    @endif

    {{-- خلفية البريد الخارجية --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#EEF2F6;margin:0;padding:24px 0 40px;width:100% !important;">
        <tr>
            <td align="center" style="padding:0 12px;">

                {{-- الحاوية المركزية للبطاقة --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center" style="width:100%;max-width:600px;background-color:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #DFE6ED;box-shadow:0 8px 24px rgba(10,42,85,0.08);">

                    {{-- شريط الهوية العلوي --}}
                    <tr>
                        <td height="5" style="background-color:#C0832B;background:linear-gradient(90deg, #C0832B 0%, #11A0C8 50%, #0E5C9C 100%);font-size:1px;line-height:1px;">&nbsp;</td>
                    </tr>

                    {{-- رأس الرسالة (Header) --}}
                    <tr>
                        <td align="center" style="background-color:#0A2A55;background:linear-gradient(135deg, #0A2A55 0%, #0E5C9C 55%, #11A0C8 120%);padding:28px 24px 22px;text-align:center;">

                            {{-- صندوق الشعار --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 14px;">
                                <tr>
                                    <td align="center" style="background-color:#FFFFFF;border-radius:12px;padding:8px 18px;text-align:center;">
                                        <img src="{{ $logoSrc }}" alt="النظام الإداري لمكاتب المحاماة" width="200" style="display:block;margin:0 auto;width:200px;max-width:100%;height:auto;max-height:55px;border:0;" />
                                    </td>
                                </tr>
                            </table>

                            {{-- اسم النظام --}}
                            <div style="font-size:20px;font-weight:bold;color:#FFFFFF;line-height:1.3;margin-bottom:6px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
                                النظام الإداري لمكاتب المحاماة
                            </div>

                            {{-- الشارة الفرعية --}}
                            <div style="display:inline-block;background-color:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);padding:3px 14px;border-radius:16px;font-size:12px;color:#FFFFFF;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
                                {{ $subtitle ?? 'منظومة المحاماة والاستشارات القانونية' }}
                            </div>
                        </td>
                    </tr>

                    {{-- محتوى الرسالة الرئيسي --}}
                    <tr>
                        <td style="padding:30px 28px 24px;background-color:#FFFFFF;color:#1E293B;font-size:15px;line-height:1.8;direction:rtl;text-align:right;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- مساحة الدعم والتواصل --}}
                    <tr>
                        <td style="padding:0 28px 20px;background-color:#FFFFFF;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;">
                                <tr>
                                    <td align="center" style="padding:12px 16px;font-size:12.5px;color:#64748B;text-align:center;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
                                        هل تحتاج إلى مساعدة؟ يمكنك دائماً التواصل مع فريق الدعم القانوني عبر المنصة أو البريد الرسمي.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- تذييل الرسالة (Footer) --}}
                    <tr>
                        <td align="center" style="padding:22px 28px;background-color:#071E3D;border-top:1px solid #0D2C54;text-align:center;color:#94A3B8;font-size:12px;line-height:1.7;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
                            <div style="color:#CBD5E1;margin-bottom:8px;font-size:11px;border-bottom:1px solid rgba(255,255,255,0.1);padding-bottom:8px;">
                                🔒 <b>إشعار سرية:</b> هذه المراسلة موجهة خصيصاً للمستلم المعني وتحتوي على بيانات قانونية خاصة. يُرجى عدم الرد على هذه الرسالة الآلية.
                            </div>
                            <div style="color:#F1F5F9;font-weight:bold;font-size:13px;margin-bottom:3px;">
                                النظام الإداري لمكاتب المحاماة
                            </div>
                            <div style="color:#94A3B8;font-size:11px;">
                                المملكة العربية السعودية · جميع الحقوق محفوظة © {{ date('Y') }}
                            </div>
                        </td>
                    </tr>

                </table>
                {{-- نهاية بطاقة الرسالة --}}

            </td>
        </tr>
    </table>

</body>
</html>
