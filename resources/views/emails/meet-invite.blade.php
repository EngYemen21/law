@extends('emails.layout', [
    'preview' => 'دعوة اجتماع رسمي من النظام الإداري لمكاتب المحاماة — '.$req->ref,
    'subtitle' => 'دعوات الاجتماعات'
])

@section('content')
    @php
        $when = trim(($req->day ?? '').(($req->time ?? '') !== '' ? ' · '.$req->time : ''));
        $lawyer = $req->assignedLawyer?->name;
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        تحية طيبة وبعد،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        يسر <b>النظام الإداري لمكاتب المحاماة</b> دعوتك لحضور اجتماع رسمي لمناقشة التفاصيل القانونية وفق البيانات الموضحة أدناه:
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات دعوة الاجتماع',
        'rows' => [
            'رقم الدعوة' => $req->ref,
            'نوع الاجتماع' => $req->type,
            'موضوع الاجتماع / الخدمة' => $req->service,
            'الموعد المقترح' => $when,
            'المدة المتوقعة' => $req->duration_min ? $req->duration_min.' دقيقة' : null,
            'المحامي المسؤول' => $lawyer,
        ]
    ])

    {{-- تأكيد العميل للحضور أُلغي: الدعوة تصل معتمدة من الإدارة ومؤكَّدة — لا إجراء مطلوباً منه --}}
    @include('emails.partials.alert', [
        'type' => 'info',
        'title' => '📌 موعد مؤكد',
        'slot' => 'اجتماعك مجدول ومؤكد — لا يلزمك أي إجراء. ستجد رابط الدخول في منصتك، ويُفعَّل قبل الموعد بدقائق.'
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/meetings',
        'label' => 'عرض الاجتماع في المنصة',
        'variant' => 'primary'
    ])
@endsection
