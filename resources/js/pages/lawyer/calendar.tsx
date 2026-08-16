import React from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { hearingTone } from '@/lib/case-ui';
import Icon from '@/lib/icons';
import { meetStatusTone } from '@/lib/meeting-ui';

// تقويم المحامي — جلسات قضاياه واجتماعاته واستشاراته الحقيقية مع روابط تقويم جوجل والاشتراك الحي (Webcal & Live Feed)

interface Ev {
  kind: string;
  kindKey: string;
  tone: string;
  title: string;
  day: string | null;
  time: string | null;
  where: string | null;
  status: string;
  gcal?: string;
}

interface Props {
  events: Ev[];
  feedUrl?: string;
  webcalUrl?: string;
}

const statusTone = (e: Ev): string =>
  e.kindKey === 'meeting' ? meetStatusTone(e.status) : hearingTone(e.status);

const LawyerCalendar: React.FC<Props> = ({ events, feedUrl, webcalUrl }) => {
  const toast = useToast();

  const copyFeed = () => {
    if (!feedUrl) return;
    if (navigator.clipboard) {
      void navigator.clipboard.writeText(feedUrl);
    }
    toast('تم نسخ رابط الاشتراك الحي لتقويم جوجل بنجاح');
  };

  return (
    <>
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2>تقويم المحامي</h2>
          <p>جلسات قضاياك، اجتماعاتك، واستشاراتك القانونية المكلّف بها — متزامنة لحظياً.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {webcalUrl && (
            <a className="btn pri sm" href={webcalUrl} title="تفعيل الاشتراك التلقائي لتقويم جوالك">
              <Icon name="bell" /> 📲 تفعيل المزامنة التلقائية لتقويم الجوال
            </a>
          )}
          {feedUrl && (
            <button className="btn soft sm" type="button" onClick={copyFeed} title="مزامنة تلقائية دائمة مع تقويم جوجل أو جوالك">
              <Icon name="link" /> نسخ رابط Live Feed
            </button>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>الأحداث والارتباطات</h3>
          <span className="sub">{events.length} حدث</span>
        </div>
        <div className="card-b t-wrap">
          {events.length ? (
            <table className="tbl">
              <thead>
                <tr>
                  <th>النوع</th>
                  <th>العنوان</th>
                  <th>اليوم</th>
                  <th>الوقت</th>
                  <th>المكان / الجهة</th>
                  <th>الحالة</th>
                  <th>تقويم جوجل</th>
                </tr>
              </thead>
              <tbody>
                {events.map((e, i) => (
                  <tr key={i}>
                    <td><Badge text={e.kind} tone={e.tone} /></td>
                    <td><b>{e.title}</b></td>
                    <td className="muted">{e.day || '—'}</td>
                    <td className="muted">{e.time || '—'}</td>
                    <td className="muted">{e.where || '—'}</td>
                    <td><Badge text={e.status} tone={statusTone(e)} /></td>
                    <td>
                      {e.gcal && (
                        <a className="btn soft sm" href={e.gcal} target="_blank" rel="noopener noreferrer" title="إضافة للتقويم">
                          <Icon name="calplus" /> أضف لجوجل
                        </a>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="cal" /><b>لا أحداث في تقويمك بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default LawyerCalendar;
