@extends('emails.layout', ['preview' => 'انتهى الاجتماع: '.$title, 'subtitle' => 'الاجتماعات'])

@section('content')
    <p style="margin:0 0 14px;font-size:16px;font-weight:800;color:#0A2A55;">مرحباً {{ $recipientName }}،</p>
    <p style="margin:0 0 6px;">انتهى اجتماع <b>{{ $title }}</b>. شكراً لحضورك.</p>

    @if ($summary)
        <div style="margin:16px 0;background:#F6F8FA;border:1px solid #EDF2F6;border-radius:10px;padding:14px 16px;">
            <div style="font-weight:800;color:#0A2A55;margin-bottom:6px;">ملخّص الاجتماع</div>
            <div style="color:#33415c;white-space:pre-line;">{{ $summary }}</div>
        </div>
    @endif

    @if ($minutesUrl)
        @include('emails.partials.button', ['url' => $minutesUrl, 'label' => 'عرض محضر الاجتماع'])
    @endif
@endsection
