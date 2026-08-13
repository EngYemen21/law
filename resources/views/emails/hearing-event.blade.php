@extends('emails.layout', [
    'preview' => $subject ?? 'تحديث على جلسة قضيتك — '.($hearing->legalCase?->number ?? ''),
    'subtitle' => 'الجلسات القضائية'
])

@section('content')
    @php
        $case = $hearing->legalCase;
        $when = $hearing->starts_at?->locale('ar')->translatedFormat('l d F Y · h:i A') ?: trim((string) $hearing->day.' · '.$hearing->time);
        $cancelled = $event === 'cancelled';
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:{{ $cancelled ? '#C0392B' : '#0A2A55' }};margin-bottom:10px;">
        {{ $intro }}
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        نحيطكم علماً ببيانات الجلسة القضائية الخاصة بالقضية رقم <b>{{ $case?->number ?? '—' }}</b>:
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات الجلسة القضائية',
        'rows' => [
            'رقم القضية' => $case?->number ?? '—',
            'عنوان الجلسة' => $hearing->title,
            'الموعد' => $when,
            'المحكمة / الدائرة' => $hearing->court,
            'حالة الجلسة' => $cancelled ? 'ملغاة ❌' : ($event === 'rescheduled' ? 'مُعاد جدولتها 🔄' : 'مجدولة رسمياً ⚖️'),
        ]
    ])

    @if($cancelled)
        @include('emails.partials.alert', [
            'type' => 'danger',
            'title' => '⚠️ إشعار إلغاء الجلسة',
            'slot' => 'تم إلغاء هذه الجلسة بناءً على المستجدات القضائية. ستتم إفادتكم بأي تحديث جديد فور صدوره.'
        ])
    @else
        @include('emails.partials.alert', [
            'type' => 'info',
            'title' => '⚖️ توجيهات مهمة',
            'slot' => 'يُرجى التأكد من تجهيز كافة المستندات المطلوبة ومتابعة التوجيهات الصادرة من المحامي المسؤول عبر المنصة.'
        ])
    @endif

    @include('emails.partials.button', [
        'url' => $baseUrl.'/cases',
        'label' => 'متابعة تفاصيل القضية',
        'variant' => $cancelled ? 'outline' : 'primary'
    ])
@endsection
