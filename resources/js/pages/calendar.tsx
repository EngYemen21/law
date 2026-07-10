import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';

// تقويم العميل — أحداثه الحقيقية مع رابط «أضف إلى تقويم جوجل» لكل حدث

interface Ev { kind: string; tone: string; title: string; day: string | null; time: string | null; where: string | null; status: string; gcal: string; }
interface Props { events: Ev[]; }

const Calendar: React.FC<Props> = ({ events }) => (
  <>
    <div className="greet">
      <h2>التقويم</h2>
      <p>مواعيدك واجتماعاتك القادمة — أضِف أيّاً منها إلى تقويم جوجل بضغطة.</p>
    </div>

    <div className="card">
      <div className="card-h"><h3>الأحداث</h3><span className="sub">{events.length} حدث</span></div>
      <div className="card-b">
        {events.length ? events.map((e, i) => (
          <div key={i} className="item">
            <div className="iico"><Icon name={e.kind === 'اجتماع' ? 'video' : 'cal'} /></div>
            <div className="imeta">
              <b>{e.title}</b>
              <span>{[e.day, e.time, e.where].filter(Boolean).join(' · ') || '—'}</span>
            </div>
            <div className="iact" style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <Badge text={e.status} tone={e.tone} />
              <a className="btn soft sm" href={e.gcal} target="_blank" rel="noopener noreferrer">
                <Icon name="calplus" /> أضف إلى جوجل
              </a>
            </div>
          </div>
        )) : (
          <div className="empty"><Icon name="cal" /><b>لا أحداث في تقويمك بعد</b></div>
        )}
      </div>
    </div>
  </>
);

export default Calendar;
