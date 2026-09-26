import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';

/**
 * **من يتولّى المحادثة الآن، ومن تولّاها قبله.** (طلب المالك 2026-09-25)
 *
 * «الموظّف لا يُحتفظ بمن الذي أدار المحادثات» — صار للمحادثة مسؤولٌ يتولّاها تلقائيّاً من يردّ من
 * الطاقم، وسجلُّ تسليمٍ واستلام. مكوّنٌ واحد لكلّ صفحات الطاقم (التذكرة · القضيّة · التنفيذ)،
 * والبيانات من الخادم (`ConversationHandler::history`) — شاشات العميل لا تستلمها أصلاً.
 */
export interface ConversationHistory {
  current: { id: number; name: string; since: string | null } | null;
  history: { to: string; from: string | null; at: string; via: string | null }[];
}

/** كُتّاب المكتب: رسالةٌ حيّة من أحدهم قد تنقل المحادثة (الحكم في الخادم لا هنا). */
const OFFICE_WRITERS = ['staff', 'lawyer', 'admin'];

/**
 * **الزميل يرى انتقال المحادثة دون تحديث الصفحة.** يُنادى من مستمع الرسائل الحيّة: رسالةٌ من المكتب
 * قد تكون نقلت المسؤوليّة، فيُعاد جلب `conversation` وحده من الخادم — لا يُخمَّن المسؤول في الواجهة.
 */
export function refreshConversationOn(who: string | undefined): void {
  if (who && OFFICE_WRITERS.includes(who)) {
    refreshConversation();
  }
}

/** يُعاد جلب المسؤول وسجلّه وحدهما — بعد ردٍّ أرسلتُه أنا أيضاً، فلا يتّكئ على البثّ اللحظيّ. */
export function refreshConversation(): void {
  router.reload({ only: ['conversation'] });
}

/** أوّل ما يُعرض من السجلّ — والباقي خلف «عرض الكلّ»: السجلّ يطول في الملفّات القديمة. */
const PREVIEW = 3;

const ConversationHandlerCard: React.FC<{ conversation?: ConversationHistory | null }> = ({ conversation }) => {
  const [expanded, setExpanded] = useState(false);

  if (!conversation) {
    return null;
  }

  const { current, history } = conversation;
  const shown = expanded ? history : history.slice(0, PREVIEW);

  return (
    <div className="card">
      <div className="tc-top">
        <div className="lbl">المحادثة</div>
      </div>
      <div className="tc-body">
        <div className="tc-row">
          <span className="k">المسؤول الآن</span>
          <span className="v">{current ? current.name : 'لم يتولّها أحدٌ بعد'}</span>
        </div>
        {current?.since && (
          <div className="tc-row">
            <span className="k">منذ</span>
            <span className="v">{current.since}</span>
          </div>
        )}

        {history.length > 0 && (
          <div style={{ marginTop: 10 }}>
            <div style={{ fontWeight: 700, fontSize: 12.5, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 6 }}>
              <Icon name="clock" /> سجلّ التسليم والاستلام
            </div>
            <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 6 }}>
              {shown.map((h, i) => (
                <li key={`${h.at}-${i}`} style={{ fontSize: 12, lineHeight: 1.6, borderInlineStart: '2px solid var(--line, #e2e8f0)', paddingInlineStart: 8 }}>
                  <b>{h.to}</b> {h.from ? <>تولّاها بعد <b>{h.from}</b></> : 'تولّاها أوّلاً'}
                  {h.via && <span style={{ color: 'var(--muted)' }}> · عبر «{h.via}»</span>}
                  <div style={{ color: 'var(--muted)', fontSize: 11 }}>{h.at}</div>
                </li>
              ))}
            </ol>
            {history.length > PREVIEW && (
              <button type="button" className="btn ghost sm" style={{ marginTop: 6 }} onClick={() => setExpanded((v) => !v)}>
                {expanded ? 'عرض أقلّ' : `عرض الكلّ (${history.length})`}
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

export default ConversationHandlerCard;
