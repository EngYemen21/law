import { Link, router } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import MsgMeta from '@/components/babylon/MsgMeta';
import TicketTalkingNotice from '@/components/babylon/TicketTalkingNotice';
import TicketActionsPanel from '@/components/babylon/TicketActionsPanel';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { TKT_LIFE, tktStage, type Message } from '@/lib/chat';
import { useCan } from '@/lib/permissions';
import { type SummaryData } from '@/lib/lawyer-data';

// دراسة التذكرة لدى المستشار — محادثة العميل (سياق حيّ) + ملخص الملف + الاعتماد
// تُستخدم الصفحة نفسها من لوحة الإدارة؛ لذا كل الروابط تُبنى من base لا مثبّتة على /lawyer.

interface EmpTicket { no: string; client: string; type: string; dept: string; lawyer: string; status: string; tone: string; caseRef?: string | null; }
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
  const endRef = useRef<HTMLDivElement>(null);
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  useEffect(() => {
    const ch = echo.private(channel);
    ch.listen('.message', (e: { message: Message }) => {
      const m = e.message;
      if (m.id && seen.current.has(m.id)) return;
      if (m.id) seen.current.add(m.id);
      setMsgs((prev) => [...prev, m]);
    });
    ch.listen('.status', (e: { status: string; tone: string }) => setStatus(e));
    return () => { echo.leave(channel); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel]);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, [msgs]);

  const no = encodeURIComponent(ticket.no);
  const fail = (fallback: string) => (errors: Record<string, string>) =>
    toast(`⚠️ ${Object.values(errors)[0] ?? fallback}`);

  const approve = () =>
    router.post(`${base}/summary/${no}/approve`, {}, {
      onSuccess: () => toast('تم اعتماد الملخص وإرساله لمحادثة العميل'),
      onError: fail('لا يمكن اعتماد ملخّص لم يكتمل تحليله الذكي — حرّره يدوياً أولاً.'),
    });

  // اعتماد نتيجة الجلسة خطوة المستشار (pending_lawyer)؛ اعتماد الإدارة النهائي في /admin/summaries.
  // لذا يبقى المسار مسار المستشار حتى حين تفتح الإدارة الصفحة (تتجاوز حارس الدور).
  const approveResult = () =>
    router.post(`/lawyer/tickets/${no}/result`, {}, {
      onSuccess: () => toast('تم اعتماد ملخص الجلسة ورفعه للإدارة'),
      onError: fail('تعذّر اعتماد ملخص الجلسة'),
    });

  const closeTicket = () =>
    router.post(`${base}/tickets/${no}/close`, {}, {
      onSuccess: () => toast('تم إغلاق الطلب دون تحويله إلى قضية'),
      onError: fail('تعذّر إغلاق الطلب'),
    });

  const requestDocs = () =>
    router.post(`${base}/tickets/${no}/request-docs`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم طلب مستندات إضافية من العميل'),
      onError: fail('تعذّر طلب مستندات إضافية'),
    });

  const cur = tktStage(status.status);
  const canConvert = status.status === 'مكتملة' && !converted;

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
            <div className="card-h"><h3>محادثة العميل {ticket.no}</h3></div>
            {/* وعيٌ فقط: هل يتحدث موظف مع العميل الآن؟ (لا صندوق ردّ هنا فلا بثّ) */}
            <TicketTalkingNotice channel={channel} />
            <div className="thread">
              {msgs.map((m, i) => <MsgRow key={m.id ?? i} m={m} />)}
              <div ref={endRef} />
            </div>
          </div>
        </div>

        <aside className="tf-aside">
          <div className="card">
            <div className="card-h">
              <h3>ملخص الملف</h3>
              {summary && <Badge text={summary.approved ? 'معتمد' : 'بانتظار اعتمادك'} tone={summary.approved ? 'b-green' : 'b-amber'} />}
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
                      {!summary.approved && (
                        <button className="btn sm" onClick={approve} type="button">
                          <Icon name="check" /> اعتماد وإرسال للعميل
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

          {summary?.resultStatus === 'pending_lawyer' && canApproveSummaries && (
            <div className="card">
              <div className="card-h"><h3>نتيجة الجلسة</h3><Badge text="بانتظار اعتمادك" tone="b-amber" /></div>
              <div className="card-b" style={{ padding: 14 }}>
                <div style={{ fontSize: 13, whiteSpace: 'pre-line', marginBottom: 12 }}>{summary.result || '—'}</div>
                <button className="btn sm" onClick={approveResult} type="button">
                  <Icon name="check" /> اعتماد ملخص الجلسة ورفعه للإدارة
                </button>
              </div>
            </div>
          )}

          {/* قرار المستشار: الإغلاق دون تحويل فقط — التحويل لقضية وطلب المستندات في لوحة الإجراءات أدناه (بلا ازدواج) */}
          {(canConvert || converted) && canManageCases && (
            <div className="card">
              <div className="card-h"><h3>قرار المستشار</h3>{converted && <Badge text="محوّلة لقضية" tone="b-cyan" />}</div>
              <div className="card-b" style={{ padding: 14 }}>
                {converted ? (
                  <div className="empty"><Icon name="scale" /><b>تم تحويل هذه التذكرة إلى قضية</b></div>
                ) : (
                  <>
                    <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
                      اكتملت الاستشارة. إن لم تكن بحاجة لفتح قضية رسمية يمكنك إغلاق الطلب:
                    </div>
                    <button className="btn soft sm" onClick={closeTicket} type="button">
                      <Icon name="check" /> إغلاق دون تحويل
                    </button>
                  </>
                )}
              </div>
            </div>
          )}

          {/* لوحة إجراءات وتحويلات التذكرة الموحدة (مطابقة للتصميم المرجعي) */}
          {canManageCases && (
            <TicketActionsPanel
              ticketNo={ticket.no}
              status={status.status}
              caseRef={ticket.caseRef ?? null}
              role={base === '/admin' ? 'admin' : 'lawyer'}
              onRequestDocs={requestDocs}
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
