import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import {
  type Message,
  ATTACH_POOL,
  CLIENT_NAME,
  ackMessage,
  attachMessage,
  cleanTime,
  msgIP,
  nowClock,
  todayDate,
} from '@/lib/chat';

// يطابق ctRenderMsg + metaLine
const MsgRow: React.FC<{ m: Message }> = ({ m }) => {
  if (m.who === 'note') return null;
  const isClient = m.who === 'client' || m.who === 'me';
  const actor = isClient ? 'me' : 'ai';
  const name = isClient ? 'أنت' : m.name || 'خدمة العملاء';
  const role = isClient ? '' : m.role || '';
  const ip = msgIP(m.who);

  return (
    <div className={`msg ${actor}`}>
      <div className={`av ${actor}`}>
        {isClient ? 'أنت' : <img src="/images/mono.jpg" alt="" />}
      </div>
      <div className="bubble-wrap">
        <div className="who">
          <b>{name}</b>
          {role && <span className={`role ${actor}`}>{role}</span>}
          <time>{m.time}</time>
        </div>
        <div className="bubble" dangerouslySetInnerHTML={{ __html: m.text }} />
        <div className="msg-meta">
          <span><Icon name="cal" />{todayDate()}</span>
          <span><Icon name="clock" />{cleanTime(m.time)}</span>
          <span><Icon name="pin" /><bdi>{ip}</bdi></span>
        </div>
      </div>
    </div>
  );
};

interface ChatThreadProps {
  initial: Message[];
  placeholder?: string;
  // وضع الخادم: عند تمريرها تُحفظ الرسائل عبر الخادم بدل المحاكاة المحلية
  onSend?: (text: string) => void;
  onAttach?: (file?: File) => void;
  // وضع البثّ اللحظي: اسم قناة Reverb (مثل ticket.5) — مزامنة بلا إعادة تحميل
  channel?: string;
  // تحديث حالة التذكرة لحظياً (تقدّم مسار المعالجة)
  onStatus?: (s: { status: string; tone: string }) => void;
}

// يطابق سلوك ctSend / ctAttach مع مؤشر الكتابة والرد التلقائي
const ChatThread: React.FC<ChatThreadProps> = ({ initial, placeholder = 'اكتب رسالتك لخدمة العملاء…', onSend, onAttach, channel, onStatus }) => {
  const toast = useToast();
  const serverMode = !!onSend;
  const liveMode = !!channel;
  const [local, setLocal] = useState<Message[]>(initial);
  const [live, setLive] = useState<Message[]>(initial);
  const [reply, setReply] = useState('');
  const [typing, setTyping] = useState(false);
  const [attachN, setAttachN] = useState(0);
  const endRef = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const seen = useRef<Set<number>>(new Set(initial.map((m) => m.id).filter(Boolean) as number[]));

  const messages = liveMode ? live : serverMode ? initial : local;

  // الاشتراك في قناة التذكرة (Reverb) وإلحاق الرسائل الواردة لحظياً
  useEffect(() => {
    if (!channel) return;
    const ch = echo.private(channel);
    ch.listen('.message', (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setLive((prev) => [...prev, m]);
      if (m.who === 'ai' || m.who === 'staff' || m.who === 'lawyer') setTyping(false);
    });
    ch.listen('.status', (e: { status: string; tone: string }) => onStatus?.(e));
    return () => { echo.leave(channel); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, [messages, typing]);

  const send = () => {
    const v = reply.trim();
    if (!v) return;
    if (liveMode) { onSend?.(v); setReply(''); setTyping(true); return; }
    if (serverMode) { onSend!(v); setReply(''); return; }
    const mine: Message = {
      who: 'client', name: CLIENT_NAME, role: 'العميل',
      text: v.replace(/</g, '&lt;'), time: nowClock(),
    };
    setLocal((m) => [...m, mine]);
    setReply('');
    toast('تم إرسال رسالتك إلى خدمة العملاء');
    setTyping(true);
    setTimeout(() => {
      setTyping(false);
      setLocal((m) => [...m, ackMessage()]);
    }, 1300);
  };

  const attach = () => {
    if (liveMode) { fileRef.current?.click(); return; } // فتح منتقي الملفات (رفع حقيقي)
    if (serverMode) { onAttach?.(); return; }
    const f = ATTACH_POOL[attachN % ATTACH_POOL.length];
    setAttachN((n) => n + 1);
    setLocal((m) => [...m, attachMessage(f)]);
    toast('تم إرفاق المستند');
  };
  const onFilePicked = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    if (f) onAttach?.(f);
    e.target.value = '';
  };

  return (
    <>
      <div className="thread">
        {messages.map((m, i) => <MsgRow key={i} m={m} />)}
        {typing && (
          <div className="msg ai">
            <div className="av ai"><img src="/images/mono.jpg" alt="" /></div>
            <div className="bubble-wrap">
              <div className="bubble">
                <span className="typing"><span /><span /><span /></span>
              </div>
            </div>
          </div>
        )}
        <div ref={endRef} />
      </div>
      <div className="composer">
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 8 }}>
          اكتب هنا:
        </div>
        <textarea
          value={reply}
          onChange={(e) => setReply(e.target.value)}
          placeholder={placeholder}
        />
        <div className="crow">
          <button className="btn" onClick={send} type="button">
            <Icon name="send" /> إرسال
          </button>
          <button className="btn soft" onClick={attach} type="button">
            <Icon name="upload" /> إرفاق مستند
          </button>
          <input ref={fileRef} type="file" hidden onChange={onFilePicked} />
        </div>
      </div>
    </>
  );
};

export default ChatThread;
