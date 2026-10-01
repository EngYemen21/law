@extends('emails.layout', [
    'preview' => 'اعتُمد ملخّص ملفك وصدر الرأي القانونيّ المبدئيّ — '.$ticket->number,
    'subtitle' => 'دراسة الملفات والاستشارات'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $ticket->user?->name ?? 'عميلنا الكريم' }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        يسرنا إبلاغك بأن المستشار القانوني قد أتمّ دراسة ملخص ملفك في التذكرة رقم <b>{{ $ticket->number }}</b> وأصدر الرأي القانوني المبدئي.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات التذكرة المعتمدة',
        'rows' => [
            'رقم التذكرة' => $ticket->number,
            'موضوع الملف' => $ticket->subject,
            'حالة الدراسة' => 'معتمدة ومكتملة ✅',
        ]
    ])

    @if(filled($opinion))
        <div style="margin:22px 0;background-color:#F8FAFC;border:1px solid #E2E8F0;border-right:4px solid #0E5C9C;border-radius:12px;padding:18px 20px;">
            <div style="font-weight:800;color:#0A2A55;font-size:14px;margin-bottom:8px;">📜 الرأي القانوني المبدئي للمستشار:</div>
            @if(filled($opinionHtml ?? null))
                {{-- منقّى في `SummaryApprovedMail` (`RichHtml::clean`) — بتنسيق المستشار كما اعتُمد --}}
                <div style="color:#2C4258;font-size:13.5px;line-height:1.85;">{!! $opinionHtml !!}</div>
            @else
                <div style="color:#2C4258;font-size:13.5px;line-height:1.85;white-space:pre-line;">{{ $opinion }}</div>
            @endif
        </div>
    @endif

    @include('emails.partials.alert', [
        'type' => 'info',
        'title' => '💡 لمناقشة التفاصيل والإجراءات',
        'slot' => 'لإبداء الرأي القانوني الشامل ومناقشة تفاصيل الدعوى والخيارات النظامية، ندعوك لحجز جلسة استشارة مع المستشار.'
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/tickets/'.$ticket->number,
        'label' => 'فتح التذكرة وحجز استشارة',
        'variant' => 'primary'
    ])
@endsection
