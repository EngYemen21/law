@extends('emails.layout', [
    'preview' => 'تم استلام دفعتك بنجاح للاستشارة '.$consult->ref,
    'subtitle' => 'الفواتير والمدفوعات'
])

@section('content')
    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $consult->user?->name ?? 'عميلنا الكريم' }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        تم استلام وتأكيد سداد فاتورة استشارتك القانونية بنجاح عبر بوابة الدفع الإلكتروني.
    </p>

    @include('emails.partials.card', [
        'title' => 'تفاصيل الفاتورة والسداد',
        'rows' => [
            'رقم الاستشارة' => $consult->ref,
            'موضوع الاستشارة' => $consult->subject,
            'المبلغ المسدد' => $consult->total.' ر.س (شامل ضريبة القيمة المضافة)',
            'حالة الدفع' => 'مسددة بنجاح ✅',
            'نوع الاستشارة' => $consult->channel,
        ]
    ])

    @include('emails.partials.alert', [
        'type' => 'success',
        'title' => '🗓️ الخطوة التالية المطلوبة',
        'slot' => 'يمكنك الآن اختيار وتحديد موعد جلستك القانونية المناسب مع المستشار من خلال حسابك.'
    ])

    @include('emails.partials.button', [
        'url' => rtrim((string) config('app.url'), '/').'/myconsults',
        'label' => 'اختيار موعد الجلسة الآن',
        'variant' => 'success'
    ])
@endsection
