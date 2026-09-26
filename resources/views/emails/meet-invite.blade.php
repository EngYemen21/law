@extends('emails.layout', [
    'preview' => 'دعوة اجتماع رسمي من '.\App\Support\SettingsRegistry::str('office_name').' — '.$req->ref,
    'subtitle' => 'دعوات الاجتماعات'
])

@section('content')
    @php
        $when = trim(($req->day ?? '').(($req->time ?? '') !== '' ? ' · '.$req->time : ''));
        // بريدُ العميل وحده (MeetingController/MeetRequestController) — «محمد. ب» لا الاسم الكامل
        $lawyer = App\Support\LawyerName::forClient($req->assignedLawyer, $req->assignedLawyer?->name, '—');
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        تحية طيبة وبعد،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        يسر <b>{{ \App\Support\SettingsRegistry::str('office_name') }}</b> دعوتك لحضور اجتماع رسمي لمناقشة التفاصيل القانونية وفق البيانات الموضحة أدناه:
    </p>

    {{-- لا «المدة المتوقعة»: الاجتماع ينتهي حين يُنهى لا بمدّةٍ ثابتة (قرار المالك 2026-09-26) --}}
    @include('emails.partials.card', [
        'title' => 'بيانات دعوة الاجتماع',
        'rows' => [
            'رقم الدعوة' => $req->ref,
            'نوع الاجتماع' => $req->type,
            'موضوع الاجتماع / الخدمة' => $req->service,
            'الموعد المقترح' => $when,
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
