@extends('emails.layout', [
    'preview' => 'تذكير باجتماع: '.$title.($remaining ? ' (خلال '.$remaining.')' : ''),
    'subtitle' => 'تذكير بالاجتماعات'
])

@section('content')
    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $recipientName }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        نود تذكيرك بالاجتماع القادم <b>{{ $title }}</b>@if ($remaining) خلال <b>{{ $remaining }}</b>@endif.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات الاجتماع',
        'rows' => [
            'موضوع الاجتماع' => $title,
            'الموعد والتوقيت' => $when,
        ]
    ])

    @if ($joinUrl)
        @include('emails.partials.button', [
            'url' => $joinUrl,
            'label' => 'الانضمام للاجتماع الآن',
            'variant' => 'primary'
        ])
    @endif
@endsection
