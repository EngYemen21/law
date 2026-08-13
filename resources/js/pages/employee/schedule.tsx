import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { todayISO } from '@/components/SpecialistPicker';

// جدولة موعد استشارة من الموظف نيابةً عن عميل — يحفظ حجزاً حقيقياً

interface Props { clients: { id: number; name: string }[]; lawyers: { id: number; name: string }[]; }

const TYPES: [string, string][] = [['office', 'حضورية'], ['video', 'مرئية'], ['phone', 'هاتفية']];

const EmployeeSchedule: React.FC<Props> = ({ clients, lawyers }) => {
  const toast = useToast();
  const [clientId, setClientId] = useState<number | ''>(clients[0]?.id ?? '');
  const [lawyerId, setLawyerId] = useState<number | ''>(lawyers[0]?.id ?? '');
  const [type, setType] = useState('office');
  const [subject, setSubject] = useState('');
  const [date, setDate] = useState(todayISO());
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  // حجب الأوقات الماضية على تاريخ اليوم (شبكة الأمان الخادمية isPast() تبقى قائمة)
  const nowHM = () => new Date().toTimeString().slice(0, 5);
  const isPast = date === todayISO() && time !== '' && time <= nowHM();

  const submit = () => {
    if (!clientId || !date || !time) { toast('اختر العميل والتاريخ والوقت'); return; }
    if (isPast) { toast('لا يمكن اختيار وقت ماضٍ، فضلاً اختر وقتاً لاحقاً'); return; }
    setBusy(true);
    router.post('/employee/schedule', { client_id: clientId, lawyer_id: lawyerId || null, type, subject: subject.trim(), date, time }, {
      preserveScroll: true,
      onFinish: () => setBusy(false),
      onSuccess: () => { toast('تم إنشاء الموعد وحفظه'); setSubject(''); setTime(''); },
      onError: (e) => toast(e.starts_at || e.time || e.date || 'تعذّر إنشاء الموعد'),
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
            <select value={lawyerId} onChange={(e) => setLawyerId(Number(e.target.value))}>
              {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
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
          <div className="field"><label>التاريخ</label><input className="input" type="date" min={todayISO()} value={date} onChange={(e) => setDate(e.target.value)} /></div>
          <div className="field"><label>الوقت</label><input className="input" type="time" min={date === todayISO() ? nowHM() : undefined} value={time} onChange={(e) => setTime(e.target.value)} /></div>
        </div>
        {isPast && <div style={{ color: 'var(--danger, #c0392b)', fontSize: 12, margin: '2px 0 8px' }}>الوقت المختار مضى — اختر وقتاً لاحقاً.</div>}
        <button className="btn block" style={{ marginTop: 14 }} onClick={submit} type="button" disabled={busy || isPast}>
          <Icon name="calplus" /> {busy ? 'جارٍ الحفظ…' : 'تأكيد الجدولة'}
        </button>
      </div>
    </div>
  );
};

export default EmployeeSchedule;
