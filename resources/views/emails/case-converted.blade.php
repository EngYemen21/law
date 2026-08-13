@extends('emails.layout', [
    'preview' => 'تم تحويل تذكرتك إلى قضية قانونية — '.$case->number,
    'subtitle' => 'شؤون القضايا والدعاوى'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $case->user?->name ?? 'عميلنا الكريم' }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        بناءً على التوصية القانونية ودراسة الملف، تم بنجاح تحويل تذكرتكم رقم <b>{{ $ticketNumber }}</b> إلى قضية قانونية رسمية رقم <b>{{ $case->number }}</b>.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات القضية المحولة',
        'rows' => [
            'رقم التذكرة السابقة' => $ticketNumber,
            'رقم القضية الجديد' => $case->number,
            'نوع القضية / التصنيف' => $case->type,
            'حالة الملف' => 'تم التحويل بانتظار تحديد الأتعاب ⚖️',
        ]
    ])

    @include('emails.partials.alert', [
        'type' => 'info',
        'title' => '📌 الإجراء القادم',
        'slot' => 'تتولى الإدارة العليا حالياً دراسة تقدير الأتعاب وإصدار الفاتورة الرسمية لتتمكنوا من سدادها وتفعيل إجراءات الترافع.'
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/cases',
        'label' => 'متابعة ملف القضية في حسابك',
        'variant' => 'primary'
    ])
@endsection
