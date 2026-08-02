@extends('emails.layout', ['preview' => 'تذكير باجتماع: '.$title, 'subtitle' => 'الاجتماعات'])

@section('content')
    <p style="margin:0 0 14px;font-size:16px;font-weight:800;color:#0A2A55;">مرحباً {{ $recipientName }}،</p>
    <p style="margin:0 0 18px;">
        نذكّرك باجتماع <b>{{ $title }}</b>@if ($remaining) خلال <b>{{ $remaining }}</b>@endif.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E1E8EE;border-radius:10px;overflow:hidden;">
        <tr>
            <td style="padding:10px 14px;background:#F6F8FA;font-weight:700;width:120px;color:#33415c;">الموعد</td>
            <td style="padding:10px 14px;">{{ $when }}</td>
        </tr>
    </table>

    @if ($joinUrl)
        @include('emails.partials.button', ['url' => $joinUrl, 'label' => 'الانضمام للاجتماع'])
    @endif
@endsection
