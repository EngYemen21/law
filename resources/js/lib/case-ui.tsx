import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import type { ConfirmRequest } from '@/components/babylon/ConfirmDialog';
import MsgMeta from '@/components/babylon/MsgMeta';
import { type Message } from '@/lib/chat';

// أدوات واجهة القضية المشتركة (تطابق CaseJourney::LIFE في الخادم)

export const CASE_LIFE = ['تفعيل القضية', 'خطة العمل واللائحة', 'رفع الدعوى ومتابعة الجلسات', 'الحكم', 'الإغلاق والأرشفة'];

export function caseStage(status: string): number {
  switch (status) {
    case 'بانتظار اعتماد الأتعاب':
    case 'بانتظار سداد الأتعاب': return 0;
    case 'قيد التحضير': return 1;
    case 'بانتظار القيد':
    case 'منظورة': return 2;
    case 'صدر الحكم': return 3;
    case 'مغلقة':
    case 'مؤرشفة': return 4;
    default: return 0;
  }
}

export interface Hearing {
  id: number; title: string; day: string; time?: string | null;
  court?: string | null; status: string; outcome?: string | null; startsAt?: string | null;
  // المدّة المتوقّعة بالدقائق كما أدخلها الطاقم — null ⇒ لا نهاية ولا مدّة تُعرض (لا رقمَ مختلَق)
  durationMin?: number | null;
  endsAt?: string | null;
  // جلسة «مجدولة» فات موعدها بلا نتيجة — الحالة المخزّنة لا تتحدّث بمرور الوقت (يشتقها الخادم)
  lapsed?: boolean;
  // سلسلة التأجيل: الجلسة التي أُجّلت إلى هذه — تُوجد في القائمة نفسها بمعرّفها
  postponedFromId?: number | null;
  // ما يجوز على الجلسة يقرّره الخادم (HearingStatus) — لا مقارنة لنصوص الحالة هنا
  canRecord?: boolean;
  canEdit?: boolean;
  canCancel?: boolean;
}

/**
 * حدود «المدّة المتوقّعة» في نماذج الجلسة — مرآة `CaseHearing::DURATION_MIN/MAX`. الحارس الخادم
 * (`CaseHearing::durationRule`)؛ وهذه تُعين المتصفّح على منع الخطأ قبل الإرسال فقط.
 */
/** أرشفة القضيّة — تُغلق الملفّ نهائياً فيصير للقراءة (قرار المالك 2026-09-27: لا تقع بنقرةٍ عابرة). */
export const CONFIRM_ARCHIVE_CASE: ConfirmRequest = {
  title: 'أرشفة ملف القضية؟',
  message: 'يُنقل الملف إلى الأرشيف فيصير للقراءة فقط: لا جلسات ولا مستندات ولا رسائل جديدة عليه.',
  confirmLabel: 'أرشفة الملف',
  cancelLabel: 'تراجع',
  tone: 'danger',
};

export const HEARING_DURATION = { min: 5, max: 600 } as const;

/**
 * صياغة المدّة المتوقّعة للجلسة — مصدرٌ واحد لبطاقات القضيّة والتقويم والتبويب الزمنيّ.
 * لا مدّة ⇒ null: لا يُعرض شيء، فلا تُوحي الواجهة بنهايةٍ لا يعرفها أحد (قرار المالك 2026-09-26).
 */
export const hearingDurationLabel = (min?: number | null): string | null =>
  min ? `المدّة المتوقّعة ${min} دقيقة` : null;

/** نغمة حالة الجلسة — مصدر وحيد (يستعملها تقويم المحامي أيضاً) */
export const hearingTone = (s: string): string =>
  s === 'منعقدة' ? 'b-green'
    : s === 'مؤجلة' ? 'b-amber'
    : s === 'ملغاة' ? 'b-grey'
    // حالات فائتة تُشتقّ حيّاً في App\Support\EventStatus — بلا هذين السطرين تسقط
    // للافتراضي الأزرق فتُلوَّن جلسة فائتة كأنها عادية.
    : s === 'فائتة — بانتظار النتيجة' ? 'b-red'
    : s === 'لم تنعقد' ? 'b-red'
    : 'b-blue';

export interface HearingDoc {
  id: number;
  name: string;
  hearingId?: number | null;
  downloadUrl?: string | null;
}

export const HearingsCard: React.FC<{ hearings: Hearing[]; documents?: HearingDoc[] }> = ({ hearings, documents = [] }) => (
  <div className="card">
    <div className="card-h"><h3>الجلسات</h3><span className="sub">{hearings.length}</span></div>
    <div className="card-b">
      {hearings.length ? hearings.map((h) => {
        const linkedDocs = documents.filter((d) => d.hearingId === h.id);

        return (
          <div key={h.id} className="item" style={{ flexDirection: 'column', alignItems: 'stretch', gap: 6 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <div className="iico"><Icon name="cal" /></div>
                <div className="imeta">
                  <b>{h.title}</b>
                  <span>{h.day}{h.time ? ` · ${h.time}` : ''}{h.court ? ` · ${h.court}` : ''}</span>
                  {hearingDurationLabel(h.durationMin) && <span>{hearingDurationLabel(h.durationMin)}</span>}
                  {h.outcome && <span style={{ display: 'block', color: 'var(--muted)', marginTop: 3 }}>{h.outcome}</span>}
                </div>
              </div>
              <div className="iact">
                {h.lapsed
                  ? <Badge text="فائتة — بانتظار النتيجة" tone="b-amber" />
                  : <Badge text={h.status} tone={hearingTone(h.status)} />}
              </div>
            </div>

            {linkedDocs.length > 0 && (
              <div style={{ paddingRight: 38, display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                <span style={{ fontSize: 11, color: 'var(--muted)' }}>المذكرات والمرفقات:</span>
                {linkedDocs.map((ld) => (
                  <span
                    key={ld.id}
                    style={{
                      fontSize: 11,
                      background: 'var(--paper-2)',
                      padding: '2px 8px',
                      borderRadius: 4,
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 4,
                      border: '1px solid var(--line-soft)',
                    }}
                  >
                    <Icon name="doc" /> {ld.name}
                  </span>
                ))}
              </div>
            )}
          </div>
        );
      }) : (
        <div className="empty"><Icon name="cal" /><b>لا جلسات بعد</b></div>
      )}
    </div>
  </div>
);

// عارض رسالة القضية (يطابق نمط محادثة التذكرة)
export const CaseMsgRow: React.FC<{ m: Message }> = ({ m }) => {
  const isClient = m.who === 'client' || m.who === 'me';
  const actor = isClient ? 'me' : 'ai';
  // المخرج الآليّ يُوسَم «ردّ آليّ» كما في محادثة التذكرة — الوسم واحد أينما ظهر
  const isAuto = m.who === 'ai';
  return (
    <div className={`msg ${actor}`}>
      <div className={`av ${actor}`}>{isClient ? 'ع' : <img src="/images/mono.jpg" alt="" />}</div>
      <div className="bubble-wrap">
        <div className="who">
          <b>{isClient ? 'العميل' : isAuto ? 'خدمة العملاء' : m.name}</b>
          {(isAuto || m.role) && <span className={`role ${actor}`}>{isAuto ? 'ردّ آليّ' : m.role}</span>}
          <time>{m.time}</time>
        </div>
        <div className="bubble" dangerouslySetInnerHTML={{ __html: m.text }} />
        <MsgMeta m={m} />
      </div>
    </div>
  );
};
