<?php

namespace App\Support;

use App\Jobs\RecordAuditLogJob;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * غلاف التسجيل في سجل التدقيق الأمني — صامت الفشل عمدًا:
 * السجل توثيق لاحق للعملية ولا يجوز أن يُفشل العملية نفسها أبدًا
 * (جدول مفقود قبل الهجرة، قاعدة مقفلة، عمود متغير…).
 *
 * الكتابة عبر الطابور (QUEUE_CONNECTION=database): سياق الطلب — المستخدم وIP
 * والمتصفح ووقت الحدث — يُلتقط هنا لحظيًا في الحمولة، والإدراج الفعلي يجري في
 * العامل كي لا يزاحم قيدُ التدقيق العمليةَ الأصلية (تسعير/حجز/اعتماد…).
 */
class Audit
{
    public static function log(
        string $action,
        string $description,
        string $category = 'عام',
        string $severity = 'info',
        ?Model $auditable = null,
        ?string $auditableRef = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?User $user = null,
    ): void {
        try {
            $payload = AuditLog::preparePayload(
                action: $action,
                description: $description,
                category: $category,
                severity: $severity,
                auditable: $auditable,
                auditableRef: $auditableRef,
                beforeState: $beforeState,
                afterState: $afterState,
                user: $user,
            );
            // وقت الحدث لا وقت معالجة الطابور — كي لا ينسب التأخير زمناً خاطئاً للقيد
            $payload['created_at'] = now();
            $payload['updated_at'] = now();

            RecordAuditLogJob::dispatch($payload);
        } catch (\Throwable $e) {
            Log::warning('[Audit] تعذّر تسجيل قيد التدقيق: '.$e->getMessage());
        }
    }
}
