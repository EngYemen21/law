@extends('emails.layout', [
    'preview' => 'انتهى الاجتماع: '.$title.' — شكراً لحضورك',
    'subtitle' => 'محاضر الاجتماعات'
])

@section('content')
    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $recipientName }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        تم اختتام اجتماع <b>{{ $title }}</b> بنجاح. نشكرك على حضورك وتفاعلك المثمر.
    </p>

    @if ($summary)
        <div style="margin:20px 0;background-color:#F8FAFC;border:1px solid #E2E8F0;border-right:4px solid #11A0C8;border-radius:12px;padding:16px 20px;">
            <div style="font-weight:800;color:#0A2A55;font-size:14px;margin-bottom:8px;">📋 ملخّص وتوصيات الاجتماع:</div>
            <div style="color:#33415C;font-size:13.5px;line-height:1.8;white-space:pre-line;">{{ $summary }}</div>
        </div>
    @endif

    @if ($minutesUrl)
        @include('emails.partials.button', [
            'url' => $minutesUrl,
            'label' => 'عرض محضر الاجتماع الكامل',
            'variant' => 'primary'
        ])
    @endif
@endsection
