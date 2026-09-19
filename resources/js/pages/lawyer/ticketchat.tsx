import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import CloseTicketModal, { CLOSURE_REASONS } from '@/components/babylon/CloseTicketModal';
import FlowLine from '@/components/babylon/FlowLine';
import MsgMeta from '@/components/babylon/MsgMeta';
import TicketActionsPanel from '@/components/babylon/TicketActionsPanel';
import TicketDetailsCard from '@/components/babylon/TicketDetailsCard';
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
  caseRef?: string | null; subject?: string | null; priority?: string | null; mobile?: string | null; openedAt?: string | null;
  isFrozen?: boolean;
  canDecideOutcome?: boolean;
  isTerminal?: boolean;
  closureReasonCode?: string | null;
  closureNotes?: string | null;
  hasCase?: boolean;
  caseNumber?: string | null;
  hasExecution?: boolean;
  executionNumber?: string | null;
  trackGovernance?: TrackGovernanceData | null;
}
interface Props { ticket: EmpTicket; channel: string; messages: Message[]; summary: SummaryData | null; converted?: boolean; base?: string }

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

// مراحل الرحلة التي يصحّح إليها المدير
const CORRECTABLE = [
  'جديدة', 'قيد التحليل', 'بانتظار مستندات', 'محالة للقسم القانوني', 'بانتظار اعتماد المستشار',
  'بانتظار اعتماد الإدارة للملخّص', 'الرأي القانوني', 'بانتظار حجز الاستشارة', 'بانتظار تحديد الموعد',
  'موعد مؤكد', 'بانتظار ملخّص الجلسة', 'مكتملة', 'مغلقة',
];

const CorrectStatusCard: React.FC<{ ticketNo: string; current: string }> = ({ ticketNo, current }) => {
  const toast = useToast();
  const [target, setTarget] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = () => {
    setBusy(true);
    router.post(`/admin/tickets/${encodeURIComponent(ticketNo)}/correct-status`, { status: target, reason }, {
      preserveScroll: true,
      onSuccess: () => {
        toast('صُحّحت حالة التذكرة وسُجّل السبب');
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
        <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>
          استثناءٌ للإدارة فقط — يُسجَّل السبب في ملاحظة داخليّة وفي سجلّ التدقيق.
        </div>
        <div className="field">
          <label>الحالة الصحيحة</label>
          <select value={target} onChange={(e) => setTarget(e.target.value)}>
            <option value="">— اختر —</option>
            {CORRECTABLE.filter((s) => s !== current).map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div className="field">
          <label>السبب</label>
          <textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="لماذا تُصحَّح الحالة؟" />
        </div>
        <button className="btn soft sm" type="button" disabled={busy || !target || reason.trim().length < 5} onClick={submit}>
          <Icon name="check" /> تصحيح الحالة
        </button>
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

const LawyerTicketChat: React.FC<Props> = ({ ticket, channel, messages, summary, converted, base = '/lawyer' }) => {
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
    ch.listen('.status', (e: { status: string; tone: string }) => setStatus(e));
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
      .then(() => setBody(''))
      .catch((err) => {
        const msg = err.response?.data?.message || 'تعذّر الإرسال';
        toast(`⚠️ ${msg}`);
      });
  };

  const approve = () =>
    router.post(`${base}/summary/${no}/approve`, {}, {
      onSuccess: () => toast(base === '/admin' ? 'اعتُمد الملخّص وأُرسل الرأي القانوني للعميل' : 'اعتُمد الملخّص ورُفع للإدارة'),
      onError: fail('لا يمكن اعتماد ملخّص لم يكتمل تحليله الذكي — حرّره يدوياً أولاً.'),
    });

  const [showCloseModal, setShowCloseModal] = useState(false);

  const convertToCase = () =>
    router.post(`${base}/tickets/${no}/convert`, {}, {
      onSuccess: () => toast('✅ تم تحويل التذكرة إلى قضية بنجاح'),
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تحويل التذكرة لقضية'}`),
    });

  const requestDocs = () =>
    router.post(`${base}/tickets/${no}/request-docs`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم طلب مستندات إضافية من العميل'),
      onError: fail('تعذّر طلب مستندات إضافية'),
    });

  const cur = tktStage(status.status);
  const isTerminal = ['محولة إلى قضية', 'مغلقة'].includes(status.status) || !!ticket.isTerminal;
  const isFrozen = !!ticket.isFrozen || isTerminal;
  const canDecideOutcome = !isFrozen && !ticket.caseRef && !converted && (status.status === 'بانتظار قرار المآل' || status.status === 'مكتملة');
  const canConvert = canDecideOutcome;

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
          {/* تفاصيل الطلب (يطابق tkDetailsCard المرجعي) — بجانب المحادثة */}
          <TicketDetailsCard
            subject={ticket.subject}
            dept={ticket.dept}
            service={ticket.type}
            mobile={ticket.mobile}
            priority={ticket.priority}
          />
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
                      <Link href={`${base}/summary/${no}`} className="btn soft sm">
                        <Icon name="doc" /> تعديل الملخص
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
          {base === '/admin' && <CorrectStatusCard ticketNo={ticket.no} current={status.status} />}

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
              onRequestDocs={requestDocs}
              onCloseJustified={() => setShowCloseModal(true)}
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

      {showCloseModal && (
        <CloseTicketModal
          open={showCloseModal}
          ticketNo={ticket.no}
          role={base === '/admin' ? 'admin' : 'lawyer'}
          onClose={() => setShowCloseModal(false)}
        />
      )}
    </div>
  );
};

export default LawyerTicketChat;
