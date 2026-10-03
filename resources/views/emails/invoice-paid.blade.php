@extends('emails.layout', [
    'preview' => 'تم اعتماد دفعتك — '.$invoice->number,
    'subtitle' => 'الفواتير والمدفوعات'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $invoice->user?->name ?? 'عميلنا الكريم' }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        تم اعتماد دفعتكم للفاتورة <b>{{ $invoice->number }}</b>، وسند القبض متاح للتنزيل من صفحة «فواتيري».
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات الدفعة',
        'rows' => [
            'رقم الفاتورة' => $invoice->number,
            'البيان' => $invoice->description ?: '—',
            'المبلغ' => number_format((int) $invoice->amount).' ر.س',
            'الحالة' => 'مدفوعة ✅',
        ]
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/invoices',
        'label' => 'عرض فواتيري وسند القبض',
        'variant' => 'success'
    ])
@endsection
