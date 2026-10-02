import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import MsgMeta from '@/components/babylon/MsgMeta';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import {
  type Message,
  ALLOWED_DOC_ACCEPT,
  ALLOWED_DOC_HINT,
  ATTACH_POOL,
  CLIENT_NAME,
  ackMessage,
  attachMessage,
  nowClock,
} from '@/lib/chat';
import { serverMessage } from '@/lib/server-message';

// يطابق ctRenderMsg + metaLine
const MsgRow: React.FC<{ m: Message; staffNotes?: boolean }> = ({ m, staffNotes = false }) => {
  if (m.who === 'note') {
    // الملاحظة الداخليّة للطاقم وحده (`staffNotes`) — بشكلها في محادثة التذكرة عند الطاقم. والعميل لا تصله أصلاً:
    // الخادم يحجبها من حمولته (`Execution::flowMessages`) وقناتها `.staff` لا يُصرَّح له بها.
    if (!staffNotes) return null;

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
  const isAuto = m.who === 'ai';

  // **الاسم من الخادم لا من هنا** (`ChatSenderLabel`): الإدارة تضبط من الإعدادات باسم مَن يظهر
  // الموظّف والمحامي والإدارة والذكاء الاصطناعي للعميل (طلب المالك 2026-09-25). كانت التسميتان
  // «الفريق القانوني» و«خدمة العملاء» منقوشتين هنا فتغييرهما يلزمه نشرُ كود.
  //
  // والردّ الآليّ باسمه الذي ضبطته الإدارة **وحده** بلا وسم «ردّ آليّ» — قرار المالك. واسمه المستقلّ
  // عن اسم الموظّف يبقي المصدر صادقاً: العميل لا يقرأ ردّاً آليّاً باسم الفريق البشريّ.
  const name = isClient ? 'أنت' : m.name || 'الفريق القانوني';
  const role = isClient || isAuto ? '' : m.role || '';

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
        <MsgMeta m={m} />
      </div>
    </div>
  );
};

interface ChatThreadProps {
  initial: Message[];
  placeholder?: string;
  // وضع الخادم: عند تمريرها تُحفظ الرسائل عبر الخادم بدل المحاكاة المحلية.
  // إن أعادت وعداً، يُنتظر: يُستعاد النصّ ويُعرض خطأ عند الرفض بدل ضياع الرسالة صامتةً.
  onSend?: (text: string) => void | Promise<unknown>;
  onAttach?: (file?: File) => void | Promise<unknown>;
  // وضع البثّ اللحظي: اسم قناة Reverb (مثل ticket.5) — مزامنة بلا إعادة تحميل
  channel?: string;
  // تحديث حالة التذكرة لحظياً (تقدّم مسار المعالجة)
  /** حمولة بثّ الحالة كما هي — `status`/`tone` ومعها أعلام النوع (`isActive` · `isTerminal`…). */
  onStatus?: (s: { status: string; tone: string } & Record<string, unknown>) => void;
  // للقراءة فقط: تُخفى منطقة الكتابة/الإرفاق (سجلّ مغلق — مثل قضية مغلقة/مؤرشفة)
  readOnly?: boolean;
  // تجاوز صيغ/تلميح الإرفاق الافتراضيّين (مثال: لتضمين XLSX في محادثة التنفيذ)
  accept?: string;
  hint?: string;
  // ملصق منطقة الكتابة — التذكرة تمرّر «اكتب في التذكرة:» (يطابق المرجع)
  composerLabel?: string;
  /**
   * **عرضُ الملاحظات الداخليّة واستقبالها لحظيّاً — للطاقم وحده** (ملاحظة المالك 2026-10-02): محادثة التنفيذ عند
   * الطاقم تمرّ بهذا المكوّن المشترك، وكان يُخفي الملاحظة ولا يستمع لقناة `.staff` — فلا يرى المحامي ملاحظة
   * «تحليل — بانتظار اعتماد المحامي» لا لحظيّاً ولا بعد التحديث. وصفحات القضيّة والتذكرة تعرضها بمكوّناتها.
   */
  staffNotes?: boolean;
}

// يطابق سلوك ctSend / ctAttach مع مؤشر الكتابة والرد التلقائي
const ChatThread: React.FC<ChatThreadProps> = ({ initial, placeholder = 'اكتب رسالتك لخدمة العملاء…', onSend, onAttach, channel, onStatus, readOnly = false, accept = ALLOWED_DOC_ACCEPT, hint = ALLOWED_DOC_HINT, composerLabel = 'اكتب هنا:', staffNotes = false }) => {
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
    const append = (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setLive((prev) => [...prev, m]);
      if (m.who === 'ai' || m.who === 'staff' || m.who === 'lawyer') setTyping(false);
    };
    ch.listen('.message', append);
    ch.listen('.status', (e: { status: string; tone: string }) => onStatus?.(e));
    // الملاحظات الداخليّة تُبثّ على قناة الطاقم وحدها (`RecordsSender::broadcastChannelName`)
    const staffChannel = `${channel}.staff`;
    if (staffNotes) {
      echo.private(staffChannel).listen('.message', append);
    }
    return () => {
      echo.leave(channel);
      if (staffNotes) {
        echo.leave(staffChannel);
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel, staffNotes]);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, [messages, typing]);

  // فشل الإرسال كان صامتاً والنصّ يُمسح على أي حال، فتضيع رسالة العميل بلا أثر
  // السبب من الخادم إن جاء (`serverMessage`) — لا «تعذّر» مجرّدةً تُخفي ما يصحّحه المستخدم
  const failed = (restore: string, msg: string) => (err?: unknown): void => {
    setReply(restore);
    setTyping(false);
    toast(`⚠️ ${serverMessage(err, msg)}`);
  };

  const send = () => {
    const v = reply.trim();
    if (!v) return;
    if (liveMode) {
      setReply('');
      setTyping(true);
      Promise.resolve(onSend?.(v)).catch(failed(v, 'تعذّر إرسال الرسالة، حاول مجدداً'));

      return;
    }
    if (serverMode) {
      setReply('');
      Promise.resolve(onSend!(v)).catch(failed(v, 'تعذّر إرسال الرسالة، حاول مجدداً'));

      return;
    }
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
    if (f) Promise.resolve(onAttach?.(f)).catch((err: unknown) => toast(`⚠️ ${serverMessage(err, 'تعذّر رفع المستند، حاول مجدداً')}`));
    e.target.value = '';
  };

  return (
    <>
      <div className="thread">
        {messages.map((m, i) => <MsgRow key={i} m={m} staffNotes={staffNotes} />)}
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
      {readOnly ? (
        <div className="composer" style={{ textAlign: 'center', color: 'var(--muted)', fontSize: 12.5, fontWeight: 700 }}>
          انتهت هذه المحادثة — السجلّ متاح للاطّلاع فقط.
        </div>
      ) : (
        <div className="composer">
          <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 8 }}>
            {composerLabel}
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
            {onAttach && (
              <button className="btn soft" onClick={attach} type="button">
                <Icon name="upload" /> إرفاق مستند
              </button>
            )}
            <input ref={fileRef} type="file" hidden accept={accept} onChange={onFilePicked} />
          </div>
          <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 6 }}>{hint}</div>
        </div>
      )}
    </>
  );
};

export default ChatThread;
