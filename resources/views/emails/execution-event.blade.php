@extends('emails.layout', [
    'preview' => $subject ?? 'تحديث على طلب التنفيذ — '.$execution->number,
    'subtitle' => 'شؤون التنفيذ والأحكام'
])

@section('content')
    @php
        $total = (int) $execution->fee + (int) $execution->vat;
        // رسائل المكتب تخاطب الطاقم لا صاحب الطلب — «طلبكم» في بريدٍ للإدارة كان سيقرأ خطأً
        $forOffice = ($audience ?? 'client') !== 'client';
        $remaining = max(0, (int) $execution->amount - (int) $execution->collected);
    @endphp

    <div style="font-size:17px;font-weight:800;color:#0A2A55;margin-bottom:10px;">
        {{ $intro }}
    </div>

    <p style="margin:0 0 16px;color:#33415C;">
        @if($forOffice)
            تفاصيل ملفّ التنفيذ أدناه، ومتابعته من لوحتكم في {{ \App\Support\SettingsRegistry::str('office_name') }}:
        @else
            نحيطكم علماً بآخر التحديثات والإجراءات المتخذة على طلب التنفيذ الخاص بكم لدى {{ \App\Support\SettingsRegistry::str('office_name') }}:
        @endif
    </p>

    @include('emails.partials.card', [
        'title' => 'بيانات ملف التنفيذ',
        'rows' => [
            'رقم الطلب' => $execution->number,
            'موضوع التنفيذ' => $execution->subject,
            'طالب التنفيذ' => $forOffice ? ($execution->user?->name ?? null) : null,
            'الرقم المرجعيّ الداخليّ لملفّ التنفيذ' => $execution->exec_no,
            'رقم الطلب في ناجز' => $execution->najiz_request_no,
            'محكمة التنفيذ' => $execution->court,
            'أتعاب التنفيذ' => $execution->fee > 0 ? $total.' ر.س (شامل ضريبة القيمة المضافة)' : null,
            {{-- نموذج «نسبة من المحصّل»: لا مبلغ ثابت يُذكر، فالنسبة هي الأتعاب --}}
            'نموذج الأتعاب' => $execution->feeMode() === 'percent'
                ? \App\Support\ExecFee::pctLabel((float) $execution->collection_fee_pct).'% من كلّ مبلغ يُحصَّل'
                : null,
            'المحصَّل' => $event === 'collection' ? number_format((int) $execution->collected).' ر.س — المتبقّي '.number_format($remaining).' ر.س' : null,
            'مهلة الوفاء' => $event === 'payDueOverdue' ? optional($execution->pay_due_at)->locale('ar')->translatedFormat('j F Y') : null,
            {{-- الفاتورة المعنيّة بالتذكير: رقمها ومبلغها واستحقاقها — بلا هذا تتطابق رسائل الدفعات الثلاث --}}
            'الفاتورة المستحقّة' => ($invoice ?? null) ? $invoice->number.' — '.number_format((int) $invoice->amount).' ر.س' : null,
            'تاريخ الاستحقاق' => ($invoice ?? null)?->due_at ? $invoice->due_at->locale('ar')->translatedFormat('j F Y') : null,
            'حالة الإجراء' => $subject,
        ]
    ])

    @if($event === 'feeSet' || $event === 'feeApproved' || $event === 'paymentReminder')
        @include('emails.partials.alert', [
            'type' => 'warning',
            'title' => '💳 سداد الأتعاب',
            {{-- التذكير يخاطب فاتورةً بعينها تجاوزت استحقاقها، لا «الفاتورة المعتمدة» مطلقاً:
                 الملفّ قد يكون في المرحلة 8 وإجراءاته جارية، فوعدُ «البدء الفوري» فيه غير صادق --}}
            'slot' => $event === 'paymentReminder'
                ? 'تجاوزت الفاتورة المذكورة أعلاه تاريخ استحقاقها ولمّا تُسدَّد. يمكنكم سدادها من صفحة الفواتير في لوحتكم.'
                : 'يُرجى سداد الفاتورة المعتمدة للبدء الفوري في إجراءات التنفيذ ومتابعة السندات لدى محكمة التنفيذ.'
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
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
            'url' => $panelUrl,
            'label' => 'متابعة ملف التنفيذ',
            'variant' => 'success'
        ])
    @elseif($event === 'fileOpened')
        @include('emails.partials.alert', [
            'type' => 'success',
            'title' => '✅ فُتح ملف التنفيذ',
            'slot' => 'قُبل العرض وفُتح ملف التنفيذ — لا مبلغ مقدَّم عليكم. تُصدَر فاتورة أتعاب بالنسبة المتّفق عليها مع كل مبلغ يُحصَّل، ويتولى الفريق المختص متابعة الإجراءات التنفيذية.'
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => 'متابعة ملف التنفيذ',
            'variant' => 'success'
        ])
    @elseif($event === 'firstInstallmentPaid')
        @include('emails.partials.alert', [
            'type' => 'success',
            'title' => '✅ سُدِّدت الدفعة الأولى وفُتح الملف',
            'slot' => 'تم استلام الدفعة الأولى من أتعاب التنفيذ وفُتح ملف التنفيذ. تبقى الدفعتان التاليتان باستحقاقيهما، وتجدونهما في صفحة الفواتير.'
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => 'متابعة ملف التنفيذ والدفعات',
            'variant' => 'success'
        ])
    @elseif($event === 'feeInvoice')
        @include('emails.partials.alert', [
            'type' => 'warning',
            'title' => '💳 فاتورة أتعاب عن مبلغ محصَّل',
            'slot' => 'وفق نموذج «نسبة من المحصّل» المتّفق عليه، صدرت فاتورة أتعاب بنسبتها من المبلغ الذي حُصّل — تفاصيلها وسدادها من صفحة الفواتير في لوحتكم.'
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => 'عرض الفاتورة وسدادها',
            'variant' => 'gold'
        ])
    @elseif($event === 'feePaidInFull')
        @include('emails.partials.alert', [
            'type' => 'success',
            'title' => '✅ اكتمل سداد الأتعاب',
            'slot' => 'سُدّدت الدفعة الأخيرة من خطّة التقسيط واكتملت أتعاب التنفيذ. تتواصل إجراءات الملفّ كالمعتاد.'
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => 'متابعة ملف التنفيذ',
            'variant' => 'success'
        ])
    @elseif($event === 'payDueOverdue')
        @include('emails.partials.alert', [
            'type' => 'warning',
            'title' => '⏰ انقضت مهلة الوفاء',
            'slot' => 'انقضت المهلة الممنوحة للمنفَّذ ضدّه بعد الإبلاغ بأمر التنفيذ ولم تُسجَّل إجراءات عدم الوفاء — يلزم طلبها في منصّة ناجز وتسجيلها على الملفّ.'
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => 'فتح ملفّ التنفيذ',
            'variant' => 'gold'
        ])
    @elseif($event === 'newRequest' || $event === 'feeAwaitingApproval' || $event === 'offerRejected' || $event === 'offerInquiry')
        @include('emails.partials.alert', [
            'type' => 'warning',
            'title' => '📌 إجراء مطلوب',
            'slot' => $event === 'newRequest'
                ? 'يلزم فحص المستندات وطلب أيّ ناقص، ثمّ إحالة الطلب لقسم التنفيذ.'
                : ($event === 'feeAwaitingApproval'
                    ? 'الاعتماد قرار الإدارة — يُراجَع المبلغ ثمّ يُعتمد ليُرسَل العرض للعميل.'
                    : 'يُراجَع العرض ويُعاد تسعيره من الإدارة عند الاقتضاء.')
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => 'فتح الطلب في لوحتك',
            'variant' => 'primary'
        ])
    @elseif($event === 'rejected')
        @include('emails.partials.alert', [
            'type' => 'warning',
            'title' => 'تعذّر قبول الطلب',
            'slot' => 'يمكنكم التواصل مع المكتب عبر محادثة الطلب لمعرفة أسباب التعذّر وبدائل المعالجة.'
        ])
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => 'عرض تفاصيل الطلب',
            'variant' => 'primary'
        ])
    @else
        @include('emails.partials.button', [
            'url' => $panelUrl,
            'label' => $forOffice ? 'فتح ملفّ التنفيذ' : 'عرض تفاصيل ملف التنفيذ',
            'variant' => 'primary'
        ])
    @endif
@endsection
