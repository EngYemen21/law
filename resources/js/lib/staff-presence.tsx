import { usePage } from '@inertiajs/react';
import React from 'react';
import Badge from '@/components/babylon/Badge';

/**
 * **حالة المحامي الحيّة: في جلسة Zoom الآن أم متاح** — المصدر الواحد لكلّ قوائم المحامين.
 *
 * تأتي من أحداث Zoom نفسها (دخولٌ/خروجٌ من أيّ اجتماعٍ أو استشارة — `RoomPresence::staffInSession`)
 * عبر الخاصيّة المشتركة `inSession` [معرّف ⇒ رقم الجلسة]. للعرض وحده (قرار المالك 2026-09-29):
 * حكم الحجز من المواعيد (`LawyerAvailability`)، لا من هذه الحالة.
 */
export function useInSession(): Record<number, string> {
    const { props } = usePage() as unknown as {
        props: { inSession?: Record<number, string> | null };
    };

    return props.inSession ?? {};
}

/** لاحقةٌ لنصّ `<option>` (لا يحمل عناصر) — فارغةٌ لمن ليس في جلسة. */
export function inSessionSuffix(
    inSession: Record<number, string>,
    id: number | string | null | undefined,
): string {
    const ref = id == null || id === '' ? undefined : inSession[Number(id)];

    return ref ? ` — في جلسة الآن (${ref})` : '';
}

/** شارة الحالة بجانب اسم المحامي. `showFree` تُظهر «متاح الآن» أيضاً (الجداول والبطاقات). */
export const PresenceBadge: React.FC<{
    userId: number | null | undefined;
    showFree?: boolean;
}> = ({ userId, showFree = false }) => {
    const inSession = useInSession();
    const ref = userId == null ? undefined : inSession[userId];

    if (ref) {
        return <Badge text={`في جلسة الآن · ${ref}`} tone="b-red" />;
    }

    return showFree && userId != null ? (
        <Badge text="متاح الآن" tone="b-green" />
    ) : null;
};
