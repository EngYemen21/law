<?php

namespace App\Support;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Models\Consult;

/**
 * بنية «تقرير الاستشارة القانونية — نسخة العميل».
 *
 * فُصلت عن المتحكّم لأن محتواها **يُختبَر**: المتحكّم يُصيّرها PDF مضغوطاً، والبحث
 * النصّيّ في بايتاته بلا معنى — فحارسٌ يؤكّد على مخرج `/report` يمرّ حتى لو عاد
 * الملخّص غير المعتمد إلى الوثيقة. وهذا ما وقع فعلاً في أوّل صياغةٍ للحارس.
 *
 * وما تحرسه هذه البنية: **لا ملخّص للعميل قبل أن يعتمده محامٍ**. كان يُعرض فور
 * كتابته بالنموذج، وكان التقرير نفسه يقول «سيصلك فور اعتماده» ويعرضه في السطر ذاته.
 */
class ConsultReport
{
    /** ما يُكتب مكان الملخّص حين كُتب وينتظر اعتماداً. */
    public const AWAITING_APPROVAL = 'يُعدّ الفريق القانوني ملخص استشارتك، وسيصلك فور اعتماده من المستشار.';

    /**
     * وما يُكتب حين **لم يُكتب بعد** — لا مادّة من الجلسة، فلم يُنادَ النموذج.
     *
     * «بانتظار الإعداد» ≠ «بانتظار الاعتماد»: الثانية تقول إن نصّاً قائم ينتظر
     * توقيعاً، والحال أن لا نصّ. وقول ذلك يجعل العميل ينتظر ما لم يبدأ.
     */
    public const AWAITING_DRAFT = 'لم يُعدّ ملخص استشارتك بعد. يُعدّه المستشار من واقع الجلسة، وسيصلك فور اعتماده.';

    /**
     * @return array<string,mixed> البنية التي يفهمها `ReportPrint::html`
     */
    public static function doc(Consult $consult, string $clientName): array
    {
        // العميل يقرأ تسمياته، ولا يُطبع له مكانُ موعدٍ مقترح قبل اعتماد الإدارة؛ والطاقم يرى الداخليّ
        $forClient = auth()->user()?->isClient() === true;

        $payLabel = $consult->paid_at !== null
            ? 'مدفوعة'
            : ($consult->priced_at !== null ? 'بانتظار السداد' : 'بانتظار التسعير من الإدارة');

        $nextStep = match (true) {
            $consult->priced_at === null => 'بانتظار تحديد السعر من الإدارة',
            $consult->paid_at === null => 'سداد الفاتورة عبر ميسّر',
            // «راجع الملخّص أعلاه» تتبع الاعتماد لا وجود النصّ: كانت تحيل العميل إلى
            // ملخّصٍ محجوب عنه، فيبحث عمّا لا يراه.
            $consult->session === 'منتهية' => match (true) {
                $consult->summaryApproved() => 'راجع ملخص الاستشارة أعلاه',
                // لا نصّ بعد ⇒ لا اعتماد يُنتظَر: الخطوة على المستشار أن يُعدّه
                blank($consult->summary) => 'بانتظار إعداد ملخص الاستشارة من المستشار',
                // بلا «من المستشار»: الاعتماد مرحلتان (المستشار ثمّ الإدارة)، وكانت تُطبع بعد اعتماده
                default => 'بانتظار اعتماد ملخص الاستشارة',
            },
            $consult->session === 'جلسة جارية' => 'الجلسة قائمة الآن',
            default => 'حضور الجلسة في الموعد المحدّد',
        };

        return [
            'title' => 'تقرير الاستشارة القانونية',
            'subtitle' => 'نسخة العميل',
            'ref' => $consult->ref,
            'blocks' => [
                [
                    'title' => '١. بيانات الاستشارة',
                    'cellRows' => [
                        [['رقم الاستشارة', $consult->ref], ['نوع الاستشارة', $consult->channel], ['التخصّص', $consult->specialty ?: '—'], ['الموعد', $consult->when_label ?: '—']],
                        [['المكان', $forClient && $consult->appointmentAwaitingApproval() ? '—' : $consult->placeLabel()], ['حالة الجلسة', $consult->session ?: '—'], ['حالة السداد', $payLabel], ['رقم الفاتورة', $consult->invoice?->number ?: '—']],
                    ],
                ],
                [
                    ['title' => '٢. بياناتك', 'cellRows' => [[['اسم العميل', $clientName], ['الحالة', 'عميل نشط']]]],
                    ['title' => '٣. مقدّم الخدمة', 'cellRows' => [[['الجهة', 'المكتب القانوني'], ['المحامي المسؤول', auth()->user()?->isClient() ? LawyerName::forClient($consult->assigned_lawyer_id ? $consult->assignedLawyer : null, $consult->lawyer, '—') : ($consult->lawyer ?: '—')]]]],
                ],
                ['title' => '٤. ملخص الاستشارة', 'lines' => match (true) {
                    $consult->summaryApproved() => $consult->summary,
                    blank($consult->summary) => self::AWAITING_DRAFT,
                    default => self::AWAITING_APPROVAL,
                }],
                [
                    'title' => '٥. الفاتورة والسداد',
                    'cellRows' => [[
                        ['رسوم الاستشارة', $consult->price ? $consult->price.' ر.س' : '—'],
                        // النسبة المطبَّقة فعلاً على هذه الاستشارة لا «١٥٪» منقوشة — `Consult::vatRate`
                        // (وقبل التسعير لا نسبة تُطبع: لم تُطبَّق بعد)
                        [$consult->vatRate() !== null ? 'الضريبة ('.$consult->vatRate().'٪)' : 'الضريبة', $consult->vat ? $consult->vat.' ر.س' : '—'],
                        ['الإجمالي', $consult->total ? $consult->total.' ر.س' : '—'],
                        ['حالة السداد', $payLabel],
                    ]],
                ],
                ['title' => '٦. الإجراء القادم', 'chips' => [$nextStep]],
            ],
            'approval' => [
                // رابط التحقّق الموقَّع — المسح يُظهر حالة الاستشارة الآن (`DocumentVerification`)
                'qr' => DocumentVerification::url(DocumentVerification::CONSULT, (string) $consult->ref),
                'qrCaption' => 'امسح للتحقّق من التقرير',
                'rows' => [
                    ['الجهة', SettingsRegistry::str('office_name')],
                    // للعميل تسميته — «بانتظار اعتماد الموعد» شأنٌ داخليّ يقرؤه «بانتظار تحديد الموعد»
                    ['حالة الاستشارة', $forClient ? (ConsultStatus::tryFrom((string) $consult->status)?->clientLabel() ?? $consult->status) : $consult->status],
                    ['تاريخ الطباعة', now()->format('Y-m-d')],
                ],
            ],
            'note' => 'هذا التقرير يلخّص استشارتك القانونية ولا يُعدّ بذاته مرافعة أو مستنداً قضائياً. للاستفسار يمكنك فتح تذكرة من بوابتك.',
            // اسم المكتب من الإعدادات — التذييل المنقوش كان يعلو على ما تضبطه الإدارة
            'footer' => SettingsRegistry::str('office_name').' — نسخة العميل · صادرة إلكترونياً',
        ];
    }
}
