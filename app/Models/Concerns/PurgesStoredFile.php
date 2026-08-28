<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * يحذف الملف المخزَّن عند حذف سجلّ المستند.
 *
 * لماذا: لم يكن في المشروع كلّه نداء `Storage::delete` واحد ولا خطّاف حذف على أي نموذج
 * مستند. والمهاجرات تستعمل `cascadeOnDelete` على جداول المستندات، فحذف تذكرة/قضية/طلب
 * تنفيذ يُسقط صفوفها على مستوى قاعدة البيانات — **بلا أي حدث Eloquent** — فتبقى الملفات
 * على القرص يتيمةً وقد ضاع المسار الوحيد الذي يدلّ عليها. لذا يُكمَّل هذا الخطّاف بحذف
 * صريح للأبناء في النماذج الأمّ (راجع PurgesDocumentFiles).
 */
trait PurgesStoredFile
{
    protected static function bootPurgesStoredFile(): void
    {
        static::deleting(function (self $model) {
            $path = (string) ($model->path ?? '');

            if ($path === '') {
                return;
            }

            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable $e) {
                // الحذف أفضل-جهد: تعذّره لا يمنع حذف السجلّ، لكنه لا يمرّ صامتاً
                Log::warning('storage.purge_failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
        });
    }
}
