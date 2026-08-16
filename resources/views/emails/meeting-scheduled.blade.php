@extends('emails.layout', [
    'preview' => 'موعد اجتماع جديد: '.$title,
    'subtitle' => 'الاجتماعات والمواعيد'
])

@section('content')
    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $recipientName }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        تم تحديد وتأكيد موعد اجتماع رسمي لك وفق التفاصيل والمعلومات الموضحة أدناه:
    </p>

    @include('emails.partials.card', [
        'title' => 'تفاصيل موعد الاجتماع',
        'rows' => [
            'موضوع الاجتماع' => $title,
            'الموعد والتوقيت' => $when,
            'مكان الانعقاد / الرابط' => $location ?: 'عبر المنصة الإلكترونية',
        ]
    ])

    @if ($note)
        @include('emails.partials.alert', [
            'type' => 'info',
            'title' => '📝 تعليمات الدخول',
            'slot' => $note
        ])
    @else
        @include('emails.partials.alert', [
            'type' => 'info',
            'title' => '🔒 خصوصية وأمان الجلسة',
            'slot' => 'لأسباب الأمان والسرية، تنعقد جميع الجلسات داخل المنصة وتتطلب تسجيل الدخول المسبق.'
        ])
    @endif

    @if ($joinUrl)
        @include('emails.partials.button', [
            'url' => $joinUrl,
            'label' => 'الدخول للمنصة والاطلاع على تفاصيل الاجتماع',
            'variant' => 'primary'
        ])
    @endif
@endsection
