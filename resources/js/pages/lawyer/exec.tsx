import { Link, router } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { EXEC_LIFE, execStage, type Procedure, ProceduresCard, ExecMsgRow } from '@/lib/exec-ui';
import { type Message } from '@/lib/chat';

interface ExecInfo { no: string; client: string; subject: string; lawyer: string; court?: string | null; status: string; tone: string; last?: string | null; }
interface Props { exec: ExecInfo; channel: string; messages: Message[]; procedures: Procedure[]; }

const PROC_TYPES = ['حجز', 'تحصيل', 'إخطار', 'إجراء'];

const LawyerExec: React.FC<Props> = ({ exec: e, channel, messages, procedures }) => {
  const toast = useToast();
  const base = `/lawyer/execs/${encodeURIComponent(e.no)}`;
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [live, setLive] = useState({ status: e.status, tone: e.tone });
  const [court, setCourt] = useState('');
  const [proc, setProc] = useState({ title: '', type: 'حجز', detail: '' });
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  useEffect(() => {
    const ch = echo.private(channel);
    ch.listen('.message', (ev: { message: Message }) => {
      const m = ev.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setMsgs((prev) => [...prev, m]);
    });
    ch.listen('.status', (ev: { status: string; tone: string }) => setLive({ status: ev.status, tone: ev.tone }));
    return () => { echo.leave(channel); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  const post = (url: string, data: Record<string, string>, msg: string) =>
    router.post(url, data, { preserveScroll: true, onSuccess: () => toast(msg) });

  const s = live.status;

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/lawyer/execs" className="btn soft sm"><Icon name="reply" /> رجوع لطلبات التنفيذ</Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>مسار طلب التنفيذ {e.no}</h3><Badge text={live.status} tone={live.tone} /></div>
        <div className="card-b" style={{ padding: '16px 18px' }}><FlowLine steps={EXEC_LIFE} cur={execStage(live.status)} /></div>
      </div>

      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h"><h3>محادثة طلب التنفيذ</h3></div>
            <div className="thread">{msgs.map((m, i) => <ExecMsgRow key={m.id ?? i} m={m} />)}</div>
          </div>

          {/* إضافة إجراء تنفيذ */}
          {(s === 'مقيّد لدى محكمة التنفيذ' || s === 'جارٍ') && (
            <div className="card" style={{ marginTop: 16 }}>
              <div className="card-h"><h3>إجراء تنفيذ جديد</h3></div>
              <div className="card-b" style={{ padding: 16 }}>
                <div className="picker-grid">
                  <div className="field"><label>عنوان الإجراء</label><input className="input" value={proc.title} onChange={(ev) => setProc({ ...proc, title: ev.target.value })} placeholder="حجز تحفظي على الحسابات" /></div>
                  <div className="field"><label>النوع</label><select value={proc.type} onChange={(ev) => setProc({ ...proc, type: ev.target.value })}>{PROC_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}</select></div>
                </div>
                <div className="field"><label>تفاصيل (اختياري)</label><input className="input" value={proc.detail} onChange={(ev) => setProc({ ...proc, detail: ev.target.value })} /></div>
                <button className="btn" type="button" onClick={() => { if (!proc.title.trim()) { toast('أدخل عنوان الإجراء'); return; } post(`${base}/procedures`, proc, 'تمت إضافة الإجراء'); setProc({ title: '', type: 'حجز', detail: '' }); }}>
                  <Icon name="exec" /> إضافة الإجراء
                </button>
              </div>
            </div>
          )}
        </div>

        <aside className="tf-aside">
          {/* أزرار المراحل */}
          <div className="card">
            <div className="card-h"><h3>إجراء المرحلة</h3></div>
            <div className="card-b" style={{ padding: 14 }}>
              {(s === 'جديد' || s === 'قيد الفتح') && (
                <button className="btn sm" type="button" onClick={() => post(`${base}/instrument`, {}, 'بدء تجهيز السند')}>
                  <Icon name="doc" /> تجهيز السند التنفيذي
                </button>
              )}
              {s === 'تجهيز السند التنفيذي' && (
                <>
                  <div className="field"><label>محكمة التنفيذ / رقم القيد</label><input className="input" value={court} onChange={(ev) => setCourt(ev.target.value)} placeholder="محكمة التنفيذ بالرياض — قيد 12345" /></div>
                  <button className="btn sm" type="button" onClick={() => { if (!court.trim()) { toast('أدخل محكمة/رقم القيد'); return; } post(`${base}/court`, { court }, 'تم القيد لدى المحكمة'); }}>
                    <Icon name="scale" /> القيد لدى محكمة التنفيذ
                  </button>
                </>
              )}
              {s === 'جارٍ' && (
                <button className="btn sm" type="button" onClick={() => post(`${base}/complete`, {}, 'تم إكمال التنفيذ')}>
                  <Icon name="check" /> التحصيل وإكمال التنفيذ
                </button>
              )}
              {(s === 'مكتمل' || s === 'مغلق') && <div className="empty"><Icon name="check" /><b>اكتمل التنفيذ</b></div>}
            </div>
          </div>

          {/* تحديث الإجراءات */}
          {procedures.some((p) => p.status === 'مجدول') && (
            <div className="card">
              <div className="card-h"><h3>تحديث الإجراءات</h3></div>
              <div className="card-b">
                {procedures.filter((p) => p.status === 'مجدول').map((p) => (
                  <div key={p.id} className="item">
                    <div className="imeta"><b>{p.title}</b><span>{p.type}</span></div>
                    <div className="iact" style={{ gap: 6 }}>
                      <button className="btn soft sm" type="button" onClick={() => post(`${base}/procedures/${p.id}`, { status: 'منفّذ' }, 'تم التحديث')}>منفّذ</button>
                      <button className="btn soft sm" type="button" onClick={() => post(`${base}/procedures/${p.id}`, { status: 'مؤجل' }, 'تم التحديث')}>مؤجل</button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          <ProceduresCard procedures={procedures} />

          <div className="card">
            <div className="tc-top"><div className="lbl">الطلب</div><div className="num">{e.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{e.client}</span></div>
              <div className="tc-row"><span className="k">الموضوع</span><span className="v">{e.subject}</span></div>
              <div className="tc-row"><span className="k">محكمة التنفيذ</span><span className="v">{e.court ?? '—'}</span></div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default LawyerExec;
