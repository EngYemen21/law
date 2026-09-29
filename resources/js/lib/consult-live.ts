import type { ConsultCard } from '@/lib/consult-ui';

/**
 * **بثّ الاستشارة على شاشات الطاقم — قاعدةٌ واحدة لأربع شاشات.**
 *
 * قناة `consult.{id}` يستمع لها العميل أيضاً (`ConsultStatusBroadcast`)، فحمولتها ما يجوز للعميل:
 * - `status` **تسمية العميل** («بانتظار اعتماد الموعد» تصل «بانتظار تحديد الموعد») — نسخُها فوق بطاقة
 *   الطاقم كان يُخفي مرحلة الاعتماد حتى إعادة التحميل.
 * - `summary` **المعتمَد وحده** — نسخُه كان يمسح النصّ غير المعتمَد الذي يراجعه الطاقم.
 *
 * فيُدمج الباقي (الجلسة · `missed` · `startable` · `canJoin` · الدفع…)، وتغيُّر المرحلة يُعيد قراءة
 * البطاقة من الخادم بحقولها المشتقّة (`tone` · `bookingStage` · الأعلام).
 */
export function staffPatch(e: Partial<ConsultCard>): Partial<ConsultCard> {
    const rest = { ...e };
    delete rest.status;
    delete rest.summary;

    return rest;
}

/** تغيّرت مرحلة الملفّ أو جلسته — فالبطاقة تُقرأ من الخادم لا تُرقَّع. */
export function stageChanged(
    e: Partial<ConsultCard>,
    c: Pick<ConsultCard, 'status' | 'session'>,
): boolean {
    return (
        (e.status !== undefined && e.status !== c.status) ||
        (e.session !== undefined && e.session !== c.session)
    );
}
