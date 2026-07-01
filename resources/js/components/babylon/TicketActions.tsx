import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { REQ_DOCS, DEPTS, LAWYERS } from '@/lib/employee-data';

// يطابق سلوك emReqDocs/sendReqDocs + emTransfer + emScheduleModal في index (82).html
// خطاف يوفّر فتح المودالات الثلاثة وتنفيذ أزرارها.

type ActionKind = 'reqdocs' | 'transfer' | 'schedule' | null;

export function useTicketActions() {
  const toast = useToast();
  const [kind, setKind] = useState<ActionKind>(null);
  const [no, setNo] = useState('');

  const openReqDocs = (n: string) => { setNo(n); setKind('reqdocs'); };
  const openTransfer = (n: string) => { setNo(n); setKind('transfer'); };
  const openSchedule = (n: string) => { setNo(n); setKind('schedule'); };
  const close = () => setKind(null);

  // مودال طلب النواقص
  const [chosen, setChosen] = useState<Record<string, boolean>>({});
  const [extra, setExtra] = useState('');

  const toggleChip = (r: string) => setChosen((c) => ({ ...c, [r]: !c[r] }));

  const sendReqDocs = () => {
    const picked = REQ_DOCS.filter((r) => chosen[r]);
    const extras = extra.split('\n').map((s) => s.trim()).filter(Boolean);
    const all = picked.concat(extras);
    if (!all.length) { toast('اختر أو اكتب مستنداً واحداً على الأقل'); return; }
    close();
    setChosen({});
    setExtra('');
    toast(`تم إرسال طلب النواقص للعميل (${all.length} مستند)`);
  };

  const node = (
    <>
      <Modal title={`طلب نواقص — ${no}`} open={kind === 'reqdocs'} onClose={close}>
        <p style={{ fontSize: '13.5px', color: '#2b4a68', marginBottom: 10 }}>
          اختر المستندات المطلوبة من العميل، ويمكنك أيضاً كتابة مستندات إضافية:
        </p>
        <div className="chips" style={{ marginBottom: 14 }}>
          {REQ_DOCS.map((r) => (
            <span
              key={r}
              className={`chip sel-toggle${chosen[r] ? ' on' : ''}`}
              onClick={() => toggleChip(r)}
            >
              {r}
            </span>
          ))}
        </div>
        <div className="field">
          <label>مستندات إضافية (كتابة)</label>
          <textarea
            value={extra}
            onChange={(e) => setExtra(e.target.value)}
            placeholder="اكتب أي مستندات أخرى مطلوبة، كل مستند في سطر…"
          />
        </div>
        <button className="btn block" onClick={sendReqDocs} type="button">
          <Icon name="send" /> إرسال طلب النواقص
        </button>
      </Modal>

      <Modal title={`تحويل التذكرة — ${no}`} open={kind === 'transfer'} onClose={close}>
        <div className="field">
          <label>القسم المختص</label>
          <select>{DEPTS.map((d) => <option key={d}>{d}</option>)}</select>
        </div>
        <div className="field">
          <label>المستشار</label>
          <select>
            <option>توزيع تلقائي</option>
            {LAWYERS.map((l) => <option key={l.name}>{l.name}</option>)}
          </select>
        </div>
        <button
          className="btn block"
          onClick={() => { close(); toast('تم تحويل التذكرة إلى القسم المختص'); }}
          type="button"
        >
          <Icon name="reply" /> تأكيد التحويل
        </button>
      </Modal>

      <Modal title={`جدولة موعد — ${no}`} open={kind === 'schedule'} onClose={close}>
        <div className="field">
          <label>المستشار</label>
          <select>{LAWYERS.map((l) => <option key={l.name}>{l.name}</option>)}</select>
        </div>
        <div className="field">
          <label>اليوم والوقت</label>
          <select>
            <option>الاثنين 29 يونيو · 11:30 ص</option>
            <option>الثلاثاء 30 يونيو · 01:00 م</option>
          </select>
        </div>
        <button
          className="btn block"
          onClick={() => { close(); toast('تم إنشاء الموعد'); }}
          type="button"
        >
          <Icon name="calplus" /> تأكيد
        </button>
      </Modal>
    </>
  );

  return { openReqDocs, openTransfer, openSchedule, node };
}
