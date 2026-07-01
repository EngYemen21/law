import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { type MeetRequest, MEET_REQUESTS, MR_FLOW, maskClient } from '@/lib/employee-data';

// يطابق meetReqsView (دور الموظف) + sendMeetInvite/mrCancel في index (82).html

// أدلّة العملاء — يطابق CLIENT_DIR
const CLIENT_DIR: { name: string; items: string[] }[] = [
  { name: 'عبدالله محمد العتيبي', items: ['SB-2026-1042 — استشارة تجارية', 'CASE-2026-014 — قضية تجارية', 'EXE-2026-2210 — طلب تنفيذ حكم'] },
  { name: 'نورة سعد الدوسري', items: ['SB-2026-1009 — استشارة عمالية', 'CASE-2026-031 — قضية عمالية'] },
  { name: 'شركة الأفق التجارية', items: ['SB-2026-0987 — مراجعة عقد', 'CASE-2026-022 — نزاع تجاري', 'EXE-2026-2185 — تنفيذ مطالبة'] },
  { name: 'فهد علي الشهري', items: ['SB-2026-0950 — استشارة تنفيذ'] },
];

const caseOptionsFor = (name: string): string[] => {
  const c = CLIENT_DIR.find((x) => x.name === name);
  return (c && c.items) || [];
};

const EmployeeMeetReqs: React.FC = () => {
  const toast = useToast();
  const [list, setList] = useState<MeetRequest[]>(() => MEET_REQUESTS.map((r) => ({ ...r })));
  const [open, setOpen] = useState(false);

  // حقول مودال إرسال الدعوة
  const [miClient, setMiClient] = useState(CLIENT_DIR[0].name);
  const [miService, setMiService] = useState('');
  const [miType, setMiType] = useState('استشارة مرئية');
  const [miDay, setMiDay] = useState('');
  const [miTime, setMiTime] = useState('');

  const cancel = (id: string) => {
    setList((p) => p.filter((x) => x.id !== id));
    toast('تم إلغاء الدعوة');
  };

  const submitInvite = () => {
    const n = `MR-${Math.floor(1000 + Math.random() * 9000)}`;
    const svc = miService || 'استشارة';
    const dy = miDay || '—';
    const tm = miTime || '—';
    setList((p) => [{ id: n, client: miClient, service: svc, type: miType, day: dy, time: tm, by: 'منيرة الحربي (خدمة العملاء)', stage: 0 }, ...p]);
    setOpen(false);
    setMiService(''); setMiDay(''); setMiTime('');
    toast(`تم إرسال الدعوة وإشعارها إلى العميل: ${miClient}`);
  };

  const enterRoom = (r: MeetRequest) => {
    if (r.type && r.type.indexOf('مرئية') >= 0) {
      router.visit(`/employee/videoroom?kind=req&id=${encodeURIComponent(r.id)}`);
    } else {
      toast('سيتم فتح رابط الاجتماع في موعده');
    }
  };

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>
          يرسل المكتب دعوة الاجتماع للعميل، فيستقبلها ويؤكّد حضوره. <b>المسار:</b> إرسال الدعوة للعميل ← تأكيد حضور العميل ← تنفيذ الجلسة ← اعتماد الإدارة.
        </p>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 14 }}>
        <button className="btn" onClick={() => setOpen(true)} type="button">
          <Icon name="send" /> إرسال دعوة اجتماع للعميل
        </button>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>طلبات الاجتماعات</h3>
          <span className="sub">{list.length} دعوة</span>
        </div>
        <div className="card-b">
          {list.length ? list.map((r) => (
            <div key={r.id} className="item">
              <div className="iico"><Icon name="video" /></div>
              <div className="imeta">
                <b>{r.id} — {maskClient(r.client)}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  {r.type} · {r.service} · {r.day} {r.time} · أرسلها: {r.by || 'المكتب'}
                </span>
                {r.stage >= 1 && r.meetId && (
                  <span style={{ display: 'block', margin: '4px 0', fontSize: '11.5px', color: 'var(--primary)', fontWeight: 700, direction: 'ltr', textAlign: 'right' }}>
                    🔗 {r.meetLink}
                  </span>
                )}
                <span><FlowLine steps={MR_FLOW} cur={r.stage} /></span>
              </div>
              <div className="iact">
                {r.stage >= 3 ? (
                  <Badge text="معتمد" tone="b-green" />
                ) : r.stage === 0 ? (
                  <>
                    <span className="chip muted">بانتظار تأكيد العميل</span>
                    <button className="btn soft sm" onClick={() => cancel(r.id)} type="button">
                      <Icon name="out" /> إلغاء
                    </button>
                  </>
                ) : (
                  <span className="chip muted">{MR_FLOW[r.stage]}</span>
                )}
                {r.stage >= 1 && r.meetId && (
                  <>
                    <button className="btn soft sm" onClick={() => toast('تم نسخ رابط الاجتماع')} type="button">
                      <Icon name="link" /> نسخ الرابط
                    </button>
                    <button className="btn sm" onClick={() => enterRoom(r)} type="button">
                      <Icon name="video" /> {r.type && r.type.indexOf('مرئية') >= 0 ? 'دخول الغرفة' : 'دخول'}
                    </button>
                  </>
                )}
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="video" /><b>لا دعوات اجتماعات حالياً</b></div>
          )}
        </div>
      </div>

      <Modal title="إرسال دعوة اجتماع للعميل" open={open} onClose={() => setOpen(false)}>
        <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
          يرسل المكتب الدعوة للعميل ليؤكّد حضوره.
        </p>
        <div className="field">
          <label>العميل (من المسجّلين)</label>
          <select value={miClient} onChange={(e) => setMiClient(e.target.value)}>
            {CLIENT_DIR.map((c) => <option key={c.name} value={c.name}>{c.name}</option>)}
          </select>
        </div>
        <div className="field">
          <label>قضية / استشارة العميل</label>
          <select>
            <option value="">— اختر قضية/استشارة —</option>
            {caseOptionsFor(miClient).map((i) => <option key={i} value={i}>{i}</option>)}
          </select>
        </div>
        <div className="field">
          <label>الموضوع/الخدمة</label>
          <input className="input" value={miService} onChange={(e) => setMiService(e.target.value)} placeholder="مثال: نزاع تجاري" />
        </div>
        <div className="field">
          <label>نوع الاجتماع</label>
          <select value={miType} onChange={(e) => setMiType(e.target.value)}>
            <option>استشارة مرئية</option>
            <option>استشارة حضورية</option>
            <option>استشارة هاتفية</option>
          </select>
        </div>
        <div className="picker-grid">
          <div className="field">
            <label>اليوم</label>
            <input className="input" type="date" value={miDay} onChange={(e) => setMiDay(e.target.value)} />
          </div>
          <div className="field">
            <label>الوقت</label>
            <input className="input" type="time" value={miTime} onChange={(e) => setMiTime(e.target.value)} />
          </div>
        </div>
        <button className="btn block" onClick={submitInvite} type="button">
          <Icon name="send" /> إرسال الدعوة للعميل
        </button>
      </Modal>
    </>
  );
};

export default EmployeeMeetReqs;
