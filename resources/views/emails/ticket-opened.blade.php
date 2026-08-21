@extends('emails.layout', [
    'preview' => ($audience === 'client' ? 'تم استلام طلبك — ' : 'تذكرة جديدة من عميل — ').$ticket->number,
    'subtitle' => 'استقبال الطلبات'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    @if ($audience === 'client')
        <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
            مرحباً {{ $ticket->user?->name ?? 'عميلنا الكريم' }}،
        </div>
        <p style="margin:0 0 16px;color:#33415C;">
            تم استلام طلبكم بنجاح وفُتحت له التذكرة رقم <b>{{ $ticket->number }}</b>، وسيتولى فريقنا المختص دراسته ومتابعتكم عبر محادثة التذكرة أولاً بأول.
        </p>
    @else
        <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
            تذكرة جديدة بانتظار المتابعة
        </div>
        <p style="margin:0 0 16px;color:#33415C;">
            فتح العميل <b>{{ $ticket->user?->name ?? '—' }}</b> تذكرة جديدة رقم <b>{{ $ticket->number }}</b> — يُرجى الاطلاع والمتابعة من اللوحة.
        </p>
    @endif

    @include('emails.partials.card', [
        'title' => 'بيانات التذكرة',
        'rows' => array_filter([
            'رقم التذكرة' => $ticket->number,
            'نوع الطلب' => $ticket->type,
            'القسم' => $ticket->department ?: null,
            'الأولوية' => $ticket->priority ?: null,
        ])
    ])

    @if ($audience === 'client')
        @include('emails.partials.alert', [
            'type' => 'info',
            'title' => '📌 الخطوة القادمة',
            'slot' => 'تابع محادثة التذكرة في حسابك — سيطلب منك الفريق المستندات اللازمة لبدء الدراسة إن لزم.'
        ])
    @endif

    @include('emails.partials.button', [
        'url' => $baseUrl.$portalPath,
        'label' => $audience === 'client' ? 'متابعة تذكرتك' : 'فتح لوحة التذاكر',
        'variant' => 'primary'
    ])
@endsection
