import { Link } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import MsgMeta from '@/components/babylon/MsgMeta';
import { echo } from '@/lib/echo';
import { TICKET_STATES, STATE_TONES } from '@/lib/employee-data';
import { TKT_LIFE, tktStage, type Message } from '@/lib/chat';

// محادثة التذكرة (لوحة الموظف) — مزامنة لحظية مع العميل (Reverb) بلا إعادة تحميل

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; }

const MsgRow: React.FC<{ m: Message }> = ({ m }) => {
  if (m.who === 'note') {
    return (
      <div className="msg" style={{ justifyContent: 'center' }}>
        <div style={{ background: '#FBF1E0', border: '1px solid #F0DDB0', color: '#8a6d2f', borderRadius: 11, padding: '9px 13px', fontSize: 12.5, maxWidth: '85%' }}>
          <b>🔒 ملاحظة داخلية — {m.name}</b>
          <div style={{ marginTop: 4 }} dangerouslySetInnerHTML={{ __html: m.text }} />
          <time style={{ display: 'block', marginTop: 4, color: '#b08d4a', fontSize: 11 }}>{m.time}</time>
        </div>
      </div>
    );
  }
  const isClient = m.who === 'client' || m.who === 'me';
  const actor = isClient ? 'me' : 'ai';
  return (
    <div className={`msg ${actor}`}>
      <div className={`av ${actor}`}>{isClient ? 'ع' : <img src="/images/mono.jpg" alt="" />}</div>
      <div className="bubble-wrap">
        <div className="who">
          <b>{isClient ? 'العميل' : m.name}</b>
          {m.role && <span className={`role ${actor}`}>{m.role}</span>}
          <time>{m.time}</time>
        </div>
        <div className="bubble" dangerouslySetInnerHTML={{ __html: m.text }} />
        <MsgMeta m={m} />
      </div>
    </div>
  );
};

const EmployeeTicketChat: React.FC<{ ticket: EmpTicket; channel: string; messages: Message[] }> = ({ ticket, channel, messages }) => {
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [status, setStatus] = useState({ status: ticket.status, tone: ticket.tone });
  const [reply, setReply] = useState('');
  const [note, setNote] = useState('');
  const endRef = useRef<HTMLDivElement>(null);
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  // الاشتراك في قناة التذكرة + قناة الملاحظات الداخلية + تحديثات الحالة
  useEffect(() => {
    const append = (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setMsgs((prev) => [...prev, m]);
    };
    const ch = echo.private(channel);
    ch.listen('.message', append);
    ch.listen('.status', (e: { status: string; tone: string }) => setStatus(e));
    echo.private(`${channel}.staff`).listen('.message', append);
    return () => { echo.leave(channel); echo.leave(`${channel}.staff`); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, [msgs]);

  const sendReply = (e: React.FormEvent) => {
    e.preventDefault();
    const v = reply.trim(); if (!v) return;
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/reply`, { body: v });
    setReply('');
  };
  const sendNote = (e: React.FormEvent) => {
    e.preventDefault();
    const v = note.trim(); if (!v) return;
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/note`, { body: v });
    setNote('');
  };
  const changeStatus = (s: string) => {
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/status`, { status: s, tone: STATE_TONES[s] ?? 'b-blue' });
  };
  const advance = () => {
    axios.post(`/employee/tickets/${encodeURIComponent(ticket.no)}/advance`);
  };
  const cur = tktStage(status.status);
  const isLast = cur >= TKT_LIFE.length - 1;
  // مراحل بيد المحامي/الإدارة/العميل — لا يتقدّم الموظف فيها
  const WAITING: Record<string, string> = {
    'بانتظار اعتماد المستشار': 'بانتظار اعتماد المستشار',
    'بانتظار حجز الاستشارة': 'بانتظار حجز العميل',
    'بانتظار حجز استشارة': 'بانتظار حجز العميل',
    'بانتظار اعتماد النتيجة': 'بانتظار اعتماد المستشار',
    'بانتظار اعتماد الإدارة': 'بانتظار اعتماد الإدارة',
  };
  const waiting = WAITING[status.status];

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/employee/tickets" className="btn soft sm"><Icon name="reply" /> رجوع لكل التذاكر</Link>
      </div>

      {/* مسار المعالجة + تنفيذ المرحلة التالية */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>مسار المعالجة</h3>
          {isLast
            ? <Badge text="مكتملة" tone="b-green" />
            : waiting
              ? <Badge text={waiting} tone="b-amber" />
              : <button className="btn sm" type="button" onClick={advance}><Icon name="check" /> تنفيذ المرحلة التالية: {TKT_LIFE[cur + 1]}</button>}
        </div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <FlowLine steps={TKT_LIFE} cur={cur} />
        </div>
      </div>

      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h">
              <h3>محادثة التذكرة {ticket.no}</h3>
              <Badge text={status.status} tone={status.tone} />
            </div>
            <div className="thread">
              {msgs.map((m, i) => <MsgRow key={m.id ?? i} m={m} />)}
              <div ref={endRef} />
            </div>

            <div className="composer">
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 8 }}>ردّ للعميل (يراه العميل):</div>
              <form onSubmit={sendReply}>
                <textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder="اكتب ردّك للعميل…" />
                <div className="crow">
                  <button className="btn" type="submit"><Icon name="send" /> إرسال الرد</button>
                </div>
              </form>

              <div style={{ fontSize: 12, fontWeight: 700, color: '#8a6d2f', margin: '12px 0 8px' }}>🔒 ملاحظة داخلية (لا يراها العميل):</div>
              <form onSubmit={sendNote}>
                <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="ملاحظة للفريق فقط…" />
                <div className="crow">
                  <button className="btn soft" type="submit"><Icon name="doc" /> حفظ ملاحظة</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <aside className="tf-aside">
          <div className="card">
            <div className="tc-top"><div className="lbl">التذكرة</div><div className="num">{ticket.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{ticket.client}</span></div>
              <div className="tc-row"><span className="k">النوع</span><span className="v">{ticket.type}</span></div>
              <div className="tc-row"><span className="k">القسم</span><span className="v">{ticket.dept}</span></div>
              <div className="tc-row"><span className="k">المحامي</span><span className="v">{ticket.lawyer}</span></div>
            </div>
          </div>

          <div className="card">
            <div className="card-h"><h3>تغيير الحالة</h3></div>
            <div className="card-b" style={{ padding: 14 }}>
              <select value={status.status} onChange={(e) => changeStatus(e.target.value)}>
                {TICKET_STATES.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};

export default EmployeeTicketChat;
