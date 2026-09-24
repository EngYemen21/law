<?php

declare(strict_types=1);

namespace App\Domain\Ticket\Repositories;

use App\Domain\Ticket\Entities\TicketEntity;
use App\Domain\Ticket\ValueObjects\TicketNumber;

/**
 * واجهة مستودع التذاكر المجردة (Ticket Repository Interface).
 * تتبع مبدأ عكس التبعية (Dependency Inversion) وتُعرف داخل طبقة الدومين الصرفة دون ارتباط بقاعدة البيانات.
 */
interface TicketRepositoryInterface
{
    /**
     * استرجاع كيان التذكرة بالمعرف الرقمي
     */
    public function findById(int $id): ?TicketEntity;

    /**
     * استرجاع كيان التذكرة برقم التذكرة المرجعي
     */
    public function findByNumber(TicketNumber $number): ?TicketEntity;

    /**
     * حفظ أو تحديث كيان التذكرة
     */
    public function save(TicketEntity $ticket): void;

    /**
     * قفل التذكرة للتحديث لمنع المعاملات المزدوجة (Pessimistic Lock)
     */
    public function lockForUpdate(int $id): ?TicketEntity;
}
