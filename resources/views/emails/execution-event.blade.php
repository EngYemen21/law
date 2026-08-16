@extends('emails.layout', [
    'preview' => $subject ?? 'تحديث على طلب التنفيذ — '.$execution->number,
    'subtitle' => 'شؤون التنفيذ والأحكام'
])

@section('content')
    @php
        $total = (int) $execution->fee + (int) $execution->vat;
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        {{ $intro }}
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        نحيطكم علماً بآخر التحديثات والإجراءات المتخذة على طلب التنفيذ الخاص بكم لدى النظام الإداري لمكاتب المحاماة:
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات ملف التنفيذ',
        'rows' => [
            'رقم الطلب' => $execution->number,
            'موضوع التنفيذ' => $execution->subject,
            'رقم ملف التنفيذ' => $execution->exec_no,
            'أتعاب التنفيذ' => $execution->fee > 0 ? $total.' ر.س (شامل ضريبة القيمة المضافة)' : null,
            'حالة الإجراء' => $subject,
        ]
    ])

    @if($event === 'feeSet' || $event === 'feeApproved' || $event === 'paymentReminder')
        @include('emails.partials.alert', [
            'type' => 'warning',
            'title' => '💳 سداد الأتعاب',
            'slot' => 'يُرجى سداد الفاتورة المعتمدة للبدء الفوري في إجراءات التنفيذ ومتابعة السندات لدى محكمة التنفيذ.'
        ])
        @include('emails.partials.button', [
            'url' => $baseUrl.'/execs',
            'label' => 'سداد أتعاب التنفيذ ومتابعة الطلب',
            'variant' => 'gold'
        ])
    @elseif($event === 'paid')
        @include('emails.partials.alert', [
            'type' => 'success',
            'title' => '✅ تم فتح الملف بنجاح',
            'slot' => 'تم استلام سداد الأتعاب بنجاح وتفعيل ملف التنفيذ. يتولى الفريق المختص متابعة كافة الإجراءات التنفيذية.'
        ])
        @include('emails.partials.button', [
            'url' => $baseUrl.'/execs',
            'label' => 'متابعة ملف التنفيذ',
            'variant' => 'success'
        ])
    @else
        @include('emails.partials.button', [
            'url' => $baseUrl.'/execs',
            'label' => 'عرض تفاصيل ملف التنفيذ',
            'variant' => 'primary'
        ])
    @endif
@endsection
