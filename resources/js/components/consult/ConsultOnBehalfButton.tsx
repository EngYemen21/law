import { router, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import Modal from '@/components/babylon/Modal';
import { panelBase } from '@/lib/data';
import { matchesSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import type { ClientDirEntry } from '@/lib/meeting-ui';
import { useServerAction } from '@/lib/use-server-action';

/**
 * **«طلب استشارة نيابةً عن العميل»** — زرٌّ ونموذجٌ واحد في أربع صفحات: طلبات الاستشارات (الإدارة)، وإدارة
 * الاستشارات (الموظّف)، وتبويب المواعيد في تقويمَي الإدارة والموظّف (بجانب «حجز موعد جديد»).
 *
 * الطلب يسلك رحلته العاديّة (`Employee\ScheduleController::requestFor`): تسعير ← سداد ← حجز الطاقم.
 * ودليل العملاء وأنواع الاستشارة خاصّيّةٌ اختياريّة (`ConsultBooking::onBehalfForm`) تُحمَّل عند الفتح وحده.
 */
interface OnBehalfForm {
  clients: ClientDirEntry[];
  types: { key: string; label: string; ico: string }[];
}

const ConsultOnBehalfButton: React.FC<{ className?: string }> = ({ className = 'btn soft' }) => {
  const { consultRequestForm: form } = usePage<{ consultRequestForm?: OnBehalfForm }>().props;
  const action = useServerAction();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [clientId, setClientId] = useState<number | ''>('');
  const [type, setType] = useState('');
  const [subject, setSubject] = useState('');
  const [ticketNo, setTicketNo] = useState('');

  const show = () => {
    setOpen(true);

    if (!form) {
      router.reload({ only: ['consultRequestForm'] });
    }
  };
  const close = () => {
    setOpen(false);
    setSearch('');
    setClientId('');
    setType('');
    setSubject('');
    setTicketNo('');
  };

  const clients = (form?.clients ?? []).filter((c) => matchesSearch(search, c.name, ...c.items.map((i) => i.ref)));
  const tickets = (form?.clients.find((c) => c.id === clientId)?.items ?? []).filter((i) => i.kind === 'ticket');
  const ready = clientId !== '' && type !== '';

  const submit = () => {
    if (!ready) {
      return;
    }

    void action.run(`${panelBase(window.location.pathname)}/consults/request`, {
      data: { client_id: clientId, type, subject: subject.trim() || null, ticket_no: ticketNo || null },
      fallback: 'تعذّر إنشاء طلب الاستشارة',
      onSuccess: close,
    });
  };

  return (
    <>
      <button className={className} type="button" onClick={show}>
        <Icon name="calplus" /> طلب استشارة نيابةً عن العميل
      </button>

      <Modal title="طلب استشارة نيابةً عن العميل" subtitle="يسلك الطلب مساره العاديّ: تسعيرٌ فسدادٌ فحجز الموعد." open={open} onClose={close}>
        {!form ? (
          <p style={{ color: 'var(--muted)' }}>جارٍ تحميل دليل العملاء…</p>
        ) : (
          <>
            <div className="field">
              <label>العميل</label>
              <input className="input" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث بالاسم أو برقم تذكرة…" />
              <select className="input" style={{ marginTop: 6 }} value={clientId} onChange={(e) => {
 setClientId(e.target.value ? Number(e.target.value) : ''); setTicketNo(''); 
}}>
                <option value="">اختر العميل…</option>
                {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </div>
            <div className="field">
              <label>نوع الاستشارة</label>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {form.types.map((t) => (
                  <button key={t.key} type="button" className={`btn sm ${type === t.key ? '' : 'soft'}`} onClick={() => setType(t.key)}>
                    <Icon name={t.ico} /> {t.label}
                  </button>
                ))}
              </div>
            </div>
            {tickets.length > 0 && (
              <div className="field">
                <label>ربطٌ بتذكرة (اختياري)</label>
                <select className="input" value={ticketNo} onChange={(e) => setTicketNo(e.target.value)}>
                  <option value="">بلا تذكرة</option>
                  {tickets.map((t) => <option key={t.ref} value={t.ref}>{t.ref}{t.subject ? ` — ${t.subject}` : ''}</option>)}
                </select>
              </div>
            )}
            <div className="field">
              <label>الموضوع (اختياري)</label>
              <input className="input" value={subject} maxLength={120} onChange={(e) => setSubject(e.target.value)} placeholder="مثال: استشارة في عقد إيجار تجاري" />
            </div>
            <button className="btn block" type="button" disabled={!ready || action.busy} onClick={submit}>
              <Icon name="send" /> {action.busy ? 'جارٍ الإرسال…' : 'إنشاء طلب الاستشارة'}
            </button>
          </>
        )}
      </Modal>
    </>
  );
};

export default ConsultOnBehalfButton;
