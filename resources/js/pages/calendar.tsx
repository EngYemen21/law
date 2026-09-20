import React, { useState } from 'react';
import { useToast } from '@/components/babylon/Toast';
import { type Appt } from '@/lib/data';
import Icon from '@/lib/icons';
import {
  type TimelineEvent, type TimelineFilters, type TimelineMeta,
  TimelinePager, TimelineTable, TimelineToolbar,
} from '@/lib/timeline-ui';
import Appointments from '@/pages/appointments';

// التبويب الزمني الموحّد للعميل — منظران:
//   • «الأحداث»: كل الأنواع (مواعيد · استشارات · جلسات · اجتماعات) بترشيح وبحث وتصفيح خادميّة.
//   • «مواعيدي»: نوع واحد ببطاقته الغنيّة (QR · بطاقة PDF · حالة السداد · رابط الجلسة).
//
// كانت الصفحة تُحمّل الأنواع الأربعة كاملةً في حمولة واحدة ثم تدمجها الواجهة: لا بحث ولا
// ترشيح، وحجم الحمولة ينمو مع عمر الحساب بلا سقف.

interface Props {
  events: TimelineEvent[];
  meta: TimelineMeta;
  counts: Record<string, number>;
  statuses: string[];
  filters: TimelineFilters;
  appointments?: Appt[];
  feedUrl?: string;
  webcalUrl?: string;
}

const ClientCalendar: React.FC<Props> = ({
  events, meta, counts, statuses, filters, appointments, feedUrl, webcalUrl,
}) => {
  const [view, setView] = useState<'events' | 'list'>('events');
  const toast = useToast();

  const copyFeed = () => {
    if (!feedUrl) return;
    if (navigator.clipboard) void navigator.clipboard.writeText(feedUrl);
    toast('تم نسخ رابط الاشتراك الحي بتقويمك بنجاح');
  };

  return (
    <>
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2>التقويم والمواعيد</h2>
          <p>مواعيدك واستشاراتك وجلساتك واجتماعاتك — مع المزامنة مع تقويم جوالك.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {webcalUrl && (
            <a className="btn pri sm" href={webcalUrl} title="تفعيل الاشتراك التلقائي لتقويم جوالك">
              <Icon name="bell" /> 📲 تفعيل المزامنة التلقائية
            </a>
          )}
          {feedUrl && (
            <button className="btn soft sm" type="button" onClick={copyFeed} title="مزامنة دائمة مع تقويمك على الحاسوب أو الجوال (ICS)">
              <Icon name="link" /> نسخ رابط Live Feed
            </button>
          )}
        </div>
      </div>

      <div className="stat-strip" style={{ marginBottom: 4 }} role="tablist" aria-label="منظر التبويب الزمني">
        <button
          type="button" role="tab" aria-selected={view === 'events'}
          className={view === 'events' ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => setView('events')}
        >
          <Icon name="calgrid" /> الأحداث ({meta.total})
        </button>
        <button
          type="button" role="tab" aria-selected={view === 'list'}
          className={view === 'list' ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => setView('list')}
        >
          <Icon name="cal" /> مواعيدي{appointments ? ` (${appointments.length})` : ''}
        </button>
      </div>

      {view === 'events' ? (
        <>
          <TimelineToolbar filters={filters} counts={counts} statuses={statuses} />
          <div className="card">
            <div className="card-h">
              <h3>الأحداث والارتباطات</h3>
              <span className="sub">{meta.total} حدث</span>
            </div>
            <div className="card-b">
              <TimelineTable events={events} />
              <TimelinePager meta={meta} filters={filters} />
            </div>
          </div>
        </>
      ) : (
        <Appointments appointments={appointments ?? []} />
      )}
    </>
  );
};

export default ClientCalendar;
