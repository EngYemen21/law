import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import MsgMeta from '@/components/babylon/MsgMeta';
import { type Message } from '@/lib/chat';

// أدوات واجهة القضية المشتركة (تطابق CaseJourney::LIFE في الخادم)

export const CASE_LIFE = ['تفعيل القضية', 'خطة العمل واللائحة', 'رفع الدعوى ومتابعة الجلسات', 'الحكم', 'الإغلاق والأرشفة'];

export function caseStage(status: string): number {
  switch (status) {
    case 'بانتظار اعتماد الأتعاب':
    case 'بانتظار سداد الأتعاب': return 0;
    case 'قيد التحضير': return 1;
    case 'منظورة': return 2;
    case 'صدر الحكم': return 3;
    case 'مغلقة':
    case 'مؤرشفة': return 4;
    default: return 0;
  }
}

export interface Hearing {
  id: number; title: string; day: string; time?: string | null;
  court?: string | null; status: string; outcome?: string | null;
}

/** نغمة حالة الجلسة — مصدر وحيد (يستعملها تقويم المحامي أيضاً) */
export const hearingTone = (s: string): string =>
  s === 'منعقدة' ? 'b-green' : s === 'مؤجلة' ? 'b-amber' : 'b-blue';

export const HearingsCard: React.FC<{ hearings: Hearing[] }> = ({ hearings }) => (
  <div className="card">
    <div className="card-h"><h3>الجلسات</h3><span className="sub">{hearings.length}</span></div>
    <div className="card-b">
      {hearings.length ? hearings.map((h) => (
        <div key={h.id} className="item">
          <div className="iico"><Icon name="cal" /></div>
          <div className="imeta">
            <b>{h.title}</b>
            <span>{h.day}{h.time ? ` · ${h.time}` : ''}{h.court ? ` · ${h.court}` : ''}</span>
            {h.outcome && <span style={{ display: 'block', color: 'var(--muted)', marginTop: 3 }}>{h.outcome}</span>}
          </div>
          <div className="iact"><Badge text={h.status} tone={hearingTone(h.status)} /></div>
        </div>
      )) : (
        <div className="empty"><Icon name="cal" /><b>لا جلسات بعد</b></div>
      )}
    </div>
  </div>
);

// عارض رسالة القضية (يطابق نمط محادثة التذكرة)
export const CaseMsgRow: React.FC<{ m: Message }> = ({ m }) => {
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
