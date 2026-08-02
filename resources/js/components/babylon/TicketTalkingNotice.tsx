import { usePage } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import { echo } from '@/lib/echo';
import Icon from '@/lib/icons';

// منع الردّ المزدوج: يُنبّه الموظف **لحظة دخوله** المحادثة بأن زميلاً بداخلها الآن (سجلّ الحضور
// here/joining/leaving)، ويترقّى النصّ إلى «يكتب ردّاً» عند بثّ الزميل إشارة كتابة (whisper)
// على القناة نفسها. الإشارة تمرّ بين المتصفحات مباشرةً — بلا خادم ولا طابور ولا قاعدة بيانات.

interface Member { id: number; name: string; role: string }

interface Props {
  /** قناة التذكرة الأساسية (مثل ticket.5) — يُلحق بها ‎.presence */
  channel: string;
  /** الصفحة فيها صندوق ردّ للعميل → تبثّ إشارة الكتابة */
  canReply?: boolean;
  /** يتغيّر مع كل ضغطة في صندوق ردّ العميل (مصدر بثّ الكتابة) */
  typingSignal?: number;
}

const TYPING_TTL = 6000;   // إخفاء «يكتب» بعد توقّف الزميل
const WHISPER_GAP = 2000;  // تقييد البثّ (لا عند كل حرف)

const TicketTalkingNotice: React.FC<Props> = ({ channel, canReply = false, typingSignal = 0 }) => {
  const { props } = usePage() as unknown as { props: { auth?: { user?: { id?: number } } } };
  const selfId = props?.auth?.user?.id;

  const [others, setOthers] = useState<Member[]>([]);
  const [typingIds, setTypingIds] = useState<number[]>([]);
  const chRef = useRef<ReturnType<typeof echo.join> | null>(null);
  const lastWhisper = useRef(0);
  const timers = useRef<Record<number, ReturnType<typeof setTimeout>>>({});

  // الانضمام لقناة الحضور: here تُعطي من هم داخل المحادثة **لحظة الدخول** (جوهر التنبيه)
  useEffect(() => {
    const notSelf = (m: Member) => m.id !== selfId;
    const ch = echo.join(`${channel}.presence`);

    chRef.current = ch;

    ch.here((members: Member[]) => setOthers(members.filter(notSelf)))
      .joining((m: Member) => {
        if (notSelf(m)) {
          setOthers((o) => [...o.filter((x) => x.id !== m.id), m]);
        }
      })
      .leaving((m: Member) => {
        setOthers((o) => o.filter((x) => x.id !== m.id));
        setTypingIds((t) => t.filter((id) => id !== m.id));
      })
      .listenForWhisper('typing', (e: { id: number }) => {
        if (e.id === selfId) {
          return;
        }

        setTypingIds((t) => (t.includes(e.id) ? t : [...t, e.id]));
        clearTimeout(timers.current[e.id]);
        timers.current[e.id] = setTimeout(
          () => setTypingIds((t) => t.filter((id) => id !== e.id)),
          TYPING_TTL,
        );
      });

    return () => {
      Object.values(timers.current).forEach(clearTimeout);
      timers.current = {};
      echo.leave(`${channel}.presence`);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  // بثّ إشارة الكتابة (مقيّدة بمهلة) عند الكتابة في صندوق ردّ العميل فقط
  useEffect(() => {
    if (!canReply || !typingSignal || !chRef.current || !selfId) {
      return;
    }

    const now = Date.now();

    if (now - lastWhisper.current < WHISPER_GAP) {
      return;
    }

    lastWhisper.current = now;
    chRef.current.whisper('typing', { id: selfId });
  }, [typingSignal, canReply, selfId]);

  if (others.length === 0) {
    return null;
  }

  const typing = others.filter((o) => typingIds.includes(o.id));
  const shown = typing.length > 0 ? typing : others;
  const names = shown.map((o) => `${o.name} (${o.role})`).join('، ');

  return (
    <div className="ai-banner" style={{ marginBottom: 12 }}>
      <div className="ab"><Icon name="user" /></div>
      <p>
        {typing.length > 0 ? (
          <><b>{names}</b> يكتب ردّاً للعميل الآن — تجنّب الردّ المزدوج.</>
        ) : (
          <>العميل يتحدث حالياً مع <b>{names}</b> — تجنّب الردّ المزدوج.</>
        )}
      </p>
    </div>
  );
};

export default TicketTalkingNotice;
