@extends('emails.layout', [
    'preview' => 'تذكرة بلا محامٍ متخصّص — '.$ticket->number,
    'subtitle' => 'تصعيد إسناد'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        تذكرة تحتاج إسناد محامٍ
    </div>
    <p style="margin:0 0 16px;color:#33415C;">
        لا يوجد محامٍ متخصّص في قسم <b>{{ $department }}</b> ضمن التوزيع التلقائي، فأُسنِدت التذكرة
        <b>{{ $ticket->number }}</b> إلى الإدارة العليا مؤقّتاً بانتظار إسنادها لمحامٍ مناسب.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات التذكرة',
        'rows' => array_filter([
            'رقم التذكرة' => $ticket->number,
            'العميل' => $ticket->user?->name,
            'نوع الطلب' => $ticket->type,
            'القسم المطلوب' => $department,
            'الأولوية' => $ticket->priority ?: null,
        ])
    ])

    @include('emails.partials.alert', [
        'type' => 'warning',
        'title' => '⚠️ إجراء مطلوب',
        'slot' => 'التذكرة لن تتقدّم في مسارها حتى يُسنَد لها محامٍ. أعِد إسنادها من شاشة توزيع التذاكر، أو فعّل التوزيع التلقائي لمحامٍ في هذا القسم.'
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.$portalPath,
        'label' => 'فتح شاشة توزيع التذاكر',
        'variant' => 'primary'
    ])
@endsection
