import React from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { hearingTone } from '@/lib/case-ui';
import Icon from '@/lib/icons';
import { meetStatusTone } from '@/lib/meeting-ui';

// عرض التقويم المشترك بين أدوار المكتب (المحامي والموظف) — نفس نمط consult-ui/case-ui:
// الصفحة رقيقة والعرض هنا، فلا يتكرّر الجدول ولا يختلف شكله بين لوحتين.

export interface CalendarEvent {
  kind: string;
  kindKey: string;
  tone: string;
  title: string;
  day: string | null;
  time: string | null;
  where: string | null;
  status: string;
  /** ختم ISO للفرز الزمني الخادميّ — null للأحداث بلا موعد (تُرتَّب في الذيل). */
  startsAt?: string | null;
}

export interface CalendarPageProps {
  events: CalendarEvent[];
  feedUrl?: string;
  webcalUrl?: string;
  title: string;
  subtitle: string;
}

const statusTone = (e: CalendarEvent): string =>
  e.kindKey === 'meeting' ? meetStatusTone(e.status) : hearingTone(e.status);

export const CalendarPage: React.FC<CalendarPageProps> = ({ events, feedUrl, webcalUrl, title, subtitle }) => {
  const toast = useToast();

  const copyFeed = () => {
    if (!feedUrl) return;
    if (navigator.clipboard) {
      void navigator.clipboard.writeText(feedUrl);
    }
    toast('تم نسخ رابط الاشتراك الحي بتقويمك بنجاح');
  };

  return (
    <>
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2>{title}</h2>
          <p>{subtitle}</p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {webcalUrl && (
            <a className="btn pri sm" href={webcalUrl} title="تفعيل الاشتراك التلقائي لتقويم جوالك">
              <Icon name="bell" /> 📲 تفعيل المزامنة التلقائية لتقويم الجوال
            </a>
          )}
          {feedUrl && (
            <button className="btn soft sm" type="button" onClick={copyFeed} title="مزامنة تلقائية دائمة مع تقويمك على الحاسوب أو الجوال (ICS)">
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
                </tr>
              </thead>
              <tbody>
                {events.map((e, i) => (
                  <tr key={`${e.kindKey}-${e.startsAt ?? 'na'}-${i}`}>
                    <td><Badge text={e.kind} tone={e.tone} /></td>
                    <td><b>{e.title}</b></td>
                    <td className="muted">{e.day || '—'}</td>
                    <td className="muted">{e.time || '—'}</td>
                    <td className="muted">{e.where || '—'}</td>
                    <td><Badge text={e.status} tone={statusTone(e)} /></td>
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
