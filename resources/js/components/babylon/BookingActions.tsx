import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import type { ConsultCard } from '@/lib/consult-ui';
import Icon from '@/lib/icons';
import { firstError } from '@/lib/server-message';

// إجراءات دورة الحجز للعميل (تسعير → دفع الفاتورة عبر ميسّر → المكتب يحدّد الموعد)
// مشتركة بين «احجز استشارة» و«استشاراتي».
// **العميل لا يختار موعد جلسته** (قرار المالك 2026-09-14): بعد السداد يحدّده المكتب ويصله إشعارٌ وبريد به.
type BookingCard = Pick<ConsultCard, 'id' | 'status' | 'total'>;

const BookingActions: React.FC<{ c: BookingCard; toast: (m: string) => void }> = ({ c, toast }) => {
  const [busy, setBusy] = useState(false);

  if (c.status === 'بانتظار التسعير') return <Badge text="بانتظار تسعير المكتب" tone="b-amber" />;

  if (c.status === 'بانتظار السداد') return (
    <button className="btn sm" type="button" disabled={busy} onClick={() => {
      setBusy(true);
      // النجاح = تحويل المتصفّح لصفحة ميسّر (Inertia::location) — لا توست «تم السداد» هنا؛ فقط عرض تعذّر البدء
      router.post(`/consults/${c.id}/pay`, {}, { preserveScroll: true, onError: (errors) => toast(firstError(errors, 'تعذّر بدء الدفع، حاول بعد قليل')), onFinish: () => setBusy(false) });
    }}>
      <Icon name="card" /> ادفع عبر ميسّر — {c.total} ر.س
    </button>
  );

  // بانتظار تحديد الموعد — يحدّده المكتب
  return (
    <span className="action-hint" style={{ margin: 0 }}>
      <Icon name="clock" /> سوف يتم تحديد موعد جلسة استشارية مع المستشار المختص وتزويدك بالموعد المحدد
    </span>
  );
};

export default BookingActions;
