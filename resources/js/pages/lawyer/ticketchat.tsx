import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import ConversationHandlerCard, { refreshConversation, refreshConversationOn } from '@/components/babylon/ConversationHandlerCard';
import type { ConversationHistory } from '@/components/babylon/ConversationHandlerCard';
import FlowLine from '@/components/babylon/FlowLine';
import MsgMeta from '@/components/babylon/MsgMeta';
import TicketActionsPanel from '@/components/babylon/TicketActionsPanel';
import TicketDetailsCard from '@/components/babylon/TicketDetailsCard';
import TicketRequirementsCard from '@/components/babylon/TicketRequirementsCard';
import TicketTalkingNotice from '@/components/babylon/TicketTalkingNotice';
import TicketTrackDecisionCard, { TrackGovernanceData } from '@/components/babylon/TicketTrackDecisionCard';
import { useToast } from '@/components/babylon/Toast';
import { TKT_LIFE, tktStage  } from '@/lib/chat';
import type {Message} from '@/lib/chat';
import { echo } from '@/lib/echo';
import Icon from '@/lib/icons';
import type {SummaryData} from '@/lib/lawyer-data';
import { useCan } from '@/lib/permissions';

// دراسة التذكرة لدى المستشار — محادثة العميل (سياق حيّ + رد مباشر) + ملخص الملف + الاعتماد
// تُستخدم الصفحة نفسها من لوحة الإدارة؛ لذا كل الروابط تُبنى من base لا مثبّتة على /lawyer.

interface EmpTicket {
  no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string;
  caseRef?: string | null; execRef?: string | null; subject?: string | null; priority?: string | null; mobile?: string | null; openedAt?: string | null;
  isFrozen?: boolean;
  isTerminal?: boolean;
  closureReasonCode?: string | null;
  closureNotes?: string | null;
  hasCase?: boolean;
  caseNumber?: string | null;
  hasExecution?: boolean;
  executionNumber?: string | null;
  trackGovernance?: TrackGovernanceData | null;
}
/** نموذج «تصحيح الحالة» من حارس الانتقال (`CorrectTicketStatus::form`) — للإدارة وحدها، و`null` لغيرها. */
interface CorrectionForm { blocker: string | null; targets: { value: string; label: string }[] }
interface Props { ticket: EmpTicket; channel: string; messages: Message[]; summary: SummaryData | null; base?: string; conversation?: ConversationHistory | null; correction?: CorrectionForm | null }

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

/**
 * **تصحيح الحالة — نموذجٌ يقبله الخادم أو سببٌ يشرح امتناعه.** كانت البطاقة تعرض قائمةً مكتوبةً هنا
 * (تنقصها حالتا المآل) ونموذجاً دائماً ولو كان للتذكرة ملفٌّ قائم يردّ الخادمُ تصحيحَها 422.
 * الأهداف والمانع الآن من `CorrectTicketStatus::form` — الحارس نفسه الذي يحكم الطلب.
 */
const CorrectStatusCard: React.FC<{ ticketNo: string; form: CorrectionForm }> = ({ ticketNo, form }) => {
  const toast = useToast();
  const [target, setTarget] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = () => {
    setBusy(true);
    router.post(`/admin/tickets/${encodeURIComponent(ticketNo)}/correct-status`, { status: target, reason }, {
      preserveScroll: true,
      // رسالة النجاح من الخادم (flash) يعرضها التخطيط
      onSuccess: () => {
        setTarget('');
        setReason('');
      },
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تصحيح الحالة'}`),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <div className="card">
      <div className="card-h"><h3>تصحيح الحالة</h3></div>
      <div className="card-b" style={{ padding: 14 }}>
        {form.blocker ? (
          <div style={{ fontSize: 12.5, color: 'var(--muted)', display: 'flex', gap: 8, alignItems: 'flex-start' }}>
            <Icon name="lock" /> <span>{form.blocker}</span>
          </div>
        ) : (
          <>
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>
              استثناءٌ للإدارة فقط — يُسجَّل السبب في ملاحظة داخليّة وفي سجلّ التدقيق.
            </div>
            <div className="field">
              <label>الحالة الصحيحة</label>
              <select value={target} onChange={(e) => setTarget(e.target.value)}>
                <option value="">— اختر —</option>
                {form.targets.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
            <div className="field">
              <label>السبب</label>
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="لماذا تُصحَّح الحالة؟" />
            </div>
            <button className="btn soft sm" type="button" disabled={busy || !target || reason.trim().length < 5} onClick={submit}>
              <Icon name="check" /> تصحيح الحالة
            </button>
          </>
        )}
      </div>
    </div>
  );
};

const SUM_FIELDS: { key: keyof SummaryData; label: string }[] = [
  { key: 'caseSummary', label: 'تلخيص القضية' },
  { key: 'attachmentsSummary', label: 'تلخيص المرفقات' },
  { key: 'facts', label: 'الوقائع' },
  { key: 'keyPoints', label: 'النقاط المهمة' },
];

const LawyerTicketChat: React.FC<Props> = ({ ticket, channel, messages, summary, base = '/lawyer', conversation, correction }) => {
  const toast = useToast();
  const can = useCan();
  // الأزرار تُخفى بحسب الصلاحية — كانت تُعرض دائماً ثم يردّ الخادم 403
  const canApproveSummaries = can('اعتماد الملخصات');
  const canManageCases = can('إدارة القضايا والأتعاب');
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [status, setStatus] = useState({ status: ticket.status, tone: ticket.tone });
  // مؤلّف بمبدّل وضع: ردّ للعميل ⇄ ملاحظة داخلية للمستشار
  const [mode, setMode] = useState<'reply' | 'note'>('reply');
  const [body, setBody] = useState('');
  const [typingSignal, setTypingSignal] = useState(0);
  const endRef = useRef<HTMLDivElement>(null);
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  useEffect(() => {
    const append = (e: { message: Message }) => {
      const m = e.message;
      // قبل فحص التكرار: ردّي أنا يصل مكرّراً ويبقى أنّه قد نقل المحادثة إليّ
      refreshConversationOn(m.who);

      if (m.id && seen.current.has(m.id)) {
return;
}

      if (m.id) {
seen.current.add(m.id);
}

      setMsgs((prev) => [...prev, m]);
    };
    const ch = echo.private(channel);
    ch.listen('.message', append);
    ch.listen('.status', (e: { status: string; tone: string }) => {
      setStatus(e);
      // البثّ يحمل الحالة ولونها وحدهما — أمّا «مجمَّدة/نهائيّة» ونموذج التصحيح وبطاقة المآل فمن
      // الخادم؛ فتُعاد قراءتها بدل اشتقاقها هنا من نصّ الحالة
      router.reload({ only: ['ticket', 'correction', 'summary'] });
    });
    echo.private(`${channel}.staff`).listen('.message', append);

    return () => {
 echo.leave(channel); echo.leave(`${channel}.staff`); 
};
     
  }, [channel]);

  useEffect(() => {
 endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); 
}, [msgs]);

  const no = encodeURIComponent(ticket.no);
  const fail = (fallback: string) => (errors: Record<string, string>) =>
    toast(`⚠️ ${Object.values(errors)[0] ?? fallback}`);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const v = body.trim();

 if (!v) {
return;
}

    const endpoint = mode === 'reply' ? 'reply' : 'note';
    // لا يُمسح النص إلا بعد نجاح الإرسال فعلاً — لا يضيع عند فشل (صلاحية/تحقّق/خادم)
    axios.post(`${base}/tickets/${no}/${endpoint}`, { body: v })
      .then(() => {
        setBody('');

        // الردّ على العميل قد نقل المحادثة إليّ — والملاحظة الداخليّة لا تنقلها
        if (endpoint === 'reply') {
          refreshConversation();
        }
      })
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر الإرسال';
        toast(`⚠️ ${msg}`);
      });
  };

  // رسالة النجاح في الفعلين من الخادم (flash) يعرضها التخطيط — لا إشعار ثانٍ هنا
  const approve = () =>
    router.post(`${base}/summary/${no}/approve`, {}, {
      preserveScroll: true,
      onError: fail('لا يمكن اعتماد ملخّص لم يكتمل تحليله الذكي — حرّره يدوياً أولاً.'),
    });

  const requestDocs = () =>
    router.post(`${base}/tickets/${no}/request-docs`, {}, {
      preserveScroll: true,
      onError: fail('تعذّر طلب مستندات إضافية'),
    });

  const cur = tktStage(status.status);
  // من الخادم لا من قائمة حالاتٍ هنا (`Ticket::toEmployeeCard`) — ويُعاد تحميلها مع بثّ الحالة أعلاه
  const isFrozen = !!ticket.isFrozen || !!ticket.isTerminal;

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href={`${base}/tickets`} className="btn soft sm">
          <Icon name="reply" /> {base === '/admin' ? 'رجوع لكل التذاكر' : 'رجوع لتذاكري'}
        </Link>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>مسار المعالجة</h3><Badge text={status.status} tone={status.tone} /></div>
        <div className="card-b" style={{ padding: '16px 18px' }}><FlowLine steps={TKT_LIFE} cur={cur} /></div>
      </div>

      <div className="tf-grid">
        <div>
          <div className="card">
            <div className="card-h"><h3>محادثة التذكرة {ticket.no}</h3><Badge text={status.status} tone={status.tone} /></div>
            {/* تنبيه عند وجود زميل أو إشارة كتابة */}
            <TicketTalkingNotice channel={channel} canReply typingSignal={typingSignal} />
            <div className="thread">
              {msgs.map((m, i) => <MsgRow key={m.id ?? i} m={m} />)}
              <div ref={endRef} />
            </div>

            {isFrozen ? (
              <div style={{ margin: 14, padding: '14px 18px', textAlign: 'center', background: 'var(--subtle, #f8fafc)', border: '1px solid var(--line, #e2e8f0)', borderRadius: 10 }}>
                <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, color: 'var(--muted, #64748b)', fontWeight: 600, fontSize: 13 }}>
                  <Icon name="lock" />
                  <span>تم حسم قرار مآل التذكرة واكتمال الملف (أرشيف للقراءة فقط)</span>
                </div>
              </div>
            ) : (
              <div className="composer">
                {/* مبدّل الوضع: ردّ للعميل ⇄ ملاحظة داخلية */}
                <div className="cmode">
                  <button type="button" className={mode === 'reply' ? 'on' : ''} onClick={() => setMode('reply')}>
                    <Icon name="reply" /> رد على العميل
                  </button>
                  <button type="button" className={mode === 'note' ? 'on note-on' : ''} onClick={() => setMode('note')}>
                    <Icon name="lock" /> {base === '/admin' ? 'ملاحظة إدارية' : 'ملاحظة داخلية'}
                  </button>
                </div>

                <form onSubmit={submit}>
                  <textarea
                    value={body}
                    onChange={(e) => {
                      setBody(e.target.value);

                      if (mode === 'reply') {
setTypingSignal((n) => n + 1);
}
                    }}
                    placeholder={mode === 'reply' ? 'اكتب ردّك المباشر للعميل…' : 'اكتب ملاحظة داخلية لا يراها العميل…'}
                  />
                  <div className="crow">
                    <button className={mode === 'reply' ? 'btn' : 'btn soft'} type="submit">
                      <Icon name={mode === 'reply' ? 'send' : 'doc'} /> {mode === 'reply' ? 'إرسال الرد' : 'حفظ الملاحظة'}
                    </button>
                  </div>
                </form>
              </div>
            )}
          </div>
        </div>

        <aside className="tf-aside">
          <ConversationHandlerCard conversation={conversation} />

          {/* تفاصيل الطلب (يطابق tkDetailsCard المرجعي) — بجانب المحادثة */}
          <TicketDetailsCard
            subject={ticket.subject}
            dept={ticket.dept}
            service={ticket.type}
            mobile={ticket.mobile}
            priority={ticket.priority}
          />
          {/* قائمة مستندات القسم: ما استُوفي وما بقي وما لم يُتحقّق — تُعاد قراءتها مع كلّ رسالة */}
          <TicketRequirementsCard base={base} ticketNo={ticket.no} refreshKey={msgs.length} canEdit={!isFrozen} />
          <div className="card">
            <div className="card-h">
              <h3>ملخص الملف</h3>
              {summary && <Badge text={summary.approved ? 'معتمد' : summary.lawyerApproved ? 'بانتظار اعتماد الإدارة' : 'بانتظار اعتماد المستشار'} tone={summary.approved ? 'b-green' : 'b-amber'} />}
            </div>
            <div className="card-b" style={{ padding: 14 }}>
              {summary ? (
                <>
                  {SUM_FIELDS.map((f) => (
                    <div key={f.key} style={{ marginBottom: 10 }}>
                      <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 3 }}>{f.label}</div>
                      <div style={{ fontSize: 13, whiteSpace: 'pre-line' }}>{(summary[f.key] as string) || '—'}</div>
                    </div>
                  ))}
                  {canApproveSummaries && (
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 12 }}>
                      {/* الخادم يرفض تعديل الملخّص بعد الاعتماد (`updateSummary` يقذف 422 —
                          قاعدة المالك: كلّ حقلٍ له اعتمادٌ نهائيّ يُقفَل بعده). فزرُّ «تعديل» بعد
                          الاعتماد وعدٌ كاذب. والصفحة تبقى مفيدةً قراءةً وطباعةً معتمدة، فيُبدَّل
                          النصّ ولا يُخفى الزرّ — وإلّا ضاع المدخل الوحيد إليها من هذه الشاشة. */}
                      <Link href={`${base}/summary/${no}`} className="btn soft sm">
                        <Icon name="doc" /> {summary.approved ? 'عرض الملخّص المعتمد' : 'تعديل الملخص'}
                      </Link>
                      {!summary.approved && (base === '/admin' || !summary.lawyerApproved) && (
                        <button className="btn sm" onClick={approve} type="button">
                          <Icon name="check" /> {base === '/admin' ? 'اعتماد نهائي وإرسال للعميل' : 'اعتماد ورفع للإدارة'}
                        </button>
                      )}
                    </div>
                  )}
                </>
              ) : (
                <div className="empty"><Icon name="doc" /><b>لا ملخص بعد</b></div>
              )}
            </div>
          </div>

          {/* تصحيح الحالة استثناءٌ إداريّ مسبَّب — لا قائمة حالات بيد الموظّف (قرار المالك 2026-09-14) */}
          {correction && <CorrectStatusCard ticketNo={ticket.no} form={correction} />}

          {/* حوكمة وتحديد مسار المآل (القرارات الأربعة ومقترح الذكاء الاصطناعي) */}
          <TicketTrackDecisionCard
            ticketNo={ticket.no}
            status={status.status}
            role={base === '/admin' ? 'admin' : 'lawyer'}
            base={base}
            governance={ticket.trackGovernance}
            isFrozen={isFrozen}
            hasCase={ticket.hasCase || Boolean(ticket.caseRef)}
            caseNumber={ticket.caseNumber || ticket.caseRef}
            hasExecution={ticket.hasExecution}
            executionNumber={ticket.executionNumber}
            closureReasonCode={ticket.closureReasonCode}
            closureNotes={ticket.closureNotes}
          />

          {/* لوحة إجراءات وتحويلات التذكرة الموحدة (مطابقة للتصميم المرجعي) */}
          {canManageCases && (
            <TicketActionsPanel
              ticketNo={ticket.no}
              status={status.status}
              caseRef={ticket.caseRef ?? null}
              role={base === '/admin' ? 'admin' : 'lawyer'}
              // طلب النواقص كتابةٌ على التذكرة — لا يُعرض لمجمَّدةٍ أو نهائيّة (والخادم يرفضه كذلك)
              onRequestDocs={isFrozen ? undefined : requestDocs}
            />
          )}

          <div className="card">
            <div className="tc-top"><div className="lbl">التذكرة</div><div className="num">{ticket.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{ticket.client}</span></div>
              <div className="tc-row"><span className="k">النوع</span><span className="v">{ticket.type}</span></div>
              <div className="tc-row"><span className="k">القسم</span><span className="v">{ticket.dept}</span></div>
            </div>
          </div>
        </aside>
      </div>

    </div>
  );
};

export default LawyerTicketChat;
