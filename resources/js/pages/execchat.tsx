import { router } from '@inertiajs/react';
import React from 'react';
import DetailShell from '@/components/babylon/DetailShell';
import ChatThread from '@/components/babylon/ChatThread';
import { type Message } from '@/lib/chat';

// يطابق clientExecView في index (82).html — البيانات والرسائل من قاعدة البيانات

interface ExecCard { no: string; subject: string; status: string; tone: string; last: string; }

const ExecChat: React.FC<{ exec: ExecCard; messages: Message[] }> = ({ exec: e, messages }) => {
  const send = (text: string) => {
    router.post(`/execs/${encodeURIComponent(e.no)}/messages`, { body: text }, { preserveScroll: true });
  };

  return (
    <DetailShell
      backHref="/execs"
      backLabel="رجوع"
      title={`طلب التنفيذ ${e.no}`}
      no={e.no}
      status={e.status}
      tone={e.tone}
      info={[
        ['الموضوع', e.subject],
        ['الحالة', e.status],
        ['آخر إجراء', e.last],
      ]}
    >
      <ChatThread initial={messages} onSend={send} placeholder="اكتب رسالتك للفريق القانوني…" />
    </DetailShell>
  );
};

export default ExecChat;
