import { Link } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { echo } from '@/lib/echo';
import { EXEC_LIFE, execStage, type Procedure, ProceduresCard, ExecMsgRow } from '@/lib/exec-ui';
import { type Message } from '@/lib/chat';

interface ExecInfo { no: string; client: string; subject: string; lawyer: string; court?: string | null; status: string; tone: string; last?: string | null; }
interface Props { exec: ExecInfo; channel: string; messages: Message[]; procedures: Procedure[]; }

const EmployeeExec: React.FC<Props> = ({ exec: e, channel, messages, procedures }) => {
  const [reply, setReply] = useState('');
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [live, setLive] = useState({ status: e.status, tone: e.tone });
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

  const send = (ev: React.FormEvent) => {
    ev.preventDefault();
    const v = reply.trim(); if (!v) return;
    axios.post(`/employee/execs/${encodeURIComponent(e.no)}/reply`, { body: v }).then(() => setReply(''));
  };

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/employee/execs" className="btn soft sm"><Icon name="reply" /> رجوع لطلبات التنفيذ</Link>
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
            <div className="composer">
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 8 }}>ردّ للعميل (خدمة العملاء):</div>
              <form onSubmit={send}>
                <textarea value={reply} onChange={(ev) => setReply(ev.target.value)} placeholder="اكتب ردّك للعميل…" />
                <div className="crow"><button className="btn" type="submit"><Icon name="send" /> إرسال</button></div>
              </form>
            </div>
          </div>
        </div>
        <aside className="tf-aside">
          <ProceduresCard procedures={procedures} />
          <div className="card">
            <div className="tc-top"><div className="lbl">الطلب</div><div className="num">{e.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{e.client}</span></div>
              <div className="tc-row"><span className="k">الموضوع</span><span className="v">{e.subject}</span></div>
              <div className="tc-row"><span className="k">المحامي</span><span className="v">{e.lawyer}</span></div>
              <div className="tc-row"><span className="k">محكمة التنفيذ</span><span className="v">{e.court ?? '—'}</span></div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default EmployeeExec;
