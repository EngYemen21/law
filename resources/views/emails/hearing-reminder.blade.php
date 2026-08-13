@extends('emails.layout', [
    'preview' => 'تذكير بجلسة قضيتك ('.$remainingLabel.') — '.($hearing->legalCase?->number ?? ''),
    'subtitle' => 'تذكير بالجلسات القضائية'
])

@section('content')
    @php
        $case = $hearing->legalCase;
        $when = $hearing->starts_at?->locale('ar')->translatedFormat('l d F Y · h:i A') ?: trim((string) $hearing->day.' · '.$hearing->time);
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        نذكّركم بموعد الجلسة القضائية القادمة
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        نحيطكم علماً باقتراب موعد الجلسة في القضية رقم <b>{{ $case?->number ?? '—' }}</b> بعد <b>{{ $remainingLabel }}</b>.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات الجلسة القضائية',
        'rows' => [
            'رقم القضية' => $case?->number ?? '—',
            'عنوان الجلسة' => $hearing->title,
            'موعد الجلسة' => $when,
            'المحكمة / الدائرة' => $hearing->court,
        ]
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/cases',
        'label' => 'الاطلاع على مستندات وتفاصيل الجلسة',
        'variant' => 'primary'
    ])
@endsection
