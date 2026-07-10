import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';

// يطابق viewExecs — طلبات التنفيذ من قاعدة البيانات + إنشاء طلب مباشر

interface ExecCard { no: string; subject: string; status: string; tone: string; last: string; }

const SUBJECTS = ['تنفيذ حكم مالي', 'تنفيذ سند لأمر', 'تنفيذ حكم إخلاء', 'تنفيذ حكم عمالي'];

const Execs: React.FC<{ execs: ExecCard[] }> = ({ execs }) => {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [subject, setSubject] = useState(SUBJECTS[0]);
  const [details, setDetails] = useState('');

  const create = () =>
    router.post('/execs', { subject, details }, { onSuccess: () => toast('تم فتح طلب التنفيذ') });

  return (
    <div className="card">
      <div className="card-h">
        <h3>طلبات التنفيذ</h3>
        <button className="btn sm" type="button" onClick={() => setOpen((o) => !o)}>
          <Icon name="exec" /> طلب تنفيذ جديد
        </button>
      </div>

      {open && (
        <div className="card-b" style={{ padding: 16, borderBottom: '1px solid var(--line)' }}>
          <div className="field">
            <label>موضوع التنفيذ</label>
            <select value={subject} onChange={(e) => setSubject(e.target.value)}>
              {SUBJECTS.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <div className="field">
            <label>تفاصيل الطلب</label>
            <textarea value={details} onChange={(e) => setDetails(e.target.value)} placeholder="اذكر السند/الحكم والمطلوب تنفيذه…" />
          </div>
          <button className="btn" type="button" onClick={create}><Icon name="send" /> فتح الطلب</button>
        </div>
      )}

      <div className="card-b t-wrap">
        {execs.length ? (
          <table className="tbl">
            <thead>
              <tr><th>رقم الطلب</th><th>الموضوع</th><th>الحالة</th><th>آخر إجراء</th><th /></tr>
            </thead>
            <tbody>
              {execs.map((e) => (
                <tr key={e.no} className="click" onClick={() => router.visit(`/execs/${encodeURIComponent(e.no)}`)}>
                  <td className="mono">{e.no}</td>
                  <td>{e.subject}</td>
                  <td><Badge text={e.status} tone={e.tone} /></td>
                  <td className="muted">{e.last}</td>
                  <td>
                    <button className="btn soft sm" type="button" onClick={(ev) => { ev.stopPropagation(); router.visit(`/execs/${encodeURIComponent(e.no)}`); }}>
                      <Icon name="reply" /> فتح المتابعة
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="empty"><Icon name="exec" /><b>لا طلبات تنفيذ</b></div>
        )}
      </div>
    </div>
  );
};

export default Execs;
