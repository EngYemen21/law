@extends('emails.layout', [
    'preview' => 'تذكير بموعد استشارتك ('.$remainingLabel.') — '.$consult->ref,
    'subtitle' => 'تذكير بالموعد'
])

@section('content')
    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $consult->user?->name ?? 'عميلنا الكريم' }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        نود تذكيرك بأن موعد استشارتك القانونية رقم <b>{{ $consult->ref }}</b> قد اقترب (خلال <b>{{ $remainingLabel }}</b>).
    </p>

    @include('emails.partials.card', [
        'title' => 'تفاصيل موعد الاستشارة',
        'rows' => [
            'رقم الاستشارة' => $consult->ref,
            'موضوع الاستشارة' => $consult->subject,
            'الموعد المحدد' => $consult->when_label,
            'قناة الجلسة' => $consult->channel,
            'المستشار القانوني' => $consult->lawyer,
        ]
    ])

    @if($consult->channel === 'مرئية')
        @include('emails.partials.alert', [
            'type' => 'info',
            'title' => '📹 تنبيه الجلسة المرئية',
            'slot' => 'جلستك مرئية عبر المنصة — سيصلك رابط الدخول المباشر قبل الموعد بـ 5 دقائق، وسيتم تفعيل زر الدخول داخل حسابك.'
        ])
    @endif

    @include('emails.partials.button', [
        'url' => rtrim((string) config('app.url'), '/').'/myconsults',
        'label' => 'عرض الاستشارة وتفاصيلها'
    ])
@endsection
