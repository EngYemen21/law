@extends('emails.layout', [
    'preview' => $forLawyer 
        ? 'تم إسناد حجز استشارة جديد إليك — '.$consult->ref 
        : 'تم تأكيد حجز استشارتك — '.$consult->ref,
    'subtitle' => 'الاستشارات القانونية'
])

@section('content')
    @if($forLawyer)
        <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
            مرحباً سعادة المستشار/ {{ $consult->lawyer }}،
        </div>
        <p style="margin:0 0 16px;color:#33415C;">
            تم إسناد حجز استشارة قانونية جديد إليك عبر المنصة برقم <b>{{ $consult->ref }}</b>.
        </p>

        @include('emails.partials.card', [
            'title' => 'تفاصيل الاستشارة المسندة',
            'rows' => [
                'العميل' => $consult->user?->name ?? 'عميل المنصة',
                'الموضوع' => $consult->subject,
                'الموعد' => $consult->when_label,
                'القناة' => $consult->channel,
                'نوع الاستشارة' => $consult->channel,
            ]
        ])

        @include('emails.partials.button', [
            'url' => rtrim((string) config('app.url'), '/').'/lawyer/consults',
            'label' => 'عرض الاستشارة في لوحة المستشار'
        ])
    @else
        <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
            مرحباً {{ $consult->user?->name ?? 'عميلنا الكريم' }}،
        </div>
        <p style="margin:0 0 16px;color:#33415C;">
            يسرنا إبلاغك بأنه تم تأكيد حجز استشارتك القانونية رقم <b>{{ $consult->ref }}</b> بنجاح لدى {{ \App\Support\SettingsRegistry::str('office_name') }}.
        </p>

        @include('emails.partials.card', [
            'title' => 'بيانات حجز الاستشارة',
            'rows' => [
                'رقم المرجع' => $consult->ref,
                'الموضوع' => $consult->subject,
                'الموعد المحدد' => $consult->when_label,
                {{-- فرعُ العميل: «محمد. ب» لا الاسم الكامل (قرار المالك 2026-09-11) --}}
                'المستشار القانوني' => $consult->lawyerForClient(),
                'قناة الاستشارة' => $consult->channel,
            ]
        ])

        @if($consult->channel === 'مرئية')
            @include('emails.partials.alert', [
                'type' => 'info',
                'title' => '📹 تنبيه الجلسة المرئية',
                'slot' => 'جلستك مرئية عبر المنصة. سيصلك رابط الجلسة المباشر قبل الموعد بـ 5 دقائق، وسيتم تفعيل زر الدخول تلقائياً في حسابك.'
            ])
        @endif

        @include('emails.partials.button', [
            'url' => rtrim((string) config('app.url'), '/').'/myconsults',
            'label' => 'متابعة استشاراتي'
        ])
    @endif
@endsection
