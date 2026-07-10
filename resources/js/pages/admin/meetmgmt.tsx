import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { MEET_STATUSES, MEET_TYPES_FULL, MEET_TEMPLATES, meetStatusTone, STAFF_DIR } from '@/lib/admin-data';
import { type ClientDirEntry, type FullMeetingCard } from '@/lib/meeting-ui';

// يطابق meetMgmtView + openCreateMeeting + submitMeeting في index (82).html — البيانات حقيقية من الخادم

interface Props { meetings: FullMeetingCard[]; clients: ClientDirEntry[] }

const AdminMeetMgmt: React.FC<Props> = ({ meetings, clients }) => {
  const toast = useToast();
  const [filter, setFilter] = useState('all');
  const [open, setOpen] = useState(false);

  // حقول النموذج
  const [title, setTitle] = useState('');
  const [type, setType] = useState(MEET_TYPES_FULL[0]);
  const [prio, setPrio] = useState('عادية');
  const [conf, setConf] = useState('عادي');
  const [dur, setDur] = useState('');
  const [participants, setParticipants] = useState<string[]>([]);
  const [day, setDay] = useState('');
  const [time, setTime] = useState('10:00');
  const [clientId, setClientId] = useState<number | ''>('');
  const [caseRef, setCaseRef] = useState('');
  useEffect(() => { setCaseRef(''); }, [clientId]);

  const up = meetings.filter((m) => m.status === 'قادم').length;
  const live = meetings.filter((m) => m.status === 'جارٍ').length;
  const done = meetings.filter((m) => m.status === 'منتهٍ');
  const att = done.length ? Math.round(done.reduce((a, m) => a + (m.attend || 0), 0) / done.length) : 0;

  const stats: StatItem[] = [
    ['t-blue', 'video', up, 'اجتماعات قادمة'],
    ['t-amber', 'video', live, 'جارية الآن'],
    ['t-cyan', 'user', `${att}%`, 'نسبة الحضور'],
    ['t-green', 'check', '78%', 'تنفيذ القرارات'],
    ['t-grey', 'clock', '52 د', 'متوسط المدة'],
    ['t-blue', 'folder', meetings.length, 'إجمالي الاجتماعات'],
  ];

  const list = meetings.filter((m) => filter === 'all' || m.status === filter);
  const caseOptions = clients.find((c) => c.id === clientId)?.items ?? [];

  const applyTpl = (name: string, t: string) => {
    setTitle(name); setType(t);
    toast('تم تطبيق قالب: ' + name);
  };

  const submit = () => {
    if (!title.trim()) { toast('أدخل عنوان الاجتماع'); return; }
    router.post('/admin/meetings', {
      title, type, priority: prio, conf, dur,
      participants: participants.join('، '),
      day, time,
      client_id: clientId === '' ? null : clientId,
      case_ref: caseRef,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setOpen(false); setFilter('قادم');
        setTitle(''); setDur(''); setParticipants([]);
        toast('تم إنشاء الاجتماع بجلسة Zoom وإضافته للتقويم');
      },
    });
  };

  return (
    <>
      <div className="greet">
        <h2>إدارة الاجتماعات</h2>
        <p>لوحة موحّدة لجميع الاجتماعات: الجدولة، الفريق القانوني، القرارات والمهام، والحماية.</p>
      </div>

      <StatRow items={stats} />

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10, margin: '6px 0 4px' }}>
        <div />
        <button className="btn" onClick={() => setOpen(true)} type="button"><Icon name="video" /> إنشاء اجتماع جديد</button>
      </div>

      <div className="mtabs">
        {MEET_STATUSES.map((t) => (
          <button key={t[0]} className={`mtab${filter === t[0] ? ' on' : ''}`} onClick={() => setFilter(t[0])} type="button">{t[1]}</button>
        ))}
      </div>

      <div className="card">
        <div className="card-h"><h3>الاجتماعات</h3><span className="sub">{list.length} اجتماع</span></div>
        <div className="card-b">
          {list.length ? list.map((m) => (
            <div key={m.id} className="item">
              <div className="iico"><Icon name="video" /></div>
              <div className="imeta">
                <b>{m.title}{m.conf === 'سري' && <> <Icon name="lock" /></>}</b>
                <span style={{ display: 'block', marginTop: 3 }}>{m.type} · {m.when} · {m.dur}</span>
              </div>
              <div className="iact">
                <span className={`mq-priority ${m.priority}`}>{m.priority}</span>
                <Badge text={m.status} tone={meetStatusTone(m.status)} />
                <button className="btn soft sm" onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)} type="button">
                  <Icon name="out" /> فتح الصفحة
                </button>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="video" /><b>لا اجتماعات في هذه الحالة</b></div>
          )}
        </div>
      </div>

      <Modal title="إنشاء اجتماع جديد" open={open} onClose={() => setOpen(false)}>
        <div className="presets">
          <span style={{ fontSize: 12, color: 'var(--muted)', alignSelf: 'center' }}>قالب جاهز:</span>
          {MEET_TEMPLATES.map((t) => (
            <button key={t[0]} className="preset-btn" onClick={() => applyTpl(t[0], t[1])} type="button">{t[0]}</button>
          ))}
        </div>
        <div className="form-sec-h"><span className="si"><Icon name="video" /></span> معلومات الاجتماع</div>
        <div className="field"><label>عنوان الاجتماع</label><input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="عنوان الاجتماع" /></div>
        <div className="picker-grid">
          <div className="field"><label>نوع الاجتماع</label>
            <select value={type} onChange={(e) => setType(e.target.value)}>{MEET_TYPES_FULL.map((t) => <option key={t}>{t}</option>)}</select>
          </div>
          <div className="field"><label>الأولوية</label>
            <select value={prio} onChange={(e) => setPrio(e.target.value)}><option>عادية</option><option>متوسطة</option><option>عالية</option></select>
          </div>
        </div>
        <div className="picker-grid">
          <div className="field"><label>مستوى السرية</label>
            <select value={conf} onChange={(e) => setConf(e.target.value)}><option>عادي</option><option>سري</option></select>
          </div>
          <div className="field"><label>المدة</label><input className="input" value={dur} onChange={(e) => setDur(e.target.value)} placeholder="مثال: 60 دقيقة" /></div>
        </div>

        <div className="form-sec-h"><span className="si"><Icon name="user" /></span> المشاركون</div>
        <div className="field">
          <label>الموظفون / المحامون المشاركون (من المسجّلين)</label>
          <select
            multiple
            size={4}
            style={{ height: 'auto', padding: 8 }}
            value={participants}
            onChange={(e) => setParticipants([...e.target.selectedOptions].map((o) => o.value))}
          >
            {STAFF_DIR.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 5 }}>اختر اسماً أو أكثر (Ctrl/⌘ للتعدد)</div>
        </div>

        <div className="form-sec-h"><span className="si"><Icon name="cal" /></span> الموعد</div>
        <div className="picker-grid">
          <div className="field"><label>التاريخ</label><input className="input" type="date" value={day} onChange={(e) => setDay(e.target.value)} /></div>
          <div className="field"><label>من</label><input className="input" type="time" value={time} onChange={(e) => setTime(e.target.value)} /></div>
        </div>

        <div className="form-sec-h"><span className="si"><Icon name="link" /></span> ربط الاجتماع</div>
        <div className="picker-grid">
          <div className="field"><label>العميل (من المسجّلين)</label>
            <select value={clientId} onChange={(e) => setClientId(e.target.value === '' ? '' : Number(e.target.value))}>
              <option value="">— داخلي (بلا عميل) —</option>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div className="field"><label>قضية / استشارة العميل</label>
            <select value={caseRef} onChange={(e) => setCaseRef(e.target.value)}>
              <option value="">— اختر قضية/استشارة —</option>
              {caseOptions.map((i) => <option key={i} value={i}>{i}</option>)}
            </select>
          </div>
        </div>
        <div className="action-hint" style={{ margin: '10px 0' }}>
          <Icon name="cal" /> يُنشأ اجتماع Zoom تلقائياً ويصل رابطه للعميل المرتبط في إشعاراته واجتماعاته.
        </div>
        <button className="btn block" onClick={submit} type="button"><Icon name="check" /> إنشاء الاجتماع وإضافته للتقويم</button>
      </Modal>
    </>
  );
};

export default AdminMeetMgmt;
