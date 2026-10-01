@extends('emails.layout', [
    'preview' => $subject ?? 'تحديث على موعد اجتماعك — '.$meeting->ref,
    'subtitle' => 'تحديثات الاجتماعات'
])

@section('content')
    @php
        $when = $meeting->starts_at?->locale('ar')->translatedFormat('l d F Y · h:i A') ?: (string) $meeting->when_label;
        $cancelled = $event === 'cancelled';
        // التأجيل بلا موعد يمرّ بحدث «إعادة الجدولة» نفسه — لكن لا موعد جديد يُعلَن ولا «تواجد قبله»
        $postponed = ! $cancelled && $meeting->starts_at === null;
        $baseUrl = rtrim((string) config('app.url'), '/');
    @endphp

    <div style="font-size:17px;font-weight:800;color:{{ $cancelled ? '#C0392B' : '#0A2A55' }};margin-bottom:10px;">
        {{ $postponed ? 'أُجّل اجتماعك' : $intro }}
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        نود إحاطتكم بآخر المستجدات والتحديثات الخاصة بالاجتماع رقم <b>{{ $meeting->ref }}</b>:
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات الاجتماع',
        'rows' => [
            'رقم المرجع' => $meeting->ref,
            'عنوان الاجتماع' => $meeting->title,
            'الحالة الحالية' => $cancelled ? 'ملغي ❌' : ($postponed ? 'مؤجَّل ⏸️' : 'مُعاد جدولته 🔄'),
            'الموعد الجديد' => $cancelled || $postponed ? null : $when,
            // من يُبلَّغ بتغيّر موعده يعرف لماذا تغيّر — الصفّ يسقط وحده حين لا سبب
            'السبب' => $reason ?? null,
        ]
    ])

    @if($cancelled)
        @include('emails.partials.alert', [
            'type' => 'danger',
            'title' => '⚠️ إشعار إلغاء',
            'slot' => 'تم إلغاء هذا الاجتماع. في حال رغبتكم في حجز موعد جديد، يُرجى الدخول إلى حسابكم واختيار موعد بديل.'
        ])
    @elseif($postponed)
        @include('emails.partials.alert', [
            'type' => 'info',
            'title' => '⏸️ الموعد يُحدَّد لاحقاً',
            'slot' => 'أُجّل هذا الاجتماع دون موعدٍ بعد — يصلكم الموعد الجديد بإشعارٍ وبريد حين يُحدَّد. لا تحضروا في الموعد السابق.'
        ])
    @else
        @include('emails.partials.alert', [
            'type' => 'info',
            'title' => '🗓️ الموعد المعدّل',
            'slot' => 'تم اعتماد الموعد الجديد للاجتماع، يُرجى التواجد قبل الموعد بـ'.\App\Support\SessionWindow::joinOpensLabel().'.'
        ])
    @endif

    @include('emails.partials.button', [
        'url' => $baseUrl,
        'label' => 'فتح لوحة التحكم',
        'variant' => $cancelled ? 'outline' : 'primary'
    ])
@endsection
