@extends('emails.layout', [
    'preview' => 'صدرت فاتورة أتعاب قضيتك رقم '.$case->number,
    'subtitle' => 'شؤون القضايا والأتعاب'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $case->user?->name ?? 'عميلنا الكريم' }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        نحيطكم علماً بأن الإدارة العليا قد حدّدت واعتمدت أتعاب قضيتكم رقم <b>{{ $case->number }}</b> وصدرت الفاتورة الخاصة بها.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات فاتورة أتعاب القضية',
        'rows' => [
            'رقم القضية' => $case->number,
            'نوع القضية / التصنيف' => $case->type,
            'مبلغ الأتعاب المعتمد' => $total.' ر.س (شامل ضريبة القيمة المضافة)',
            'حالة الفاتورة' => 'بانتظار السداد لتفعيل ملف القضية ⏳',
        ]
    ])

    @include('emails.partials.alert', [
        'type' => 'warning',
        'title' => '⚖️ تفعيل القضية والبدء في الإجراءات',
        'slot' => 'يُرجى سداد الفاتورة المعتمدة لتفعيل القضية في النظام والبدء الفوري بإعداد صحيفة الدعوى واللوائح القانونية.'
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/cases',
        'label' => 'سداد الأتعاب وتفعيل القضية',
        'variant' => 'gold'
    ])
@endsection
