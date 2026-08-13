@extends('emails.layout', [
    'preview' => 'رمز التحقق الآمن الخاص بك هو: '.$code,
    'subtitle' => 'التحقق الآمن والوصول'
])

@section('content')
    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:12px;">
        {{ $name ? 'مرحباً '.$name.'،' : 'مرحباً بك،' }}
    </div>

    <p style="margin:0 0 16px;color:#33415C;font-size:14.5px;line-height:1.75;">
        استخدم رمز التحقق الآمن التالي لإتمام عملية <b>{{ $purpose }}</b> في منصة {{ config('app.name', 'سلاسل بابل') }}:
    </p>

    {{-- بطاقة الرمز الرقمي --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 24px;">
        <tr>
            <td align="center">
                <div style="display:inline-block;background:linear-gradient(135deg, #EBF6FC 0%, #F0F9FF 100%);border:2px dashed #93CBE9;border-radius:16px;padding:18px 36px;text-align:center;">
                    <div style="font-size:11px;font-weight:700;color:#0E5C9C;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">رمز التحقق لمرة واحدة (OTP)</div>
                    <div style="font-family:'Courier New',Courier,monospace,sans-serif;font-size:36px;font-weight:800;letter-spacing:12px;color:#0A2A55;direction:ltr;text-align:center;padding-left:12px;">
                        {{ $code }}
                    </div>
                </div>
            </td>
        </tr>
    </table>

    {{-- إرشادات الأمان --}}
    @include('emails.partials.alert', [
        'type' => 'warning',
        'title' => '⚠️ تعليمات الأمان',
        'slot' => 'هذا الرمز صالح للاستخدام لمدة '.$ttlMinutes.' دقائق فقط. لا تشارك هذا الرمز مع أي شخص، بما في ذلك موظفي خدمة العملاء. إذا لم تطلب هذا الرمز، يُرجى تجاهل هذه الرسالة.'
    ])
@endsection
