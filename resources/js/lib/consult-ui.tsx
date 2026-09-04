import { Link, router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
import StatRow from '@/components/babylon/StatRow';
import type {StatItem} from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import {
  CONSULT_CHANNELS, CONSULT_FLOW, cStage, cHasStage, cTone,
  crChannelIcon, crChannelTone, maskClient
} from '@/lib/employee-data';
import type {AuditEntry} from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useCan } from '@/lib/permissions';
import ZoomEmbedRoom from '@/lib/zoom-room';

// ============================================================
// واجهة الاستشارات المشتركة (سجلّ Consult الحقيقي من الخادم)
// يطابق consultRecvView + videoRoomView + vrEnd في index (82).html
// ============================================================

// نصّ قرار آمن للعرض — القرارات نصوص عادةً، لكن بيانات قديمة قد تحمل كائن مهمّة {title,...}
// (نظير الحارس نفسه في DecisionTasks::create على الخادم) فلا يُكسَر React عند عنصر غير نصّي.
function decisionText(x: unknown): string {
  if (typeof x === 'string') {
    return x;
  }

  return (x as { title?: string })?.title ?? JSON.stringify(x);
}

// بطاقة الاستشارة كما يعيدها الخادم (Consult::toCard)
export interface ConsultCard {
  id: number;
  ref: string;
  client: string;
  subject: string;
  specialty?: string; // تخصّص الاستشارة (لتصفية منتقي المستشارين عند اختيار الموعد)
  channel: string; // مرئية / حضورية / هاتفية
  lawyer: string;
  when: string;
  place: string;
  phone: string;
  slink: string;
  canJoin?: boolean; // زر الدخول مفعّل؟ (بعد إطلاق الرابط قبل الموعد بـ5د)
  missed?: boolean; // فات موعدها بلا جلسة (يشتقه الخادم)
  startable?: boolean; // «بدء الجلسة» ضمن نافذة الموعد فقط (يشتقه الخادم — بطاقة المكتب)
  startsAt?: string | null;
  hostLink: string | null; // رابط مضيف Zoom (للمكتب)
  session: string; // بانتظار الجلسة / جلسة جارية / منتهية
  status: string;
  summary: string | null;
  /** الملخّص محجوبٌ عن العميل حتى يعتمده محامٍ — انظر `Consult::toClientCard`. */
  summaryPending?: boolean;
  summaryApproved?: boolean;
  /** حُرّر الملخّص بيد إنسان قبل الاعتماد (بطاقة المكتب وحدها). */
  summaryEdited?: boolean;
  /** ختم الاعتماد (ISO) — `null` يعني لم يُعتمد بعد. */
  summaryApprovedAt?: string | null;
  /**
   * مصدر **الملخّص** وحده — غير `aiSource` الذي يصف تحليل ما قبل الجلسة.
   * خلطُهما كان يُعيد وسم تحليلٍ نجح بأنّه فاشل. و`null` = لم يُقَس.
   */
  summaryAiSource?: '' | 'ai_success' | 'fallback' | 'manual_required' | 'human_approved' | null;
  /** مادّة جلسة Zoom كما وردت — مفصولة عن الملخّص الذي يصل العميل. */
  zoomSummary?: string | null;
  /** رقم التذكرة الأمّ — **رقماً لا معرّفاً**: مسار التحويل يربط بـ`number`. */
  /**
   * معرّف المحامي المسنَد — `null` يعني **لا إسناد**.
   * ولا يُستدلّ عليه بـ`lawyer` النصّيّ: الخادم يضمن له نائباً («المستشار القانوني»)
   * فلا يخلو أبداً، وشرطُ `!lawyer` كاذبٌ دائماً.
   */
  lawyerId?: number | null;
  ticketNo?: string | null;
  /**
   * رقم القضيّة إن تحوّلت التذكرة — **إشارةُ التحويل الحقيقيّة**.
   * كانت الشاشات تعدّ حالةً `'محولة إلى قضية'` لا يكتبها أيّ مسار، فمؤشّر
   * «استشارة أصبحت قضية» صفرٌ أبداً. والتحويل يقع على التذكرة لا الاستشارة.
   */
  caseNo?: string | null;
  /** تدوين الجلسة (بطاقة المكتب وحدها). */
  sessionNotes?: string | null;
  duration: string | null;
  recording?: string | null; // رابط التسجيل السحابي (بعد الجلسة)
  total: number;
  // دورة الحجز/الدفع (تسعير → فاتورة → دفع محاكى → اختيار الموعد)
  price?: number;
  vat?: number;
  priced?: boolean;
  paid?: boolean;
  paidAgo?: string | null; // «دُفع منذ …» لطلبات الإدارة المعلقة
  invoiceNo?: string | null;
  // رحلة المعالجة (CONSULT_FLOW)
  type: string;
  priority: string;
  received: string;
  employee: string;
  mins: number;
  aiDone: boolean;
  /** App\Enums\AiSource — '' لصفوف ما قبل هجرة المصدر. */
  aiSource: '' | 'ai_success' | 'fallback' | 'manual_required' | 'human_approved';
  aiClass: string;
  aiSummary: string;
  aiLawyer: string;
  missing: string[];
  audit: AuditEntry[];
  decisions: string[];
  tasksCreated: boolean;
  zoomAudioUrl?: string | null;
  zoomShareUrl?: string | null;
}

/**
 * **بطاقة العميل — ما يرسله `Consult::toClientCard` فعلاً، لا أكثر.**
 *
 * كانت صفحة «استشاراتي» تعلن `ConsultCard[]` بينما الخادم يمرّر `toClientCard()`
 * (٢٦ مفتاحاً من ٥٥): فحقولٌ **إلزاميّة** في النوع — `client`, `phone`, `hostLink`,
 * `type`, `priority`, `employee`, `mins`, `aiSummary`, `audit`, `missing` وغيرها —
 * لا تصل صفحة العميل أبداً. لا عطلَ اليوم لأنها لا تُقرأ، **لكنّ TypeScript يضمن
 * وجودها كذباً**: أوّل سطرٍ يقرأ `c.priority` يمرّ الفحص وينكسر في المتصفّح صامتاً.
 *
 * وحجبُ التحليل والمستندات الناقصة وسجلّ التدقيق عن العميل **مقصود** — فالنوع هنا
 * يصف قراراً لا نقصاً.
 */
export interface ClientConsultCard {
  id: number;
  ref: string;
  subject: string;
  specialty?: string;
  channel: string;
  lawyer: string;
  when: string;
  place: string;
  slink: string;
  canJoin?: boolean;
  missed?: boolean;
  session: string;
  status: string;
  /** `null` ما لم يعتمده محامٍ — الحجب في الخادم لا في الواجهة. */
  summary: string | null;
  summaryPending?: boolean;
  summaryApproved?: boolean;
  duration: string | null;
  price?: number;
  vat?: number;
  total: number;
  priced?: boolean;
  paid?: boolean;
  paidAgo?: string | null;
  invoiceNo?: string | null;
  /** تتبع الملخّص في الحجب — كانت تصل قبله. */
  decisions: string[];
  startsAt?: string | null;
}

// فتح جلسة Zoom في تبويب جديد (المكالمة والتسجيل على Zoom)
export function openMeeting(url: string): void {
  if (url) {
window.open(url, '_blank', 'noopener');
}
}

// «أ. سارة القحطاني» → «أ. سارة» (لدور العميل)
export function lawyerFirst(name: string): string {
  const parts = name.trim().split(/\s+/);

  if (/^(أ|د|م|الأستاذ|الأستاذة|المحامي|المحامية)\.?$/.test(parts[0]) && parts.length > 1) {
    return `${parts[0]} ${parts[1]}`;
  }

  return parts[0] || name;
}

// يطابق fmtDur
export function fmtDur(s: number): string {
  const m = Math.floor(s / 60);
  const ss = s % 60;

  return `${m < 10 ? '0' : ''}${m}:${ss < 10 ? '0' : ''}${ss}`;
}

// يطابق vrInitials
export function vrInitials(n: string): string {
  if (!n) {
return '؟';
}

  const p = String(n).trim().split(/\s+/);

  return (p[0] ? p[0][0] : '') + (p[1] ? ` ${p[1][0]}` : '');
}

export const sessTone = (s: string) =>
  s === 'جلسة جارية' ? 'b-amber' : s === 'منتهية' ? 'b-green' : 'b-grey';

// ============================================================
// حالة ملخّص الجلسة — مصدرٌ واحد تقرؤه الشاشات الثلاث
// ============================================================

export type SummaryState = 'none' | 'pending' | 'approved';

/**
 * حالةُ الملخّص كما يقرّرها الخادم — لا كما تشتقّها كلّ شاشة على حدة.
 *
 * كانت شاشة الموظّف تعرض النصّ **بلا أيّ إشارة** إلى أنه غير معتمَد، فيقرأ رأياً
 * قانونياً محجوباً عن الموكّل ويحسبه نهائياً.
 */
export function summaryState(c: Pick<ConsultCard, 'summary' | 'summaryApproved' | 'summaryPending'>): SummaryState {
  if (c.summaryApproved) {
return 'approved';
}

  if (c.summaryPending || c.summary) {
return 'pending';
}

  return 'none';
}

/** وصفُ مصدر النصّ — و«لم يُسجَّل» لا «فشل»: `null` تعني لم يُقَس. */
export function summarySourceLabel(c: Pick<ConsultCard, 'summaryAiSource' | 'summaryEdited'>): string | null {
  if (c.summaryEdited) {
return 'حُرّر بيد محامٍ';
}

  switch (c.summaryAiSource) {
    case 'ai_success': return 'صياغة آليّة';
    case 'fallback': return 'تعذّرت الصياغة الآليّة';
    case 'manual_required': return 'يلزمه تحريرٌ بشريّ';
    case 'human_approved': return 'بصياغة بشريّة';
    default: return null; // لم يُقَس — لا يُدّعى مصدر
  }
}

/** شارةُ حالة الملخّص — تُغني عن اشتقاقٍ محلّي في كل شاشة. */
export const SummaryStateBadge: React.FC<{ consult: ConsultCard }> = ({ consult }) => {
  const state = summaryState(consult);

  if (state === 'none') {
return null;
}

  const source = summarySourceLabel(consult);

  return (
    <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
      <Badge
        tone={state === 'approved' ? 'b-green' : 'b-amber'}
        text={state === 'approved' ? 'معتمَد — وصل الموكّل' : 'غير معتمَد — محجوب عن الموكّل'}
      />
      {source && <Badge tone="b-grey" text={source} />}
    </span>
  );
};

// نافذة ملخص الاستشارة (تُستخدم لدى العميل والمكتب)
// يقرأ المرجع والنصّ وحدهما — فيصلح للبطاقتين
export const SummaryModal: React.FC<{
  consult: Pick<ConsultCard, 'ref' | 'summary'> | null;
  onClose: () => void;
}> = ({ consult, onClose }) => (
  <Modal title={`ملخص الاستشارة — ${consult?.ref ?? ''}`} open={!!consult} onClose={onClose}>
    {consult && (
      <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: '13.5px' }}>
        {consult.summary || 'انتهت الجلسة — يُعدّ الملخص حالياً وسيصلك إشعار فور جاهزيته.'}
      </div>
    )}
  </Modal>
);

// ============================================================
// استقبال الاستشارات — صفحة مشتركة للموظف/المحامي/الإدارة
// يطابق consultRecvView + crStart/crEnd/crSetFilter
// ============================================================

export const ConsultRecvPage: React.FC<{ consults: ConsultCard[]; base: string }> = ({ consults, base }) => {
  const toast = useToast();
  const [filter, setFilter] = useState('all');
  const [summaryOf, setSummaryOf] = useState<ConsultCard | null>(null);
  const [items, setItems] = useState<ConsultCard[]>(consults);

  // تزامن لحظي: الويبهوك/زميل آخر قد يبدّل الجلسة — كانت الشاشة ساكنة فيضغط الموظف «بدء» على جلسة تعمل فعلاً
  useEffect(() => {
    setItems(consults);
    consults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen('.status', (e: { session?: string; status?: string; canJoin?: boolean }) => {
        setItems((prev) => prev.map((x) => x.id === c.id
          ? { ...x, session: e.session ?? x.session, status: e.status ?? x.status, canJoin: e.canJoin ?? x.canJoin }
          : x));

        // الملخّص **لا يُؤخذ من البثّ**: الحمولة نفسها تُبثّ للعميل، فما يراه الطاقم
        // منها هو ما يجوز للعميل رؤيته — أي المعتمَد وحده. والطاقم يحتاج النصّ غير
        // المعتمَد ليراجعه، فيُجلب من الخادم بصلاحيّة الطاقم لا من قناةٍ مشتركة.
        if (e.session === 'منتهية') {
          router.reload({ only: ['consults'] });
        }
      });
    });

    return () => {
 consults.forEach((c) => echo.leave(`consult.${c.id}`)); 
};
     
  }, [consults]);

  const counts: Record<string, number> = { 'مرئية': 0, 'حضورية': 0, 'هاتفية': 0 };
  items.forEach((c) => {
 if (counts[c.channel] != null) {
counts[c.channel]++;
} 
});
  const ended = items.filter((c) => c.session === 'منتهية').length;
  const missedCount = items.filter((c) => c.missed).length;

  const stats: StatItem[] = [
    ['t-blue', 'video', counts['مرئية'], 'مرئية (فيديو)'],
    ['t-green', 'office', counts['حضورية'], 'حضورية'],
    ['t-amber', 'phone', counts['هاتفية'], 'هاتفية'],
    ['t-cyan', 'check', ended, 'منتهية'],
    ['t-red', 'clock', missedCount, 'فائتة'],
  ];

  // يطابق crStart — بدء الجلسة (يبثّ للعميل لحظياً)
  const start = (c: ConsultCard) => {
    const msg = c.channel === 'مرئية' ? 'تم بدء الجلسة المرئية مع العميل'
      : c.channel === 'هاتفية' ? 'تم بدء المكالمة الهاتفية مع العميل'
      : 'تم تسجيل وصول العميل وبدء الجلسة الحضورية';
    router.post(`${base}/consults/${c.id}/start`, {}, {
      preserveScroll: true,
      onSuccess: () => toast(msg),
    });
  };

  // يطابق crEnd — إنهاء الجلسة مع تدوين ما دار فيها.
  //
  // كان يُرسل حمولةً فارغة `{}` ويُقال «ولّد الفريق القانوني ملخص الاستشارة»
  // ولم يُرسل حرفاً — وحقلُ الملاحظات موجود في الغرفة المرئية وحدها، فالحضورية
  // والهاتفية تنتهيان دائماً بلا مادّة. وبعد حارس «بلا مادّة ⇒ لا نداء» صارت
  // النافذة شرطاً لا تحسيناً: بلا تدوين لا ملخّص أصلاً.
  const [endingOf, setEndingOf] = useState<ConsultCard | null>(null);
  const [endNotes, setEndNotes] = useState('');

  const end = () => {
    if (endingOf === null) {
      return;
    }

    const notes = endNotes.trim();

    router.post(`${base}/consults/${endingOf.id}/end`, { notes }, {
      preserveScroll: true,
      onSuccess: () => {
        setEndingOf(null);
        setEndNotes('');
        toast(notes === ''
          ? 'خُتمت الجلسة بلا تدوين — لا ملخّص حتّى تُدوّن ما دار فيها'
          : 'خُتمت الجلسة وحُفظ تدوينك — يُعدّ الملخّص لاعتمادك');
      },
    });
  };

  // دخول غرفة الجلسة المضمّنة كمضيف — وبدء الجلسة إن لم تكن قد بدأت (يبثّ «جارية الآن» للعميل).
  // كان router.visit يُجهض طلب البدء (سباق Inertia) فيدخل الموظف والجلسة لم تبدأ رسمياً
  const enterRoom = (c: ConsultCard) => {
    const room = `${base}/videoroom?ref=${encodeURIComponent(c.ref)}`;

    if (c.session === 'بانتظار الجلسة') {
      router.post(`${base}/consults/${c.id}/start`, {}, {
        preserveScroll: true,
        onSuccess: () => router.visit(room),
        // سبب الرفض من الخادم: «فات الموعد» و«قبل الموعد بربع ساعة» فعلان مختلفان.
        onError: (errors) => toast(Object.values(errors)[0] || 'تعذّر بدء الجلسة'),
      });

      return;
    }

    router.visit(room);
  };

  // وسم «لم يحضر» لاستشارة فائتة — كانت الحيلة الوحيدة (بدء+إنهاء فوري) تزوّر السجل جلسةً منعقدة
  const markNoShow = (c: ConsultCard) => {
    router.post(`${base}/consults/${c.id}/no-show`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('وُسمت الاستشارة «لم يحضر» وأُشعر العميل'),
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر الوسم'}`),
    });
  };

  const reschedule = (c: ConsultCard) => {
    router.post(`${base}/consults/${c.id}/reschedule`, {}, {
      preserveScroll: true,
      onSuccess: () => toast('أُعيدت الاستشارة لاختيار موعد جديد وأُشعر العميل'),
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر إعادة الجدولة'}`),
    });
  };

  const copyLink = (c: ConsultCard) => {
    if (navigator.clipboard) {
void navigator.clipboard.writeText(c.slink);
}

    toast('تم نسخ رابط الاجتماع');
  };

  const tabs: [string, string][] = [...CONSULT_CHANNELS, ['_missed', `فائتة (${missedCount})`]];
  const list = items.filter((c) => (filter === '_missed' ? c.missed : filter === 'all' || c.channel === filter));

  return (
    <>
      <div className="greet">
        <h2>استقبال الاستشارات</h2>
        <p>تكملة رحلة الاستشارة: استقبال الجلسات حسب القناة — مرئية (فيديو) / حضورية / هاتفية — حتى كتابة الملخص.</p>
      </div>

      <StatRow items={stats} />

      <div className="mtabs">
        {tabs.map((t) => (
          <button
            key={t[0]}
            className={`mtab${filter === t[0] ? ' on' : ''}`}
            onClick={() => setFilter(t[0])}
            type="button"
          >
            {t[1]}
          </button>
        ))}
      </div>

      <div className="card">
        <div className="card-h">
          <h3>جلسات الاستشارات</h3>
          <span className="sub">{list.length} استشارة</span>
        </div>
        <div className="card-b">
          {list.length ? list.map((c) => {
            const extra = c.channel === 'حضورية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="pin" /> {c.place}
              </span>
            ) : c.channel === 'هاتفية' ? (
              <span style={{ display: 'block', marginTop: 3, fontSize: '11.5px', color: 'var(--muted)' }}>
                <Icon name="phone" /> {c.phone || '—'}
              </span>
            ) : null;

            return (
              <div key={c.ref} className="item">
                <div className="iico"><Icon name={crChannelIcon(c.channel)} /></div>
                <div className="imeta">
                  <b>{c.ref} — {maskClient(c.client)}</b>
                  <span style={{ display: 'block', margin: '2px 0' }}>
                    {c.subject} · {c.lawyer} · {c.when}
                  </span>
                  {extra}
                </div>
                <div className="iact">
                  <Badge text={c.channel} tone={crChannelTone(c.channel)} />
                  {c.missed ? (
                    /* فات موعدها بلا جلسة — كان زر «بدء» يبقى ظاهراً للأبد بلا أي وسم */
                    <>
                      <Badge text="فائتة — لم تنعقد" tone="b-red" />
                      <button className="btn soft sm" onClick={() => markNoShow(c)} type="button">
                        <Icon name="clock" /> لم يحضر
                      </button>
                      <button className="btn sm" onClick={() => reschedule(c)} type="button">
                        <Icon name="cal" /> إعادة جدولة
                      </button>
                    </>
                  ) : c.session === 'لم تُعقد' ? (
                    <>
                      <Badge text="لم يحضر" tone="b-red" />
                      <button className="btn soft sm" onClick={() => reschedule(c)} type="button">
                        <Icon name="cal" /> إعادة جدولة
                      </button>
                    </>
                  ) : c.session === 'بانتظار الجلسة' ? (
                    c.startable === false ? (
                      /* موعد مستقبلي خارج نافذة البدء — الخادم يسمح بإعادة جدولته والزرّ كان محصوراً بالفائتة */
                      <>
                        <Badge text="مجدولة — البدء قبل الموعد بـ15د" tone="b-grey" />
                        <button className="btn soft sm" onClick={() => reschedule(c)} type="button">
                          <Icon name="cal" /> إعادة جدولة
                        </button>
                      </>
                    ) : c.channel === 'مرئية' ? (
                      <>
                        <button className="btn sm" onClick={() => enterRoom(c)} type="button">
                          <Icon name="video" /> بدء ودخول جلسة Zoom
                        </button>
                        <button className="btn soft sm" onClick={() => copyLink(c)} type="button">
                          <Icon name="link" /> نسخ الرابط
                        </button>
                      </>
                    ) : c.channel === 'هاتفية' ? (
                      <button className="btn sm" onClick={() => start(c)} type="button">
                        <Icon name="phone" /> بدء المكالمة
                      </button>
                    ) : (
                      <button className="btn sm" onClick={() => start(c)} type="button">
                        <Icon name="check" /> تسجيل وصول العميل
                      </button>
                    )
                  ) : c.session === 'جلسة جارية' ? (
                    <>
                      <Badge text="جلسة جارية" tone="b-amber" />
                      {c.channel === 'مرئية' && (
                        <button className="btn soft sm" onClick={() => router.visit(`${base}/videoroom?ref=${encodeURIComponent(c.ref)}`)} type="button">
                          <Icon name="video" /> دخول جلسة Zoom
                        </button>
                      )}
                      <button className="btn sm" onClick={() => { setEndingOf(c); setEndNotes(''); }} type="button">
                        <Icon name="doc" /> إنهاء وكتابة الملخص
                      </button>
                    </>
                  ) : (
                    <>
                      <Badge text="منتهية" tone="b-green" />
                      <button className="btn soft sm" onClick={() => setSummaryOf(c)} type="button">
                        <Icon name="out" /> الملخص
                      </button>
                    </>
                  )}
                </div>
              </div>
            );
          }) : (
            <div className="empty"><Icon name="video" /><b>لا استشارات في هذه القناة</b></div>
          )}
        </div>
      </div>

      {/* نافذة التدوين — مادّة الملخّص الوحيدة للقنوات غير المرئية */}
      <Modal
        title={`إنهاء الجلسة وتدوين ما دار — ${endingOf?.ref ?? ''}`}
        open={!!endingOf}
        onClose={() => setEndingOf(null)}
      >
        <div className="field">
          <label>ما دار في الجلسة (وقائع العميل، ما طُلب، ما تقرّر)</label>
          <textarea
            className="input"
            rows={9}
            value={endNotes}
            onChange={(ev) => setEndNotes(ev.target.value)}
            placeholder="مثال: العميل مقاول من الباطن، لم يُصرَف له مستخلصان منذ أربعة أشهر، ويريد وقف العمل والمطالبة…"
          />
        </div>
        <p className="action-hint">
          <Icon name="info" /> الملخّص يُبنى على تدوينك وحده — وبلا تدوين لا يُكتب شيء، لأنّ ما يُكتب من عنوان الموضوع وحده محضرٌ مختلَق.
        </p>
        <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
          <button className="btn" onClick={end} disabled={endNotes.trim() === ''} type="button">
            <Icon name="doc" /> إنهاء وحفظ التدوين
          </button>
          <button className="btn soft" onClick={end} type="button">
            <Icon name="check" /> إنهاء بلا تدوين
          </button>
        </div>
      </Modal>

      <SummaryModal consult={summaryOf} onClose={() => setSummaryOf(null)} />
    </>
  );
};

// ============================================================
// غرفة الجلسة للمكتب (موظف/محامٍ/إدارة) — استشارة مرئية مضمّنة (Zoom Web SDK)
// ============================================================

export interface StaffRoomProps {
  consult?: ConsultCard | null;
  selfName?: string;
  selfAv?: string;
  base: string; // '/employee' | '/lawyer' | '/admin'
}

// غرفة الجلسة المضمّنة لدور المكتب — فيديو Zoom + بطاقة الملاحظات/الملخص (يطابق vrEnd)
const StaffZoomRoom: React.FC<{ consult: ConsultCard; base: string }> = ({ consult, base }) => {
  const toast = useToast();
  const [notes, setNotes] = useState('');
  const [seconds, setSeconds] = useState(0);

  useEffect(() => {
    const t = setInterval(() => setSeconds((s) => s + 1), 1000);

    return () => clearInterval(t);
  }, []);

  const end = () => {
    const dur = fmtDur(seconds);
    router.post(`${base}/consults/${consult.id}/end`, { notes, duration: dur }, {
      onSuccess: () => {
        toast(`انتهت الجلسة (${dur}) — ولّد الفريق القانوني ملخص الاستشارة`);
        router.visit(`${base}/consultrecv`);
      },
    });
  };

  return (
    <>
      <ZoomEmbedRoom
        cref={consult.ref}
        label={`${consult.ref} · استشارة مرئية`}
        back={`${base}/consultrecv`}
        fallbackUrl={consult.hostLink || consult.slink}
        viewer="staff"
      />
      <div className="card" style={{ marginTop: 16, maxWidth: 900, marginInline: 'auto' }}>
        <div className="card-h">
          <h3>ملاحظات الجلسة</h3>
          <button className="btn sm" onClick={end} type="button">
            <Icon name="doc" /> إنهاء وكتابة الملخص
          </button>
        </div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          <textarea
            className="input"
            rows={3}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="دوّن أبرز نقاط الجلسة المرئية (تُحفظ وتُنقل لصفحة الملخص)…"
          />
        </div>
      </div>
    </>
  );
};

export const StaffVideoRoomPage: React.FC<StaffRoomProps> = ({ consult, base }) => {
  if (!consult) {
    return (
      <div className="card"><div className="card-b">
        <div className="empty"><Icon name="video" /><b>لا توجد جلسة محددة</b></div>
      </div></div>
    );
  }

  // الاستشارة الحقيقية — فيديو Zoom مضمّن + بطاقة الملاحظات/الملخص
  return <StaffZoomRoom consult={consult} base={base} />;
};

// ============================================================
// إدارة الاستشارات — قائمة الرحلة (يطابق emConsultsView + cKPIs)
// ============================================================

// إجراء تسعير الاستشارة (الإدارة العليا) — يُصدر الفاتورة وينقلها إلى «بانتظار السداد»
export const PricingAction: React.FC<{ c: ConsultCard; base: string; toast: (m: string) => void }> = ({ c, base, toast }) => {
  const [price, setPrice] = useState<string>(String(c.price ?? ''));
  const [busy, setBusy] = useState(false);

  const save = () => {
    const val = parseInt(price, 10);

    if (Number.isNaN(val) || val < 0) {
 toast('أدخل سعراً صحيحاً');

 return; 
}

    setBusy(true);
    router.post(`${base}/consults/${c.id}/price`, { price: val }, {
      preserveScroll: true, onSuccess: () => toast('تم تحديد السعر وإصدار الفاتورة'), onFinish: () => setBusy(false),
    });
  };

  return (
    <div style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
      <input className="input" style={{ width: 96 }} type="number" min={0} value={price}
        onChange={(e) => setPrice(e.target.value)} placeholder="السعر" aria-label="سعر الاستشارة" />
      <button className="btn sm" type="button" disabled={busy} onClick={save}>
        <Icon name="card" /> تحديد السعر
      </button>
    </div>
  );
};

export const ConsultsListPage: React.FC<{ consults: ConsultCard[]; base: string }> = ({ consults, base }) => {
  const by = (s: string) => consults.filter((c) => c.status === s).length;
  const done = consults.filter((c) =>
    c.status === 'منتهية' || c.status === 'محولة إلى قضية' || c.status === 'محالة للمحامي'
  ).length;
  const avg = consults.length ? Math.round(consults.reduce((a, c) => a + (c.mins || 0), 0) / consults.length) : 0;

  const stats: StatItem[] = [
    ['t-blue', 'folder', by('جديدة'), 'جديدة'],
    ['t-cyan', 'user', by('قيد مراجعة الموظف'), 'قيد المراجعة'],
    ['t-amber', 'clock', by('بانتظار استكمال البيانات'), 'بانتظار البيانات'],
    ['t-amber', 'check', by('بانتظار اعتماد الموظف'), 'بانتظار الاعتماد'],
    ['t-green', 'scale', by('جاهزة للمحامي'), 'جاهزة للمحامي'],
    ['t-blue', 'exec', done, 'منجزة'],
    ['t-grey', 'clock', `${avg} د`, 'متوسط الزمن'],
  ];

  return (
    <>
      <div className="greet">
        <h2>إدارة الاستشارات</h2>
        <p>لوحة استقبال ومعالجة الاستشارات: المراجعة، الفريق القانوني، الاعتماد، والإحالة للمحامي.</p>
      </div>

      <StatRow items={stats} />

      <div className="card">
        <div className="card-h">
          <h3>الاستشارات</h3>
          <span className="sub">{consults.length} استشارة</span>
        </div>
        <div className="card-b">
          {consults.length ? consults.map((c) => (
            <div key={c.ref} className="item">
              <div className="iico"><Icon name="folder" /></div>
              <div className="imeta">
                <b>{c.ref} — {maskClient(c.client)}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  {c.subject} · {c.type} · {c.received}
                </span>
                {cHasStage(c.status) && <span><FlowLine steps={CONSULT_FLOW} cur={cStage(c.status)} /></span>}
              </div>
              <div className="iact">
                <span className={`mq-priority ${c.priority}`}>{c.priority}</span>
                <Badge text={c.status} tone={cTone(c.status)} />
                <button
                  className="btn soft sm"
                  onClick={() => router.visit(`${base}/consult?ref=${encodeURIComponent(c.ref)}`)}
                  type="button"
                >
                  <Icon name="out" /> فتح
                </button>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="folder" /><b>لا استشارات بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

// ============================================================
// رحلة الاستشارة (يطابق consultView + cTake/cRequestDocs/cRunAI/cSaveAI/cApproveAI/cRerun/cRefer)
// ============================================================

export interface LawyerOpt { id: number; name: string; dept: string; }

export const ConsultJourneyPage: React.FC<{ consult: ConsultCard; base: string; isAdmin?: boolean; lawyers: LawyerOpt[] }> = ({ consult: c, base, isAdmin, lawyers }) => {
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  // مطابقة اقتراح الذكاء الاصطناعي (اسم) بمحامٍ حقيقي — وإلّا فلا اختيار.
  // كان يقع على أوّل محامٍ في القائمة حين لا اقتراح، فيبدو للموظّف ترشيحاً وهو ترتيب أبجديّ.
  const matchLawyer = (name?: string): number | '' => lawyers.find((l) => l.name === name)?.id ?? '';

  // الحقول القابلة للتعديل لتحليل الفريق القانوني (تُزامَن مع الخادم بعد كل إجراء)
  const [aiClass, setAiClass] = useState(c.aiClass);
  const [aiSummary, setAiSummary] = useState(c.aiSummary);
  const [lawyerId, setLawyerId] = useState<number | ''>(matchLawyer(c.aiLawyer));
  const [priority, setPriority] = useState(c.priority);
  useEffect(() => {
    setAiClass(c.aiClass);
    setAiSummary(c.aiSummary);
    setLawyerId(matchLawyer(c.aiLawyer));
    setPriority(c.priority);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [c.aiClass, c.aiSummary, c.aiLawyer, c.priority, lawyers]);

  const lawyerName = lawyers.find((l) => l.id === lawyerId)?.name ?? '';

  const post = (action: string, data: Record<string, string>, msg: string) => {
    setBusy(true);
    router.post(`${base}/consults/${c.id}/${action}`, data, {
      preserveScroll: true,
      onSuccess: () => toast(msg),
      onFinish: () => setBusy(false),
    });
  };

  const take = () => post('take', {}, 'تم استلام الاستشارة لدى الموظف');
  // كانت ترسل حمولةً فارغة، فيصل العميلَ «مستند إضافي مطلوب» بلا بيان — طلبٌ
  // يعلق به ملفّه بانتظار شيءٍ مجهول. والخادم صار يشترط النصّ.
  const requestDocs = () => {
    const what = window.prompt('ما المستند المطلوب من العميل؟')?.trim();

    if (!what) {
      return;
    }

    post('reqdocs', { docs: what }, 'تم طلب استكمال البيانات وإشعار العميل');
  };
  // «اكتمل» تدّعي نجاحاً لا يضمنه الردّ: `Staff\ConsultController::analyze`
  // يعيد `back()` في الحالين، ويكتب في سجلّ التدقيق «تعذّر — يلزم إعداد يدويّ»
  // عند الاحتياطيّ. والعنوان في البطاقة أُصلح ليتبع `aiSource` ولم يُصلح التوست.
  const runAI = () => post('analyze', {}, 'انتهت المعالجة — راجع نتيجتها في البطاقة أدناه');
  const saveAI = () => post('analysis', { aiClass, aiSummary, aiLawyer: lawyerName }, 'تم حفظ التعديلات في سجل التدقيق');
  const approveAI = () => post('approve', {}, 'تم اعتماد التحليل — الاستشارة جاهزة للمحامي');
  const rerun = () => post('analyze', {}, 'أُعيدت المعالجة — راجع نتيجتها في البطاقة أدناه');
  const refer = () => post('refer', { lawyer_id: String(lawyerId) }, `تمت إحالة الاستشارة إلى المحامي: ${lawyerName}`);

  // تحويل قرارات الاستشارة إلى مهام حقيقية (تُستخرج عند إنهاء الجلسة) — لمرة واحدة
  const [tasksDone, setTasksDone] = useState(c.tasksCreated);
  const makeTasks = () => {
    if (tasksDone || c.decisions.length === 0) {
      return;
    }

    setBusy(true);
    router.post(`${base}/consults/${c.id}/tasks`, {}, {
      preserveScroll: true,
      onSuccess: () => {
 setTasksDone(true); toast(`تم تحويل ${c.decisions.length} قرار إلى مهام`); 
},
      onFinish: () => setBusy(false),
    });
  };
  const savePriority = () => post('priority', { priority }, 'تم تحديث الأولوية');

  // محرّر ملخّص الجلسة — النصّ الذي سيصل العميل. كان «تعديل واعتماد» خياراً في
  // صندوق المراجعة **بلا حقلٍ يستقبله**، فيُسجّل «عدّل» والمنشور نصّ النموذج حرفياً.
  const [sessionSummary, setSessionSummary] = useState(c.summary ?? '');

  // نصّ الموكّل يحرّره ويعتمده **من يملك الصلاحيّة** — لا من يفتح الصفحة.
  const mayEditSummary = useCan()('اعتماد/تعديل ملخص الاستشارة');

  useEffect(() => {
    setSessionSummary(c.summary ?? '');
  }, [c.summary]);

  const saveSessionSummary = () => post('summary', { summary: sessionSummary }, 'حُفظ الملخّص المحرّر — يصل العميل بعد اعتماده');
  const approveSessionSummary = () => post('summary/approve', {}, 'اعتُمد الملخّص وأُرسل إلى العميل');

  const showEmpActions = c.status === 'جديدة'
    || c.status === 'قيد مراجعة الموظف'
    || c.status === 'بانتظار استكمال البيانات';
  const showRefer = c.status === 'جاهزة للمحامي';
  // البطاقة سطح تحرير الموظّف — تبقى ظاهرة عند تعذّر التحليل (فهو حينها من يكتب الرأي)،
  // لكن بعنوان صادق: كان الاحتياطيّ يظهر تحت «تحليل الفريق القانوني» كأن تحليلاً وقع.
  const aiFailed = c.aiSource === 'fallback';
  const showAiCard = c.aiDone || aiFailed;
  const showApprove = c.status === 'بانتظار اعتماد الموظف';

  return (
    <div className="detail-wrap" style={{ maxWidth: 920 }}>
      <div style={{ marginBottom: 14 }}>
        <Link href={`${base}/consults`} className="btn soft sm">
          <Icon name="reply" /> رجوع للاستشارات
        </Link>
      </div>

      {/* info */}
      <div className="card">
        <div className="card-h">
          <h3>{c.ref}</h3>
          <Badge text={c.status} tone={cTone(c.status)} />
        </div>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <span className="chip muted">{maskClient(c.client)}</span>
            <span className="chip muted">{c.subject}</span>
            <span className="chip muted">{c.type}</span>
            <span className={`mq-priority ${c.priority}`}>{c.priority}</span>
            <span className="chip muted">استُلمت: {c.received}</span>
            <span className="chip muted">الموظف: {c.employee}</span>
            <span className="chip muted">المحامي: {c.lawyer}</span>
          </div>
        </div>
      </div>

      {/* stage */}
      {cHasStage(c.status) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-b" style={{ padding: '16px 18px' }}>
            <FlowLine steps={CONSULT_FLOW} cur={cStage(c.status)} />
          </div>
        </div>
      )}

      {/* action bar */}
      {(showEmpActions || showRefer) && (
        <div style={{ display: 'flex', gap: 9, margin: '0 0 16px', flexWrap: 'wrap' }}>
          {c.status === 'جديدة' && (
            <button className="btn" onClick={take} disabled={busy} type="button">
              <Icon name="check" /> استلام الاستشارة
            </button>
          )}
          {(c.status === 'قيد مراجعة الموظف' || c.status === 'بانتظار استكمال البيانات') && (
            <>
              <button className="btn soft" onClick={requestDocs} disabled={busy} type="button">
                <Icon name="upload" /> طلب استكمال مستندات
              </button>
              <button className="btn" onClick={runAI} disabled={busy} type="button">
                <Icon name="info" /> {busy ? 'جارٍ التحليل…' : 'بدء معالجة الفريق القانوني'}
              </button>
            </>
          )}
          {showRefer && (
            <button className="btn" onClick={refer} disabled={busy} type="button">
              <Icon name="scale" /> إحالة للمحامي ({lawyerName})
            </button>
          )}
        </div>
      )}

      {/* استعلام يدوي من Zoom API: يسحب كل بيانات الجلسة (الحضور/المدة/التسجيل/النص/الملخص)
          ويحدّث الاستشارة فوراً — لحالات تأخّر الويبهوك أو تعثّر السحب الدوري */}
      {c.session === 'منتهية' && (
        <div style={{ display: 'flex', gap: 9, margin: '0 0 16px', flexWrap: 'wrap' }}>
          <button className="btn soft" onClick={() => post('zoom-sync', {}, 'اكتمل الاستعلام من Zoom — حُدّثت بيانات الجلسة المتوفرة')} disabled={busy} type="button">
            <Icon name="video" /> {busy ? 'جارٍ الاستعلام من Zoom…' : 'تحديث بيانات الجلسة من Zoom'}
          </button>
        </div>
      )}

      {/* AI card */}
      {showAiCard && (
        <>
          <div className="ai-banner">
            <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
            <p>
              {aiFailed
                ? 'تعذّر التحليل الذكيّ لهذه الاستشارة — لم يُنتج النموذج رأياً. يلزم إعداد التصنيف والرأي القانوني واختيار المحامي يدوياً قبل الاعتماد. تُحفظ كل التعديلات في سجل التدقيق.'
                : 'نتائج تحليل الفريق القانوني — يمكن للموظف المخوّل أو الإدارة تعديلها واعتمادها. تُحفظ كل التعديلات في سجل التدقيق.'}
            </p>
          </div>
          <div className="card" style={{ marginBottom: 14 }}>
            <div className="card-h"><h3>{aiFailed ? 'إعداد يدويّ مطلوب — تعذّر التحليل الذكيّ' : 'تحليل الفريق القانوني'}</h3></div>
            <div className="card-b" style={{ padding: '16px 18px' }}>
              <div className="field">
                <label>تصنيف الاستشارة</label>
                <input className="input" value={aiClass} onChange={(e) => setAiClass(e.target.value)} />
              </div>
              <div className="field">
                <label>الملخص القانوني</label>
                <textarea className="input" rows={5} value={aiSummary} onChange={(e) => setAiSummary(e.target.value)} />
              </div>
              <div className="field">
                <label>المحامي المقترح</label>
                <select value={lawyerId} onChange={(e) => setLawyerId(Number(e.target.value))}>
                  {lawyers.length === 0 && <option value="">— لا محامون —</option>}
                  {/* بلا اقتراح صالح: اختيار صريح مطلوب، لا محامٍ مُنتقى ضمناً */}
                  {lawyers.length > 0 && lawyerId === '' && <option value="">— اختر المحامي المختصّ —</option>}
                  {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}{l.dept !== '—' ? ` — ${l.dept}` : ''}</option>)}
                </select>
              </div>
              {c.missing.length > 0 && (
                <div className="action-hint">
                  <Icon name="upload" /> مستندات ناقصة: {c.missing.join('، ')}
                </div>
              )}
              <div style={{ display: 'flex', gap: 9, marginTop: 6, flexWrap: 'wrap' }}>
                <button className="btn soft sm" onClick={saveAI} disabled={busy} type="button">
                  <Icon name="check" /> حفظ التعديلات
                </button>
                {showApprove && (
                  <button className="btn sm" onClick={approveAI} disabled={busy} type="button">
                    <Icon name="check" /> اعتماد التحليل (جاهزة للمحامي)
                  </button>
                )}
                <a className="btn soft sm" href={`/consults/${c.id}/report.pdf`} target="_blank" rel="noopener">
                  <Icon name="download" /> طباعة الملخص (PDF)
                </a>
                <button className="btn soft sm" onClick={rerun} disabled={busy} type="button">
                  <Icon name="info" /> إعادة التحليل
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* admin panel */}
      {isAdmin && (
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="card-h"><h3>تدخّل الإدارة العليا</h3></div>
          <div className="card-b" style={{ padding: '16px 18px' }}>
            <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap', alignItems: 'flex-end' }}>
              <div className="field" style={{ minWidth: 160, margin: 0 }}>
                <label>الأولوية</label>
                <select value={priority} onChange={(e) => setPriority(e.target.value)}>
                  {['عالية', 'متوسطة', 'عادية'].map((p) => <option key={p}>{p}</option>)}
                </select>
              </div>
              <button className="btn soft sm" onClick={savePriority} disabled={busy} type="button">
                <Icon name="check" /> تحديث الأولوية
              </button>
              <div className="field" style={{ minWidth: 200, margin: 0 }}>
                <label>المحامي المختص</label>
                <select value={lawyerId} onChange={(e) => setLawyerId(Number(e.target.value))}>
                  {lawyers.length === 0 && <option value="">— لا محامون —</option>}
                  {/* بلا اقتراح صالح: اختيار صريح مطلوب، لا محامٍ مُنتقى ضمناً */}
                  {lawyers.length > 0 && lawyerId === '' && <option value="">— اختر المحامي المختصّ —</option>}
                  {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}{l.dept !== '—' ? ` — ${l.dept}` : ''}</option>)}
                </select>
              </div>
              <button className="btn sm" onClick={refer} disabled={busy} type="button">
                <Icon name="scale" /> تعيين المحامي واعتماد الإحالة
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ملخّص الجلسة — ما سيقرؤه العميل، يُحرّر قبل الاعتماد ويُقفَل بعده */}
      {c.session === 'منتهية' && (
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="card-h">
            <h3>ملخّص الجلسة (نسخة العميل)</h3>
            <SummaryStateBadge consult={c} />
          </div>
          <div className="card-b" style={{ padding: '16px 18px' }}>
            {c.summaryApproved ? (
              <>
                <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: '13.5px' }}>{c.summary}</div>
                <p className="action-hint" style={{ marginTop: 10 }}>
                  <Icon name="info" /> اعتُمد هذا النصّ وقرأه العميل — تعديله الآن سحبٌ لا حفظ، فهو قرارٌ مستقلّ يُشعَر به صاحبه.
                </p>
              </>
            ) : c.summary ? (
              <>
                {/*
                  **المحرّر خلف الصلاحيّة لا خلف الدور.** كان يُعرض للجميع، ومسار
                  الموظّف غير مسجَّل — فيحرّر نصّه ويضغط الحفظ فيسقط الطلب صامتاً.
                  ومن لا صلاحيّة له يقرأ النصّ وحالته ولا يلمسه، إلّا أن تمنحه
                  الإدارة العليا الصلاحيّة صراحةً.
                */}
                {mayEditSummary ? (
                  <>
                    <div className="field">
                      <label>النصّ الذي سيصل العميل بعد اعتمادك</label>
                      <textarea className="input" rows={10} value={sessionSummary} onChange={(ev) => setSessionSummary(ev.target.value)} />
                    </div>
                    <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
                      <button className="btn soft sm" onClick={saveSessionSummary} disabled={busy || sessionSummary.trim() === ''} type="button">
                        <Icon name="check" /> حفظ الملخّص المحرّر
                      </button>
                      <button className="btn sm" onClick={approveSessionSummary} disabled={busy} type="button">
                        <Icon name="scale" /> اعتماد وإرسال للعميل
                      </button>
                    </div>
                  </>
                ) : (
                  <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: '13.5px' }}>{c.summary}</div>
                )}
                {c.zoomSummary && (
                  <details style={{ marginTop: 12 }}>
                    <summary style={{ cursor: 'pointer', fontWeight: 700 }}>مادّة من جلسة Zoom (للبناء عليها)</summary>
                    <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.9, fontSize: '13px', marginTop: 8, padding: 10, border: '1px solid var(--line-soft)', borderRadius: 8 }}>
                      {c.zoomSummary}
                    </div>
                  </details>
                )}
                <p className="action-hint" style={{ marginTop: 10 }}>
                  <Icon name="info" /> {mayEditSummary
                    ? 'الحفظ لا يُطلق الملخّص — الإطلاق بالاعتماد.'
                    : 'هذا النصّ محجوب عن العميل حتى يعتمده محامٍ مختصّ.'}
                </p>
              </>
            ) : (
              <div className="empty"><Icon name="info" /><b>لا ملخّص بعد</b></div>
            )}
          </div>
        </div>
      )}

      {/* القرارات والمهام — تُستخرج من ملخص الجلسة وتُحوّل لمهام حقيقية */}
      {c.decisions.length > 0 && (
        <div className="card">
          <div className="card-h">
            <h3>القرارات والمهام</h3>
            <button className="btn soft sm" onClick={makeTasks} disabled={busy || tasksDone} type="button">
              <Icon name="check" /> {tasksDone ? 'حُوّلت إلى مهام' : 'تحويل القرارات إلى مهام'}
            </button>
          </div>
          <div className="card-b">
            <ul style={{ margin: 0, paddingInlineStart: 18, lineHeight: 2 }}>
              {c.decisions.map((d, i) => <li key={i}>{decisionText(d)}</li>)}
            </ul>
          </div>
        </div>
      )}

      {/* audit log */}
      <div className="card">
        <div className="card-h">
          <h3>سجل التدقيق (Audit Log)</h3>
          <span className="sub">{c.audit.length}</span>
        </div>
        <div className="card-b">
          {c.audit.length ? c.audit.map((a, i) => (
            <div key={i} className="item">
              <div className="iico"><Icon name="info" /></div>
              <div className="imeta">
                <b>{a.field}</b>
                <span style={{ display: 'block', marginTop: 2 }}>{a.user} · {a.before} ← {a.after}</span>
                <span style={{ color: 'var(--muted)', fontSize: 11 }}>{a.time}</span>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="info" /><b>لا تعديلات بعد</b></div>
          )}
        </div>
      </div>
    </div>
  );
};
