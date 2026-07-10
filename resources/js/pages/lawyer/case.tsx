import { Link, router } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { CASE_LIFE, caseStage, type Hearing, HearingsCard, CaseMsgRow } from '@/lib/case-ui';
import { type Message } from '@/lib/chat';

interface CaseInfo {
  no: string; client: string; type: string; dept: string; lawyer: string;
  status: string; tone: string; next?: string | null; pleadingStatus: string; ruling?: string | null;
}
interface Props { case: CaseInfo; channel: string; messages: Message[]; hearings: Hearing[]; convertedExec?: boolean; }

const LawyerCase: React.FC<Props> = ({ case: c, channel, messages, hearings, convertedExec }) => {
  const toast = useToast();
  const base = `/lawyer/cases/${encodeURIComponent(c.no)}`;
  const [h, setH] = useState({ title: '', day: '', time: '', court: '' });
  const [ruling, setRuling] = useState('');
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [live, setLive] = useState({ status: c.status, tone: c.tone });
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  useEffect(() => {
    const ch = echo.private(channel);
    ch.listen('.message', (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setMsgs((prev) => [...prev, m]);
    });
    ch.listen('.status', (e: { status: string; tone: string }) => setLive({ status: e.status, tone: e.tone }));
    return () => { echo.leave(channel); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  const approvePleading = () => router.post(`${base}/pleading`, {}, { preserveScroll: true, onSuccess: () => toast('تم اعتماد اللائحة') });
  const addHearing = (e: React.FormEvent) => {
    e.preventDefault();
    if (!h.title.trim() || !h.day.trim()) { toast('أدخل عنوان الجلسة واليوم'); return; }
    router.post(`${base}/hearings`, h, { preserveScroll: true, onSuccess: () => { setH({ title: '', day: '', time: '', court: '' }); toast('تمت جدولة الجلسة'); } });
  };
  const recordHearing = (id: number, status: string) =>
    router.post(`${base}/hearings/${id}`, { status }, { preserveScroll: true, onSuccess: () => toast('تم تحديث الجلسة') });
  const recordRuling = (e: React.FormEvent) => {
    e.preventDefault();
    if (!ruling.trim()) { toast('أدخل منطوق الحكم'); return; }
    router.post(`${base}/ruling`, { ruling }, { preserveScroll: true, onSuccess: () => { setRuling(''); toast('تم تسجيل الحكم'); } });
  };
  const convertToExec = () =>
    router.post(`${base}/execute`, {}, { onSuccess: () => toast('تم فتح طلب تنفيذ الحكم') });

  const active = c.status === 'منظورة';

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/lawyer/cases" className="btn soft sm"><Icon name="reply" /> رجوع لقضاياي</Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>مسار القضية {c.no}</h3><Badge text={live.status} tone={live.tone} /></div>
        <div className="card-b" style={{ padding: '16px 18px' }}><FlowLine steps={CASE_LIFE} cur={caseStage(live.status)} /></div>
      </div>

      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h"><h3>محادثة القضية</h3></div>
            <div className="thread">{msgs.map((m, i) => <CaseMsgRow key={m.id ?? i} m={m} />)}</div>
          </div>

          {/* جدولة الجلسات */}
          {active && (
            <div className="card" style={{ marginTop: 16 }}>
              <div className="card-h"><h3>جدولة جلسة</h3></div>
              <div className="card-b" style={{ padding: 16 }}>
                <form onSubmit={addHearing}>
                  <div className="picker-grid">
                    <div className="field"><label>عنوان الجلسة</label><input className="input" value={h.title} onChange={(e) => setH({ ...h, title: e.target.value })} placeholder="الجلسة الأولى" /></div>
                    <div className="field"><label>اليوم</label><input className="input" value={h.day} onChange={(e) => setH({ ...h, day: e.target.value })} placeholder="الخميس 02 يوليو" /></div>
                  </div>
                  <div className="picker-grid">
                    <div className="field"><label>الوقت</label><input className="input" value={h.time} onChange={(e) => setH({ ...h, time: e.target.value })} placeholder="10:00 ص" /></div>
                    <div className="field"><label>الدائرة</label><input className="input" value={h.court} onChange={(e) => setH({ ...h, court: e.target.value })} placeholder="الدائرة التجارية الأولى" /></div>
                  </div>
                  <button className="btn" type="submit"><Icon name="cal" /> جدولة الجلسة</button>
                </form>
              </div>
            </div>
          )}

          {/* تسجيل الحكم */}
          {active && (
            <div className="card" style={{ marginTop: 16 }}>
              <div className="card-h"><h3>تسجيل الحكم</h3></div>
              <div className="card-b" style={{ padding: 16 }}>
                <form onSubmit={recordRuling}>
                  <textarea value={ruling} onChange={(e) => setRuling(e.target.value)} placeholder="منطوق الحكم…" />
                  <div className="crow"><button className="btn" type="submit"><Icon name="scale" /> تسجيل الحكم</button></div>
                </form>
              </div>
            </div>
          )}
        </div>

        <aside className="tf-aside">
          {/* اعتماد اللائحة */}
          {c.pleadingStatus === 'pending_lawyer' && (
            <div className="card">
              <div className="card-h"><h3>اعتماد اللائحة</h3><Badge text="بانتظار اعتمادك" tone="b-amber" /></div>
              <div className="card-b" style={{ padding: 14 }}>
                <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>راجع مسودة لائحة الدعوى في المحادثة ثم اعتمدها لرفع الدعوى.</div>
                <button className="btn sm" type="button" onClick={approvePleading}><Icon name="check" /> اعتماد اللائحة ورفع الدعوى</button>
              </div>
            </div>
          )}

          {/* إدارة الجلسات */}
          {hearings.length > 0 && (
            <div className="card">
              <div className="card-h"><h3>تحديث الجلسات</h3></div>
              <div className="card-b">
                {hearings.map((hr) => (
                  <div key={hr.id} className="item">
                    <div className="imeta"><b>{hr.title}</b><span>{hr.day} · {hr.status}</span></div>
                    {hr.status === 'مجدولة' && (
                      <div className="iact" style={{ gap: 6 }}>
                        <button className="btn soft sm" type="button" onClick={() => recordHearing(hr.id, 'منعقدة')}>منعقدة</button>
                        <button className="btn soft sm" type="button" onClick={() => recordHearing(hr.id, 'مؤجلة')}>مؤجلة</button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {(live.status === 'صدر الحكم' || convertedExec) && (
            <div className="card">
              <div className="card-h"><h3>تنفيذ الحكم</h3>{convertedExec && <Badge text="محوّل لتنفيذ" tone="b-cyan" />}</div>
              <div className="card-b" style={{ padding: 14 }}>
                {convertedExec ? (
                  <div className="empty"><Icon name="exec" /><b>فُتح طلب تنفيذ لهذا الحكم</b></div>
                ) : (
                  <>
                    <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>صدر الحكم. يمكنك فتح طلب تنفيذ لتحصيل الحق لدى محكمة التنفيذ.</div>
                    <button className="btn sm" type="button" onClick={convertToExec}><Icon name="exec" /> فتح طلب تنفيذ الحكم</button>
                  </>
                )}
              </div>
            </div>
          )}

          <HearingsCard hearings={hearings} />

          <div className="card">
            <div className="tc-top"><div className="lbl">القضية</div><div className="num">{c.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{c.client}</span></div>
              <div className="tc-row"><span className="k">النوع</span><span className="v">{c.type}</span></div>
              <div className="tc-row"><span className="k">القسم</span><span className="v">{c.dept}</span></div>
              <div className="tc-row"><span className="k">الجلسة القادمة</span><span className="v">{c.next ?? '—'}</span></div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default LawyerCase;
