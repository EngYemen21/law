@extends('emails.layout', [
    'preview' => 'أُلغي موعد استشارتك '.$consult->ref.' — يُحدَّد موعدٌ جديد',
    'subtitle' => 'تحديثات الاستشارات'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        أُعيدت جدولة استشارتك
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        أُلغي موعد جلسة الاستشارة رقم <b>{{ $consult->ref }}</b>، وسيحدّد المكتب موعداً جديداً مع المستشار المختص،
        ويصلك إشعارٌ وبريد به فور اعتماده. <b>لا تحضر في الموعد السابق.</b>
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات الاستشارة',
        'rows' => [
            'رقم المرجع' => $consult->ref,
            'الموضوع' => $consult->subject,
            'الموعد الملغى' => $oldWhen,
            'السبب' => $reason,
            'الحالة الحالية' => 'بانتظار تحديد موعد جديد',
        ]
    ])

    @include('emails.partials.alert', [
        'type' => 'info',
        'title' => 'رسوم الاستشارة',
        'slot' => 'سدادك محفوظ — الموعد الجديد لا يتطلّب أيّ دفعٍ إضافيّ.'
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/myconsults',
        'label' => 'عرض استشاراتي',
        'variant' => 'primary'
    ])
@endsection
