@extends('emails.layout', ['preview' => 'موعد اجتماع: '.$title, 'subtitle' => 'الاجتماعات'])

@section('content')
    <p style="margin:0 0 14px;font-size:16px;font-weight:800;color:#0A2A55;">مرحباً {{ $recipientName }}،</p>
    <p style="margin:0 0 18px;">تم تحديد موعد اجتماع لك بالتفاصيل التالية:</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E1E8EE;border-radius:10px;overflow:hidden;">
        <tr>
            <td style="padding:10px 14px;background:#F6F8FA;font-weight:700;width:120px;color:#33415c;">الموضوع</td>
            <td style="padding:10px 14px;">{{ $title }}</td>
        </tr>
        <tr>
            <td style="padding:10px 14px;background:#F6F8FA;font-weight:700;color:#33415c;border-top:1px solid #EDF2F6;">الموعد</td>
            <td style="padding:10px 14px;border-top:1px solid #EDF2F6;">{{ $when }}</td>
        </tr>
        @if ($location)
            <tr>
                <td style="padding:10px 14px;background:#F6F8FA;font-weight:700;color:#33415c;border-top:1px solid #EDF2F6;">المكان</td>
                <td style="padding:10px 14px;border-top:1px solid #EDF2F6;">{{ $location }}</td>
            </tr>
        @endif
    </table>

    @if ($note)
        <p style="margin:16px 0 0;color:#607689;font-size:13px;">{{ $note }}</p>
    @endif

    @if ($joinUrl)
        @include('emails.partials.button', ['url' => $joinUrl, 'label' => 'الانضمام للاجتماع'])
    @endif
@endsection
