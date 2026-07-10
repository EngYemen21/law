import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';

// تقويم المحامي — جلسات قضاياه واجتماعاته الحقيقية (من الخادم)

interface Ev { kind: string; tone: string; title: string; day: string | null; time: string | null; where: string | null; status: string; }
interface Props { events: Ev[]; }

const LawyerCalendar: React.FC<Props> = ({ events }) => (
  <>
    <div className="greet">
      <h2>التقويم</h2>
      <p>جلسات قضاياك واجتماعاتك القادمة.</p>
    </div>

    <div className="card">
      <div className="card-h">
        <h3>الأحداث</h3>
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
              </tr>
            </thead>
            <tbody>
              {events.map((e, i) => (
                <tr key={i}>
                  <td><Badge text={e.kind} tone={e.tone} /></td>
                  <td>{e.title}</td>
                  <td className="muted">{e.day || '—'}</td>
                  <td className="muted">{e.time || '—'}</td>
                  <td className="muted">{e.where || '—'}</td>
                  <td><Badge text={e.status} tone="b-grey" /></td>
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

export default LawyerCalendar;
