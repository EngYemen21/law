import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import SpecialistPicker, { todayISO } from '@/components/SpecialistPicker';
import type { ConsultCard } from '@/lib/consult-ui';
import Icon from '@/lib/icons';

// إجراءات دورة الحجز (تسعير → دفع الفاتورة الحقيقي عبر ميسّر → اختيار الموعد بعد السداد)
// مشتركة بين «احجز استشارة» و«استشاراتي» — نفس المنطق، بلا تكرار.
// اختيار الموعد يُعرض داخل نافذة منبثقة (تطابق bkSlots بالتصميم المرجعي) بدل تمديد صفّ القائمة.
// يقرأ خمسة حقول لا غير، فيطلبها وحدها — فيخدم بطاقة الطاقم وبطاقة العميل معاً
// بلا أن يدّعي حاجةً إلى حقولٍ لا تصل صفحة العميل أصلاً.
type BookingCard = Pick<ConsultCard, 'id' | 'specialty' | 'status' | 'subject' | 'total'>;

const BookingActions: React.FC<{ c: BookingCard; toast: (m: string) => void }> = ({ c, toast }) => {
  const [open, setOpen] = useState(false);
  const [date, setDate] = useState(todayISO());
  const [lawyerId, setLawyerId] = useState<number | null>(null);
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  if (c.status === 'بانتظار التسعير') return <Badge text="بانتظار تسعير المكتب" tone="b-amber" />;

  if (c.status === 'بانتظار السداد') return (
    <button className="btn sm" type="button" disabled={busy} onClick={() => {
      setBusy(true);
      // النجاح = تحويل المتصفّح لصفحة ميسّر (Inertia::location) — لا توست «تم السداد» هنا؛ فقط عرض تعذّر البدء
      router.post(`/consults/${c.id}/pay`, {}, { preserveScroll: true, onError: (errors) => toast(Object.values(errors)[0] ?? 'تعذّر بدء الدفع، حاول بعد قليل'), onFinish: () => setBusy(false) });
    }}>
      <Icon name="card" /> ادفع عبر ميسّر — {c.total} ر.س
    </button>
  );

  // بانتظار تحديد الموعد
  const confirm = () => {
    setBusy(true);
    // الإسناد خادميّ (LawyerAvailability::assignLawyer) — lawyer_id كان يُرسَل ويُهمَل
    router.post(`/consults/${c.id}/schedule`, { date, time }, {
      preserveScroll: true,
      onSuccess: () => { toast('تم تأكيد الموعد'); setOpen(false); setTime(''); setLawyerId(null); },
      onError: () => toast('تعذّر، جرّب فترة أخرى'),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <>
      <button className="btn sm" type="button" onClick={() => setOpen(true)}><Icon name="cal" /> اختر موعد الجلسة</button>
      <Modal title="اختيار موعد الاستشارة" open={open} onClose={() => setOpen(false)}>
        <div className="field">
          <label>اليوم</label>
          <input className="input" type="date" min={todayISO()} value={date}
            onChange={(e) => { setDate(e.target.value); setLawyerId(null); setTime(''); }} />
        </div>
        <SpecialistPicker fetchUrl="/book/availability" fetchParams={{ subject: c.subject, specialty: c.specialty || '' }} enabled autoAssign
          date={date} onDateSnap={setDate} lawyerId={lawyerId} onLawyerChange={setLawyerId} time={time} onTimeChange={setTime} />
        {/* الشرط على الوقت وحده: الإسناد خادميّ ولا يُرسَل lawyer_id */}
        <button className="btn block" type="button" style={{ marginTop: 14 }} disabled={!time || busy} onClick={confirm}>
          <Icon name="cal" /> {busy ? 'جارٍ التأكيد…' : 'تأكيد الموعد'}
        </button>
      </Modal>
    </>
  );
};

export default BookingActions;
