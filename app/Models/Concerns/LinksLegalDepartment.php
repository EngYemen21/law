<?php

namespace App\Models\Concerns;

use App\Models\LegalService;
use App\Support\LegalCatalogue;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **يربط السجلّ بقسمه في الكتالوج عند كلّ حفظ** — من العمود النصّيّ الذي يكتبه التطبيق.
 *
 * لماذا في النموذج لا في المتحكّمات: القسم يُكتب من مواضع كثيرة (فتح التذكرة، والتحويل بين
 * الأقسام، والفرز الآليّ، وتحويل التذكرة لقضيّة، واعتماد تصنيف الذكاء، والحجز). ربطُ كلٍّ منها
 * يدويّاً يعني موضعاً يُنسى فيتباعد المعرّف عن النصّ. هنا يتبع المعرّفُ النصَّ دائماً.
 *
 * القواعد:
 *   - يُشتقّ المعرّف فقط حين يتغيّر النصّ ولم يُكتب المعرّف صراحةً في الحفظ نفسه —
 *     فمن يكتب المعرّف بنفسه (نموذج التذكرة الجديد) لا يُكتب فوقه.
 *   - المطابقة صارمة: نصٌّ لا يُطابَق يترك المعرّف فارغاً، ولا يُخمَّن له قسم.
 *
 * يستعمله النموذج بتعريف `legalDepartmentSource()`، و`legalServiceSource()` اختياريّاً للتذكرة.
 *
 * @property int|null $legal_department_id
 */
trait LinksLegalDepartment
{
    /** العمود النصّيّ الذي يدلّ على القسم (`department` أو `specialty`). */
    abstract protected function legalDepartmentSource(): string;

    /** العمود النصّيّ الذي يدلّ على الخدمة — null حين لا خدمة للنموذج. */
    protected function legalServiceSource(): ?string
    {
        return null;
    }

    protected static function bootLinksLegalDepartment(): void
    {
        static::saving(fn (self $model) => $model->syncLegalCatalogueLinks());
    }

    /** @return BelongsTo<LegalService, $this> */
    public function legalService(): BelongsTo
    {
        return $this->belongsTo(LegalService::class, 'legal_service_id');
    }

    private function syncLegalCatalogueLinks(): void
    {
        $departmentSource = $this->legalDepartmentSource();

        if ($this->isDirty($departmentSource) && ! $this->isDirty('legal_department_id')) {
            $this->legal_department_id = LegalCatalogue::resolveDepartment($this->getAttribute($departmentSource))?->id;
        }

        $serviceSource = $this->legalServiceSource();
        if ($serviceSource === null || $this->isDirty('legal_service_id')) {
            return;
        }

        if ($this->isDirty($serviceSource) || $this->isDirty('legal_department_id')) {
            $this->setAttribute('legal_service_id', $this->legal_department_id === null
                ? null
                : LegalCatalogue::resolveService($this->getAttribute($serviceSource), (int) $this->legal_department_id)?->id);
        }
    }
}
