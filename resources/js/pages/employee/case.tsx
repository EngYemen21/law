import { Link } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { echo } from '@/lib/echo';
import { CASE_LIFE, caseStage, type Hearing, HearingsCard, CaseMsgRow } from '@/lib/case-ui';
import { type Message } from '@/lib/chat';

interface CaseInfo { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; next?: string | null; }
interface Props { case: CaseInfo; channel: string; messages: Message[]; hearings: Hearing[]; }

const EmployeeCase: React.FC<Props> = ({ case: c, channel, messages, hearings }) => {
  const [reply, setReply] = useState('');
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

  const send = (e: React.FormEvent) => {
    e.preventDefault();
    const v = reply.trim(); if (!v) return;
    axios.post(`/employee/cases/${encodeURIComponent(c.no)}/reply`, { body: v }).then(() => setReply(''));
  };

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/employee/cases" className="btn soft sm"><Icon name="reply" /> رجوع للقضايا</Link>
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
            <div className="composer">
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 8 }}>ردّ للعميل (خدمة العملاء):</div>
              <form onSubmit={send}>
                <textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder="اكتب ردّك للعميل…" />
                <div className="crow"><button className="btn" type="submit"><Icon name="send" /> إرسال</button></div>
              </form>
            </div>
          </div>
        </div>
        <aside className="tf-aside">
          <HearingsCard hearings={hearings} />
          <div className="card">
            <div className="tc-top"><div className="lbl">القضية</div><div className="num">{c.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{c.client}</span></div>
              <div className="tc-row"><span className="k">النوع</span><span className="v">{c.type}</span></div>
              <div className="tc-row"><span className="k">المحامي</span><span className="v">{c.lawyer}</span></div>
              <div className="tc-row"><span className="k">الجلسة القادمة</span><span className="v">{c.next ?? '—'}</span></div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default EmployeeCase;
