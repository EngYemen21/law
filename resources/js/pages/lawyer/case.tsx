import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import ConversationHandlerCard from '@/components/babylon/ConversationHandlerCard';
import type { ConversationHistory } from '@/components/babylon/ConversationHandlerCard';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { AppealCard, AttachDocModal, HearingUpdatesCard, NajizFilingCard, RulingCard, ScheduleHearingCard } from '@/lib/case-court';
import type { AppealData, Filing } from '@/lib/case-court';
import { CASE_LIFE, caseStage, HearingsCard, CaseMsgRow } from '@/lib/case-ui';
import type { Hearing } from '@/lib/case-ui';
import type { Message } from '@/lib/chat';
import { echo } from '@/lib/echo';
import Icon from '@/lib/icons';

interface CaseInfo {
  no: string; client: string; type: string; dept: string; lawyer: string;
  status: string; tone: string; next?: string | null; pleadingStatus: string; ruling?: string | null;
  appeal?: AppealData | null;
}
interface CaseDoc {
  id: number; name: string; by: string; status: string; docType: string; summary: string; date: string; downloadUrl?: string | null;
  hearingId?: number | null; hearingTitle?: string | null; source?: 'ticket' | 'case';
}
interface FileInfo { ticketNo?: string | null; subject?: string | null; opponent?: string | null; claim?: string | null; court?: string | null }
interface FileFacts { summary?: string | null; facts?: string | null; keyPoints?: string | null; approved: boolean }
interface ReadinessItem { label: string; ok: boolean; hint?: string | null }
interface Props {
  case: CaseInfo; channel: string; messages: Message[]; hearings: Hearing[]; documents: CaseDoc[];
  convertedExec?: boolean; pleadingBlock?: string | null; pleadingDraft?: string | null; canExecute?: boolean;
  ticketDocuments?: CaseDoc[]; fileInfo?: FileInfo; fileFacts?: FileFacts | null; readiness?: ReadinessItem[]; filing?: Filing;
  /** من يتولّى المحادثة ومن تولّاها قبله — `ConversationHandler::history`. */
  conversation?: ConversationHistory | null;
}

type DocSource = 'ticket' | 'case';
type DocTab = 'all' | DocSource | 'pending';
const DOC_TABS: [DocTab, string][] = [['all', 'الكل'], ['case', 'مستندات القضية'], ['ticket', 'مرفقات الطلب'], ['pending', 'بانتظار التحليل']];

/** حالة المستند كما تُعرض: الملخّص دليل التحليل، و«بحاجة لمراجعة يدوية» تعثّرٌ يُنبَّه إليه. */
function docState(d: CaseDoc): [string, string] {
  if (d.status.includes('مراجعة يدوية')) {
    return ['بحاجة لمراجعة', 'b-red'];
  }

  return d.summary ? ['محلَّل', 'b-green'] : ['بانتظار التحليل', 'b-amber'];
}

const LawyerCase: React.FC<Props> = ({ case: c, channel, messages, hearings, documents, convertedExec, pleadingBlock, pleadingDraft, canExecute, ticketDocuments = [], fileInfo = {}, fileFacts = null, readiness = [], filing = { canFile: false, canRegister: false, data: null }, conversation }) => {
  const ask = useConfirm();
  const toast = useToast();
  const base = `/lawyer/cases/${encodeURIComponent(c.no)}`;
  const [attachOpen, setAttachOpen] = useState(false);
  const [msgs, setMsgs] = useState<Message[]>(messages);
  const [reply, setReply] = useState('');
  const [live, setLive] = useState({ status: c.status, tone: c.tone });
  const [propsFrom, setPropsFrom] = useState({ status: c.status, messages });
  // محرّر اللائحة — يتبع أحدث مسودّة من الخادم (بعد الحفظ أو إعادة التوليد)
  const [draft, setDraft] = useState(pleadingDraft ?? '');
  const [draftFrom, setDraftFrom] = useState(pleadingDraft ?? '');
  const [pBusy, setPBusy] = useState(false);
  const [regenPending, setRegenPending] = useState(false);
  // مستندات الملفّ كلّه: القضيّة أوّلاً (الأحدث عملاً) ثم مرفقات الطلب — كلٌّ بالأحدث
  const [docTab, setDocTab] = useState<DocTab>('all');
  const [openDoc, setOpenDoc] = useState<string | null>(null);
  const allDocs = useMemo(() => [
    ...documents.map((d) => ({ ...d, source: 'case' as DocSource })),   // الخادم يرتّبها الأحدث أوّلاً
    ...ticketDocuments.map((d) => ({ ...d, source: 'ticket' as DocSource })),
  ], [documents, ticketDocuments]);
  const docCount = (t: DocTab) => (t === 'all' ? allDocs.length : t === 'pending' ? allDocs.filter((d) => !d.summary).length : allDocs.filter((d) => d.source === t).length);
  const shownDocs = allDocs.filter((d) => docTab === 'all' || (docTab === 'pending' ? !d.summary : d.source === docTab));

  // مسودّةٌ جديدة من الخادم (بعد الحفظ أو إعادة التوليد) تُحمَّل في المحرّر — ضبطٌ أثناء العرض لا في effect
  if ((pleadingDraft ?? '') !== draftFrom) {
    const wasDirty = draft.trim() !== draftFrom.trim();

    setDraftFrom(pleadingDraft ?? '');
    setRegenPending(false);

    // تعديلٌ لم يُحفظ لا يُمسح بمسودّةٍ وصلت — يبقى، وتنبّه إليه عبارة «تعديلٌ غير محفوظ»
    if (!wasDirty) {
      setDraft(pleadingDraft ?? '');
    }
  }

  const dirty = draft.trim() !== (pleadingDraft ?? '').trim();
  const seen = useRef<Set<number>>(new Set(messages.map((m) => m.id).filter(Boolean) as number[]));

  // **الحالة والمحادثة تتبعان الخادم بعد كلّ إجراء** — لا البثّ وحده. كان الرفع في ناجز يُسجَّل
  // والشارة تبقى «قيد التحضير» ورسالة القيد لا تظهر حتى إعادة التحميل (قيسَ في المتصفّح 2026-09-11).
  if (c.status !== propsFrom.status || messages !== propsFrom.messages) {
    setPropsFrom({ status: c.status, messages });
    setLive({ status: c.status, tone: c.tone });
    setMsgs(messages);
  }

  // معرّفات ما جاء من الخادم تُعلَّم مقروءة كي لا يكرّرها البثّ — في أثرٍ لا أثناء الرسم
  useEffect(() => {
    messages.forEach((m) => m.id && seen.current.add(m.id));
  }, [messages]);

  useEffect(() => {
    const ch = echo.private(channel);
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
    ch.listen('.message', append);
    // الملاحظات الداخليّة تُبثّ على قناة الطاقم وحدها — لا على القناة التي يسمعها العميل
    echo.private(`${channel}.staff`).listen('.message', append);
    ch.listen('.status', (e: { status: string; tone: string }) => {
      setLive({ status: e.status, tone: e.tone });
      // مسودّةٌ جهزت بالطابور (المحجوب لا يُبثّ) — يُحدَّث المحرّر وسببُ المنع
      router.reload({ only: ['pleadingDraft', 'pleadingBlock'] });
    });

    return () => {
      echo.leave(channel);
      echo.leave(`${channel}.staff`);
    };
  }, [channel]);

  // ردّ المستشار على موكّله داخل الملفّ — لا يُمسح النصّ إلا بعد نجاح الإرسال
  const send = (e: React.FormEvent) => {
    e.preventDefault();
    const v = reply.trim();

    if (!v) {
      return;
    }

    axios.post(`${base}/reply`, { body: v })
      .then(() => setReply(''))
      .catch(() => toast('⚠️ تعذّر إرسال الردّ، حاول مجدداً'));
  };

  const pleadingPost = (path: string, data: Record<string, string>, ok: string, after?: () => void) => {
    setPBusy(true);
    router.post(`${base}/${path}`, data, {
      preserveScroll: true,
      onSuccess: () => {
        toast(ok);
        after?.();
      },
      onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّر تنفيذ الإجراء')),
      onFinish: () => setPBusy(false),
    });
  };
  const savePleading = () => pleadingPost('pleading/save', { body: draft }, 'حُفظت المسودّة — محجوبة عن العميل حتى الاعتماد النهائيّ');
  const regeneratePleading = async () => {
    const ok = await ask({
      title: 'إعادة توليد اللائحة',
      message: 'تُنشئ مسودّةً آليّة جديدة تحلّ محلّ النصّ في المحرّر — ويبقى ما حفظته في سجلّ المحادثة.',
      confirmLabel: 'إعادة التوليد',
      tone: 'danger',
    });

    if (!ok) {
      return;
    }

    pleadingPost('pleading/regenerate', {}, 'جارٍ إعادة التوليد — تظهر المسودّة في المحرّر حين تجهز', () => setRegenPending(true));
  };
  const approvePleading = async () => {
    const ok = await ask({
      title: 'الاعتماد النهائيّ للائحة',
      message: 'يُقفل نصّ اللائحة ويُتيحه للعميل، ولا يُعدَّل بعده. ثمّ ارفع الصحيفة في ناجز وسجّل رقم الطلب هنا.',
      confirmLabel: 'اعتماد نهائيّ',
      tone: 'danger',
    });

    if (!ok) {
      return;
    }

    pleadingPost('pleading', {}, 'اعتُمدت اللائحة نهائياً — ارفعها الآن في ناجز وسجّل رقم الطلب');
  };
  // الرفع في ناجز والقيد والجلسات والحكم: بطاقات `case-court` — مشتركةٌ مع الموظّف
  const convertToExec = () =>
    router.post(`${base}/execute`, {}, { onSuccess: () => toast('تم فتح طلب تنفيذ الحكم') });

  const active = c.status === 'منظورة';

  return (
    <div className="tflow">
      <div style={{ marginBottom: 14 }}>
        <Link href="/lawyer/cases" className="btn soft sm"><Icon name="reply" /> رجوع لقضاياي</Link>
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
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--faint)', marginBottom: 8 }}>ردّ للعميل (المستشار القانوني):</div>
              <form onSubmit={send}>
                <textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder="اكتب ردّك للعميل…" />
                <div className="crow"><button className="btn" type="submit"><Icon name="send" /> إرسال</button></div>
              </form>
            </div>
          </div>

          {/* جدولة الجلسات — والقضيّة منظورة */}
          {active && <ScheduleHearingCard base={base} />}

          {/* تسجيل الحكم أو عرضه مع إمكانية التصحيح المسبّب */}
          {(active || c.ruling) && (
            <RulingCard
              base={base}
              ruling={c.ruling}
              canCorrect={['صدر الحكم', 'مغلقة'].includes(live.status)}
            />
          )}

          {/* مسار الاستئناف والاعتراض (مهلة الاعتراض 30 يوماً / قيد الاستئناف / حكم الاستئناف) */}
          <AppealCard
            base={base}
            appeal={c.appeal}
            canAct={!['مؤرشفة'].includes(live.status)}
            defaultCourt={fileInfo.court ?? ''}
          />
        </div>

        <aside className="tf-aside">
          <ConversationHandlerCard conversation={conversation} />

          {/* بطاقة الملفّ — ما يحتاجه المحامي أمامه دائماً */}
          <div className="card">
            <div className="tc-top"><div className="lbl">القضية</div><div className="num">{c.no}</div></div>
            <div className="tc-body">
              <div className="tc-row"><span className="k">العميل</span><span className="v">{c.client}</span></div>
              {fileInfo.opponent && <div className="tc-row"><span className="k">الخصم</span><span className="v">{fileInfo.opponent}</span></div>}
              {fileInfo.claim && <div className="tc-row"><span className="k">قيمة المطالبة</span><span className="v">{fileInfo.claim}</span></div>}
              {fileInfo.court && <div className="tc-row"><span className="k">المحكمة</span><span className="v">{fileInfo.court}</span></div>}
              {filing.data?.caseNo && <div className="tc-row"><span className="k">رقم القضية (ناجز)</span><span className="v">{filing.data.caseNo}</span></div>}
              {filing.data?.circuit && <div className="tc-row"><span className="k">الدائرة</span><span className="v">{filing.data.circuit}</span></div>}
              <div className="tc-row"><span className="k">النوع</span><span className="v">{c.type}</span></div>
              <div className="tc-row"><span className="k">القسم</span><span className="v">{c.dept}</span></div>
              <div className="tc-row"><span className="k">الجلسة القادمة</span><span className="v">{c.next ?? '—'}</span></div>
              {fileInfo.ticketNo && <div className="tc-row"><span className="k">الطلب الأصلي</span><span className="v">{fileInfo.ticketNo}</span></div>}
            </div>
            {fileInfo.subject && <div style={{ padding: '0 16px 14px', fontSize: 12.5, color: 'var(--muted)', lineHeight: 1.7 }}>{fileInfo.subject}</div>}
          </div>

          {/* جاهزية اللائحة — ما يلزم قبل الاعتماد النهائيّ (من حرّاس الخادم نفسها) */}
          {readiness.length > 0 && (
            <div className="card">
              <div className="card-h"><h3>جاهزية اللائحة</h3><span className="sub">{readiness.filter((r) => r.ok).length}/{readiness.length}</span></div>
              <div className="card-b">
                {readiness.map((r) => (
                  <div key={r.label} className="item" style={{ padding: '7px 0' }}>
                    <div className="iico" style={{ color: r.ok ? 'var(--green)' : 'var(--amber)' }}><Icon name={r.ok ? 'check' : 'alert'} /></div>
                    <div className="imeta"><b style={{ fontWeight: r.ok ? 600 : 700 }}>{r.label}</b>{r.hint && <span>{r.hint}</span>}</div>
                  </div>
                ))}
              </div>
            </div>
          )}
          {/* اعتماد اللائحة */}
          {c.pleadingStatus === 'pending_lawyer' && (
            <div className="card">
              <div className="card-h"><h3>اعتماد اللائحة</h3><Badge text="بانتظار اعتمادك" tone="b-amber" /></div>
              <div className="card-b" style={{ padding: 14 }}>
                <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 10 }}>
                  حرّر لائحة الدعوى ثم احفظها — تبقى محجوبة عن العميل. الاعتماد النهائيّ يُقفل التعديل ويُتيحها له، ثم تُرفع في ناجز.
                </div>
                <textarea
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder="نصّ لائحة الدعوى…"
                  rows={14}
                  style={{ width: '100%', minHeight: 260, whiteSpace: 'pre-wrap' }}
                  aria-label="نصّ لائحة الدعوى"
                />
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
                  <button className="btn soft sm" type="button" disabled={pBusy || !dirty || !draft.trim()} onClick={savePleading}>
                    <Icon name="doc" /> حفظ المسودّة
                  </button>
                  <button className="btn soft sm" type="button" disabled={pBusy || regenPending} onClick={regeneratePleading}>
                    <Icon name="reply" /> {regenPending ? 'جارٍ التوليد…' : 'إعادة التوليد'}
                  </button>
                  <Link
                    href={`/lawyer/editor/create?importType=case_pleading&id=${encodeURIComponent(c.no)}`}
                    className="btn soft sm"
                    style={{
                      textDecoration: 'none',
                      gap: 5,
                      background: 'rgba(14, 92, 156, 0.08)',
                      borderColor: 'rgba(14, 92, 156, 0.3)',
                      color: '#0e5c9c',
                      fontWeight: 700,
                    }}
                    title="فتح وتنسيق اللائحة في محرر المستندات الرسمي Word"
                  >
                    <Icon name="edit" /> تنسيق اللائحة في المحرر ⚖️
                  </Link>
                  {/* الاعتماد يُطلق ما حُفظ — فالتعديل غير المحفوظ يُحفظ أولاً */}
                  <button className="btn sm" type="button" disabled={pBusy || dirty || !!pleadingBlock} onClick={approvePleading}>
                    <Icon name="check" /> الاعتماد النهائيّ للّائحة
                  </button>
                </div>
                {dirty && <div style={{ fontSize: 12, color: 'var(--amber)', marginTop: 6 }}>تعديلٌ غير محفوظ — احفظه قبل الاعتماد النهائيّ.</div>}
                {!dirty && pleadingBlock && <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 6 }}>{pleadingBlock}</div>}
              </div>
            </div>
          )}

          {/* رفع الدعوى في ناجز ثمّ قيدها — ما يجوز يحدّده الخادم (الخطّة ب) */}
          <NajizFilingCard base={base} filing={filing} defaultCourt={fileInfo.court ?? ''} />

          {/* مستندات الملفّ كلّه — مرفقات الطلب قبل التحويل ومستندات القضية بعده، بتصنيفٍ وعدّاد */}
          <div className="card">
            <div className="card-h">
              <h3>مستندات القضية</h3>
              {c.status !== 'مؤرشفة' && (
                <button className="btn soft sm" type="button" onClick={() => setAttachOpen(true)}>
                  <Icon name="upload" /> إرفاق مستند
                </button>
              )}
            </div>
            <div className="card-b">
              <div className="chips" style={{ marginBottom: 10 }}>
                {DOC_TABS.map(([k, label]) => (
                  <button key={k} type="button" className={`chip sel-toggle${docTab === k ? ' on' : ''}`} onClick={() => setDocTab(k)}>
                    {label} ({docCount(k)})
                  </button>
                ))}
              </div>
              {shownDocs.length ? shownDocs.map((d) => {
                const key = `${d.source}-${d.id}`;
                const [stateText, stateTone] = docState(d);

                return (
                  <div key={key} className="item" style={{ alignItems: 'flex-start' }}>
                    <div className="iico"><Icon name={d.source === 'ticket' ? 'ticket' : 'doc'} /></div>
                    <div className="imeta" style={{ minWidth: 0, flex: 1 }}>
                      <b style={{ wordBreak: 'break-word' }}>{d.name}</b>
                      <span>{d.docType || 'مستند'} · {d.by} · {d.date}</span>
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 5 }}>
                        <Badge text={stateText} tone={stateTone} />
                        {d.source === 'ticket' && <Badge text="قبل التحويل" tone="b-grey" />}
                        {d.hearingTitle && <Badge text={`جلسة: ${d.hearingTitle}`} tone="b-blue" />}
                      </div>
                      {d.summary && (
                        <button
                          type="button"
                          onClick={() => setOpenDoc(openDoc === key ? null : key)}
                          style={{ background: 'none', border: 0, padding: 0, marginTop: 6, color: 'var(--primary)', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}
                        >
                          {openDoc === key ? 'إخفاء الملخّص' : 'عرض الملخّص'}
                        </button>
                      )}
                      {d.summary && openDoc === key && (
                        <div style={{ marginTop: 6, fontSize: 12.5, lineHeight: 1.8, color: 'var(--ink)', background: 'var(--paper-2)', borderRadius: 8, padding: '8px 10px' }}>{d.summary}</div>
                      )}
                    </div>
                    {/* التنزيل لمن يجيزه الخادم وحده — ويرسل null لمن سواه */}
                    {d.downloadUrl && (
                      <a className="btn soft sm" href={d.downloadUrl} title="تنزيل المستند" aria-label={`تنزيل ${d.name}`}>
                        <Icon name="download" />
                      </a>
                    )}
                  </div>
                );
              }) : (
                <div className="empty"><Icon name="doc" /><b>لا مستندات في هذا التصنيف</b></div>
              )}
            </div>
          </div>

          {/* وقائع الملفّ — ملخّص الطلب كما دُرس قبل التحويل، بوسم اعتماده */}
          {fileFacts && (fileFacts.summary || fileFacts.facts || fileFacts.keyPoints) && (
            <div className="card">
              <div className="card-h"><h3>وقائع الملف</h3><Badge text={fileFacts.approved ? 'ملخّص معتمد' : 'غير معتمد بعد'} tone={fileFacts.approved ? 'b-green' : 'b-amber'} /></div>
              <div className="card-b" style={{ padding: 14, fontSize: 13, lineHeight: 1.9, whiteSpace: 'pre-line' }}>
                {fileFacts.summary && <p style={{ margin: 0 }}>{fileFacts.summary}</p>}
                {fileFacts.facts && <><b style={{ display: 'block', marginTop: 10 }}>الوقائع</b><div>{fileFacts.facts}</div></>}
                {fileFacts.keyPoints && <><b style={{ display: 'block', marginTop: 10 }}>النقاط الجوهرية</b><div>{fileFacts.keyPoints}</div></>}
              </div>
            </div>
          )}

          {/* إدارة الجلسات — المغلقة والمؤرشفة للقراءة، والخادم يرفض تحريك جلساتهما */}
          {hearings.length > 0 && !['مغلقة', 'مؤرشفة'].includes(live.status) && <HearingUpdatesCard base={base} hearings={hearings} />}

          {(canExecute || convertedExec) && (
            <div className="card">
              <div className="card-h"><h3>تنفيذ الحكم</h3>{convertedExec && <Badge text="محوّل لتنفيذ" tone="b-cyan" />}</div>
              <div className="card-b" style={{ padding: 14 }}>
                {convertedExec ? (
                  <div className="empty"><Icon name="exec" /><b>فُتح طلب تنفيذ لهذا الحكم</b></div>
                ) : (
                  <>
                    <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>صدر الحكم. يمكنك فتح طلب تنفيذ لتحصيل الحق لدى محكمة التنفيذ.</div>
                    <button className="btn sm" type="button" onClick={convertToExec}><Icon name="exec" /> فتح طلب تنفيذ الحكم</button>
                  </>
                )}
              </div>
            </div>
          )}

          <HearingsCard hearings={hearings} documents={documents} />
        </aside>
      </div>

      <AttachDocModal
        open={attachOpen}
        onClose={() => setAttachOpen(false)}
        base={base}
        hearings={hearings}
      />
    </div>
  );
};

export default LawyerCase;
