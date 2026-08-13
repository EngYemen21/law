@extends('emails.layout', [
    'preview' => $forLawyer
        ? 'رابط جلسة الاستشارة جاهز برقم — '.$consult->ref
        : 'رابط جلسة استشارتك جاهز الآن — '.$consult->ref,
    'subtitle' => 'الجلسات المرئية'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
        $portal = $forLawyer ? $baseUrl.'/lawyer/consults' : $baseUrl.'/myconsults';
    @endphp

    @if($forLawyer)
        <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
            مرحباً سعادة المستشار/ {{ $consult->lawyer }}،
        </div>
        <p style="margin:0 0 16px;color:#33415C;">
            حان موعد استشارتك المرئية رقم <b>{{ $consult->ref }}</b> مع العميل (<b>{{ $consult->user?->name ?? 'العميل' }}</b>). زر <b>الدخول إلى الجلسة المرئية</b> مفعّل الآن في لوحتك.
        </p>

        @include('emails.partials.card', [
            'title' => 'بيانات الجلسة المرئية',
            'rows' => [
                'رقم الاستشارة' => $consult->ref,
                'اسم العميل' => $consult->user?->name ?? 'العميل',
                'موضوع الاستشارة' => $consult->subject,
                'حالة الجلسة' => 'مباشرة وجاهزة للدخول 🟢',
            ]
        ])

        @include('emails.partials.button', [
            'url' => $portal,
            'label' => 'الدخول إلى الجلسة المرئية الآن',
            'variant' => 'success'
        ])
    @else
        <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
            مرحباً {{ $consult->user?->name ?? 'عميلنا الكريم' }}،
        </div>
        <p style="margin:0 0 16px;color:#33415C;">
            حان وقت بدء جلستك القانونية المرئية رقم <b>{{ $consult->ref }}</b> مع المستشار القانوني. زر <b>الدخول إلى الجلسة</b> مفعّل ومتاح لك الآن.
        </p>

        @include('emails.partials.card', [
            'title' => 'بيانات الجلسة المرئية',
            'rows' => [
                'رقم الاستشارة' => $consult->ref,
                'المستشار القانوني' => $consult->lawyer,
                'موضوع الاستشارة' => $consult->subject,
                'الموعد' => $consult->when_label,
            ]
        ])

        @include('emails.partials.button', [
            'url' => $portal,
            'label' => 'الانضمام إلى الجلسة المرئية',
            'variant' => 'success'
        ])
    @endif
@endsection
