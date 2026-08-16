<!DOCTYPE html>
<html lang="ar" dir="rtl">
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
    @if(!empty($googleSchema))
        {!! $googleSchema !!}
    @endif
</head>
<body style="margin:0;padding:0;background-color:#EEF2F6;font-family:'Tajawal',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#13314F;direction:rtl;text-align:right;">

    @php
        $logoPath = public_path('images/021.png');
        $hasMessage = isset($message) && is_object($message) && method_exists($message, 'embed');
        $logoSrc = ($hasMessage && file_exists($logoPath))
            ? $message->embed($logoPath)
            : rtrim((string) config('app.url', 'http://localhost'), '/').'/images/021.png';
    @endphp

    {{-- نص المعاينة الخفي (Preheader) --}}
    @if(!empty($preview))
    <div style="display:none;font-size:1px;color:#EEF2F6;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;">
        {{ $preview }}
        &#847; &zwnj; &nbsp; &#8199; &shy; &#847; &zwnj; &nbsp; &#8199; &shy; &#847; &zwnj; &nbsp; &#8199; &shy;
    </div>
    @endif

    {{-- الحاوية الرئيسية الكاملة --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#EEF2F6;padding:32px 12px 48px;table-layout:fixed;">
        <tr>
            <td align="center" style="padding:0;">

                {{-- بطاقة الرسالة المركزية --}}
                <table role="presentation" class="email-container" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 12px 36px rgba(10,42,85,0.09);border:1px solid #DFE6ED;">

                    {{-- شريط الهوية العلوي الذهبي والأزرق --}}
                    <tr>
                        <td height="5" style="background:linear-gradient(90deg, #C0832B 0%, #11A0C8 50%, #0E5C9C 100%);font-size:1px;line-height:1px;">&nbsp;</td>
                    </tr>

                    {{-- ترويسة الرسالة مع الشعار واسم المنصة --}}
                    <tr>
                        <td class="header-cell" style="background:linear-gradient(135deg, #0A2A55 0%, #0E5C9C 55%, #11A0C8 120%);padding:28px 24px 24px;text-align:center;color:#ffffff;">

                            {{-- صورة شعار المنصة الرسمي --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 16px;">
                                <tr>
                                    <td align="center" style="background:#FFFFFF;border:2px solid rgba(255,255,255,0.4);border-radius:14px;text-align:center;vertical-align:middle;padding:8px 16px;box-shadow:0 8px 24px rgba(0,0,0,0.18);">
                                        <img src="{{ $logoSrc }}" alt="النظام الإداري لمكاتب المحاماة" width="220" style="display:block;margin:0 auto;width:220px;max-width:100%;height:auto;max-height:60px;object-fit:contain;border:0;">
                                    </td>
                                </tr>
                            </table>

                            {{-- اسم المنصة / النظام --}}
                            <div style="font-size:20px;font-weight:800;letter-spacing:-0.2px;color:#ffffff;line-height:1.3;margin-bottom:6px;">
                                النظام الإداري لمكاتب المحاماة
                            </div>

                            {{-- الشارة الفرعية / التصنيف --}}
                            <div style="display:inline-block;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600;color:#E1F2FB;margin-top:2px;">
                                {{ $subtitle ?? 'منظومة المحاماة والاستشارات القانونية' }}
                            </div>
                        </td>
                    </tr>

                    {{-- جسم المحتوى الرئيسي --}}
                    <tr>
                        <td class="content-cell" style="padding:34px 34px 28px;line-height:1.85;font-size:14.5px;color:#2C4258;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- مساحة الدعم والتواصل السريع --}}
                    <tr>
                        <td style="padding:0 34px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F7F9FB;border:1px solid #EBF0F4;border-radius:12px;margin-bottom:24px;">
                                <tr>
                                    <td style="padding:12px 18px;font-size:12.5px;color:#607689;text-align:center;">
                                        هل تحتاج إلى مساعدة؟ يمكنك دائماً التواصل مع فريق الدعم القانوني عبر المنصة أو البريد الرسمي.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- تذييل الرسالة الرسمي --}}
                    <tr>
                        <td class="footer-cell" style="padding:22px 34px 26px;background-color:#071E3D;border-top:1px solid #0D2C54;text-align:center;color:#8AA4BD;font-size:11.5px;line-height:1.75;">

                            {{-- تنبيه السرية المهنية --}}
                            <div style="color:#A1B8CE;margin-bottom:10px;font-size:11px;line-height:1.6;border-bottom:1px solid rgba(255,255,255,0.08);padding-bottom:10px;">
                                🔒 <b>إشعار سرية وأمان:</b> هذه المراسلة موجهة خصيصاً للمستلم المعني وتحتوي على بيانات قانونية ومهنية خاصة ومحمية. يُرجى عدم الرد على هذه الرسالة الآلية.
                            </div>

                            <div style="color:#C6D7E7;font-weight:700;font-size:12.5px;margin-bottom:4px;">
                                النظام الإداري لمكاتب المحاماه
                            </div>

                            <div style="color:#7894AE;font-size:11px;">
                                المملكة العربية السعودية · الرياض · جميع الحقوق محفوظة © {{ date('Y') }}
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
