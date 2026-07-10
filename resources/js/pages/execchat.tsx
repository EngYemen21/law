import axios from 'axios';
import React, { useState } from 'react';
import DetailShell from '@/components/babylon/DetailShell';
import ChatThread from '@/components/babylon/ChatThread';
import FlowLine from '@/components/babylon/FlowLine';
import Badge from '@/components/babylon/Badge';
import { EXEC_LIFE, execStage, type Procedure, ProceduresCard } from '@/lib/exec-ui';
import { type Message } from '@/lib/chat';

// يطابق clientExecView — مسار التنفيذ + الإجراءات + محادثة يقودها الذكاء + بثّ لحظي

interface ExecCard { no: string; subject: string; status: string; tone: string; last: string; court?: string | null; }
interface Props { exec: ExecCard; channel: string; messages: Message[]; procedures: Procedure[]; }

const ExecChat: React.FC<Props> = ({ exec: e, channel, messages, procedures }) => {
  const [status, setStatus] = useState({ status: e.status, tone: e.tone });
  const send = (text: string) => {
    axios.post(`/execs/${encodeURIComponent(e.no)}/messages`, { body: text });
  };

  const topExtra = (
    <>
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>طلب التنفيذ {e.no}</h3><Badge text={status.status} tone={status.tone} /></div>
        <div className="card-b" style={{ padding: '16px 18px' }}><FlowLine steps={EXEC_LIFE} cur={execStage(status.status)} /></div>
      </div>
      {procedures.length > 0 && <div style={{ marginBottom: 16 }}><ProceduresCard procedures={procedures} /></div>}
    </>
  );

  return (
    <DetailShell
      backHref="/execs"
      backLabel="رجوع"
      title={`طلب التنفيذ ${e.no}`}
      no={e.no}
      status={status.status}
      tone={status.tone}
      info={[
        ['الموضوع', e.subject],
        ['الحالة', status.status],
        ['محكمة التنفيذ', e.court || '—'],
        ['آخر إجراء', e.last || '—'],
      ]}
      topExtra={topExtra}
    >
      <ChatThread initial={messages} channel={channel} onSend={send} onStatus={setStatus} placeholder="اكتب رسالتك لفريق التنفيذ…" />
    </DetailShell>
  );
};

export default ExecChat;
