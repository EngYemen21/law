import React from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

// تقويم العميل — أحداثه الحقيقية مع روابط تقويم جوجل والمزامنة الحية (Webcal & Live Feed)

interface Ev {
  kind: string;
  tone: string;
  title: string;
  day: string | null;
  time: string | null;
  where: string | null;
  status: string;
  gcal: string;
}

interface Props {
  events: Ev[];
  feedUrl?: string;
  webcalUrl?: string;
}

const Calendar: React.FC<Props> = ({ events, feedUrl, webcalUrl }) => {
  const toast = useToast();

  const copyFeed = () => {
    if (!feedUrl) return;
    if (navigator.clipboard) {
      void navigator.clipboard.writeText(feedUrl);
    }
    toast('تم نسخ رابط الاشتراك في تقويم جوجل بنجاح');
  };

  return (
    <>
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2>التقويم والمواعيد</h2>
          <p>مواعيدك واستشاراتك وجلساتك القادمة — متزامنة لحظياً وتصلك تنبيهاتها تلقائياً.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {webcalUrl && (
            <a className="btn pri sm" href={webcalUrl} title="تفعيل الاشتراك المباشر لتقويم جوالك">
              <Icon name="bell" /> 📲 تفعيل المزامنة التلقائية لتقويم الجوال
            </a>
          )}
          {feedUrl && (
            <button className="btn soft sm" type="button" onClick={copyFeed} title="مزامنة حية دائمة مع تقويم جوجل">
              <Icon name="link" /> نسخ رابط Google Calendar Feed
            </button>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>الأحداث والمواعيد</h3>
          <span className="sub">{events.length} حدث</span>
        </div>
        <div className="card-b">
          {events.length ? events.map((e, i) => (
            <div key={i} className="item">
              <div className="iico">
                <Icon name={e.kind === 'اجتماع' ? 'video' : e.kind === 'جلسة قضية' ? 'scale' : 'cal'} />
              </div>
              <div className="imeta">
                <b>{e.title}</b>
                <span>{[e.day, e.time, e.where].filter(Boolean).join(' · ') || '—'}</span>
              </div>
              <div className="iact" style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <Badge text={e.status} tone={e.tone} />
                {e.gcal && (
                  <a className="btn soft sm" href={e.gcal} target="_blank" rel="noopener noreferrer">
                    <Icon name="calplus" /> أضف إلى جوجل
                  </a>
                )}
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="cal" /><b>لا أحداث في تقويمك بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default Calendar;
