<?php

namespace App\Models\Concerns;

/**
 * يحذف مستندات السجلّ عبر Eloquent قبل حذفه، كي تُطلق خطّافات PurgesStoredFile.
 *
 * لماذا: `cascadeOnDelete` في المهاجرات يحذف الصفوف على مستوى قاعدة البيانات مباشرةً،
 * فلا يمرّ أي حدث Eloquent ولا يُحذف ملف واحد. الحذف الصريح هنا يجعل التسلسل يمرّ
 * بالتطبيق أوّلاً (والقيد الأجنبي يبقى شبكة أمان لما يُحذف خارج التطبيق).
 */
trait PurgesDocumentFiles
{
    protected static function bootPurgesDocumentFiles(): void
    {
        static::deleting(function (self $model) {
            $model->documents()->cursor()->each->delete();
        });
    }
}
