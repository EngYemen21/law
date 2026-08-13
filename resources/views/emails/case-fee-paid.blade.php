@extends('emails.layout', [
    'preview' => 'تم استلام أتعاب قضيتك وتفعيلها بنجاح — '.$case->number,
    'subtitle' => 'شؤون القضايا والأتعاب'
])

@section('content')
    @php
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        مرحباً {{ $case->user?->name ?? 'عميلنا الكريم' }}،
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        تم تأكيد استلام سداد أتعاب قضيتكم رقم <b>{{ $case->number }}</b> بنجاح، وتم <b>تفعيل ملف القضية</b> رسمياً في النظام.
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات القضية المفعلة',
        'rows' => [
            'رقم القضية' => $case->number,
            'نوع القضية' => $case->type,
            'حالة الملف' => 'مفعّل وقيد المتابعة القانونية ✅',
            'حالة السداد' => 'مسدد بالكامل 💳',
        ]
    ])

    @include('emails.partials.alert', [
        'type' => 'success',
        'title' => '⚖️ سير العمل القانوني',
        'slot' => 'يعمل الفريق القانوني ومستشارو القضية حالياً على إعداد خطة العمل ومسودات اللوائح والمذكرات، وستتم موافاتكم بكافة المستجدات أولاً بأول.'
    ])

    @include('emails.partials.button', [
        'url' => $baseUrl.'/cases',
        'label' => 'متابعة مستجدات القضية',
        'variant' => 'success'
    ])
@endsection
