import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// جدولة موعد استشارة من الموظف نيابةً عن عميل — يحفظ حجزاً حقيقياً

interface Props { clients: { id: number; name: string }[]; lawyers: string[]; }

const TYPES: [string, string][] = [['office', 'حضورية'], ['video', 'مرئية'], ['phone', 'هاتفية']];

const EmployeeSchedule: React.FC<Props> = ({ clients, lawyers }) => {
  const toast = useToast();
  const [clientId, setClientId] = useState<number | ''>(clients[0]?.id ?? '');
  const [lawyer, setLawyer] = useState(lawyers[0] ?? '');
  const [type, setType] = useState('office');
  const [subject, setSubject] = useState('');
  const [day, setDay] = useState('');
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = () => {
    if (!clientId || !day.trim() || !time.trim()) { toast('اختر العميل واليوم والوقت'); return; }
    setBusy(true);
    router.post('/employee/schedule', { client_id: clientId, lawyer, type, subject: subject.trim(), day: day.trim(), time: time.trim() }, {
      preserveScroll: true,
      onFinish: () => setBusy(false),
      onSuccess: () => { toast('تم إنشاء الموعد وحفظه'); setSubject(''); setDay(''); setTime(''); },
    });
  };

  return (
    <div className="card">
      <div className="card-h"><h3>جدولة موعد جديد</h3></div>
      <div className="card-b" style={{ padding: 18 }}>
        {clients.length === 0 && <div className="empty"><Icon name="user" /><b>لا عملاء مسجّلون بعد</b></div>}
        <div className="picker-grid">
          <div className="field">
            <label>العميل</label>
            <select value={clientId} onChange={(e) => setClientId(Number(e.target.value))}>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div className="field">
            <label>المستشار</label>
            <select value={lawyer} onChange={(e) => setLawyer(e.target.value)}>
              {lawyers.map((l) => <option key={l}>{l}</option>)}
            </select>
          </div>
        </div>
        <div className="picker-grid">
          <div className="field">
            <label>نوع الاستشارة</label>
            <select value={type} onChange={(e) => setType(e.target.value)}>
              {TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="field"><label>موضوع الاستشارة</label><input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="اختياري" /></div>
        </div>
        <div className="picker-grid">
          <div className="field"><label>اليوم</label><input value={day} onChange={(e) => setDay(e.target.value)} placeholder="مثال: الأحد 12 يوليو" /></div>
          <div className="field"><label>الوقت</label><input value={time} onChange={(e) => setTime(e.target.value)} placeholder="مثال: 01:00 م" /></div>
        </div>
        <button className="btn block" style={{ marginTop: 14 }} onClick={submit} type="button" disabled={busy}>
          <Icon name="calplus" /> {busy ? 'جارٍ الحفظ…' : 'تأكيد الجدولة'}
        </button>
      </div>
    </div>
  );
};

export default EmployeeSchedule;
