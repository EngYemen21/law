<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إرسال المسوّدة إلى العميل ⇐ «مستحقة»** (م٢).
 *
 * «مسوّدة» حالةٌ جديدة (ب٦): فاتورةٌ صدرت في النظام ولم تُرسَل بعد، فلا مطالبةَ بها ولا
 * سداد — وهي ما يتيح مراجعةً قبل أن يرى العميل رقماً. والإصدار هو اللحظة التي تصير فيها
 * مطالبةً، فيُختم `issued_at` هنا لا عند الإنشاء.
 *
 * **ولا يُعاد إصدارُ ما صدر:** `from()` يقتصر على المسوّدة، فمحاولةٌ على «مستحقة» تُردّ —
 * وإلّا أعادت ختم تاريخ الإصدار فتحرّك عمرُ الفاتورة في الأعمار والتقارير إلى الوراء.
 *
 * **ومواضع الإصدار الستّة لا تمرّ به اليوم**: كلّها تُنشئ الفاتورة «مستحقة» مباشرةً
 * (`Finance\InvoiceFactory`) كما كانت — لا مفهومَ «مسوّدة» في أيّ شاشةٍ قبل م٣. الانتقال
 * بنيةٌ جاهزة لها، وحارسٌ يمنع أن تصير المسوّدة طريقاً مسدوداً حين تُستعمل.
 *
 * @extends Transition<Invoice>
 */
final class IssueInvoice extends Transition
{
    public function name(): string
    {
        return 'invoice.issue';
    }

    public function from(): array
    {
        return [InvoiceStatus::Draft->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return InvoiceStatus::Due->value;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->issued_at = now();
        $entity->tone = InvoiceStatus::Due->tone();
    }
}
