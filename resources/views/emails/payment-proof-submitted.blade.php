@extends('emails.layout', [
    'preview' => 'إثبات تحويل بانتظار المراجعة — '.$invoice->number,
    'subtitle' => 'المالية والفواتير'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <p style="margin:0 0 16px;color:#33415C;">
        رفع العميل <b>{{ $invoice->user?->name ?? '—' }}</b> إثبات تحويلٍ يدويّ للفاتورة <b>{{ $invoice->number }}</b>، وهي الآن بانتظار مراجعتكم.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات الفاتورة',
        'rows' => [
            'رقم الفاتورة' => $invoice->number,
            'البيان' => $invoice->description ?: '—',
            'المبلغ' => number_format((int) $invoice->amount).' ر.س',
            'وقت الرفع' => $invoice->proof_uploaded_at?->format('Y-m-d H:i') ?? '—',
        ]
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/admin/finance',
        'label' => 'مراجعة الإثبات في المالية',
    ])
@endsection
