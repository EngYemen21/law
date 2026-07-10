import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import MsgMeta from '@/components/babylon/MsgMeta';
import { type Message } from '@/lib/chat';

// أدوات واجهة طلب التنفيذ المشتركة (تطابق ExecJourney::LIFE في الخادم)

export const EXEC_LIFE = ['فتح الطلب', 'تجهيز السند التنفيذي', 'القيد لدى محكمة التنفيذ', 'إجراءات التنفيذ', 'التحصيل والإغلاق'];

export function execStage(status: string): number {
  switch (status) {
    case 'جديد':
    case 'قيد الفتح': return 0;
    case 'تجهيز السند التنفيذي': return 1;
    case 'مقيّد لدى محكمة التنفيذ': return 2;
    case 'جارٍ': return 3;
    case 'مكتمل':
    case 'مغلق': return 4;
    default: return 0;
  }
}

export interface Procedure {
  id: number; title: string; type: string; detail?: string | null; status: string;
}

const procTone = (s: string): string =>
  s === 'منفّذ' ? 'b-green' : s === 'مؤجل' ? 'b-amber' : 'b-blue';

export const ProceduresCard: React.FC<{ procedures: Procedure[] }> = ({ procedures }) => (
  <div className="card">
    <div className="card-h"><h3>إجراءات التنفيذ</h3><span className="sub">{procedures.length}</span></div>
    <div className="card-b">
      {procedures.length ? procedures.map((p) => (
        <div key={p.id} className="item">
          <div className="iico"><Icon name="exec" /></div>
          <div className="imeta">
            <b>{p.title}</b>
            <span>{p.type}{p.detail ? ` · ${p.detail}` : ''}</span>
          </div>
          <div className="iact"><Badge text={p.status} tone={procTone(p.status)} /></div>
        </div>
      )) : (
        <div className="empty"><Icon name="exec" /><b>لا إجراءات بعد</b></div>
      )}
    </div>
  </div>
);

// عارض رسالة التنفيذ (يطابق نمط محادثة القضية)
export const ExecMsgRow: React.FC<{ m: Message }> = ({ m }) => {
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
