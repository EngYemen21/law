@extends('emails.layout', ['preview' => 'رمزك: '.$code, 'subtitle' => 'التحقّق الآمن'])

@section('content')
    <p style="margin:0 0 14px;font-size:16px;font-weight:800;color:#0A2A55;">
        {{ $name ? 'مرحباً '.$name.'،' : 'مرحباً،' }}
    </p>

    <p style="margin:0 0 18px;">
        استخدم الرمز التالي لـ<b>{{ $purpose }}</b>:
    </p>

    <div style="text-align:center;margin:22px 0;">
        <div style="display:inline-block;background:#EAF6FA;border:1px solid #C5E7F1;border-radius:12px;padding:14px 28px;font-size:30px;font-weight:800;letter-spacing:10px;color:#0A2A55;direction:ltr;">
            {{ $code }}
        </div>
    </div>

    <p style="margin:0 0 6px;color:#607689;font-size:13px;">
        الرمز صالح لمدّة {{ $ttlMinutes }} دقائق. لا تُشاركه مع أحد.
    </p>
    <p style="margin:0;color:#90A2B2;font-size:12px;">
        إن لم تطلب هذا الرمز، تجاهل هذه الرسالة.
    </p>
@endsection
