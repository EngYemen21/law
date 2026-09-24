import React, { useState } from 'react';
import { type CalendarEvent, CalendarPage } from '@/lib/calendar-ui';
import Icon from '@/lib/icons';

// التبويب الزمني الموحّد — منظران في صفحة واحدة بدل تبويبين في القائمة الجانبية.
//
// لماذا: كان الزمن موزّعاً على تبويبات متعدّدة في كل لوحة («المواعيد» و«التقويم» للعميل،
// و«جدولة المواعيد» و«التقويم» للموظف)، وكانت شاشة الجدولة **لا تعرض جلسات المحاكم ولا
// الاجتماعات إطلاقاً** — فمن ينظر إليها وحدها قد يحجز موعداً فوق جلسة محكمة دون أن يدري.
//
// والتركيب هنا لا يمسّ المكوّنين: CalendarPage وشاشة القائمة يُصيَّران كما هما، فلا يُعاد
// بناء منطق حجز مختبَر (حرّاس التعارض والماضي وربط التذكرة) لأجل إعادة ترتيب واجهة.

interface Props {
  events: CalendarEvent[];
  feedUrl?: string;
  webcalUrl?: string;
  title: string;
  subtitle: string;
  /** منظر القائمة — شاشة المواعيد القائمة تُمرَّر مُصيَّرة من الصفحة الغلاف. */
  listView?: React.ReactNode;
  listLabel?: string;
  listCount?: number;
}

export const TimeTab: React.FC<Props> = ({
  events, feedUrl, webcalUrl, title, subtitle,
  listView, listLabel = 'المواعيد', listCount,
}) => {
  const [view, setView] = useState<'events' | 'list'>('events');

  // بلا منظر قائمة (المحامي) تبقى الصفحة كما كانت تماماً — لا مبدّل ولا تغيير سلوك
  if (!listView) {
    return <CalendarPage events={events} feedUrl={feedUrl} webcalUrl={webcalUrl} title={title} subtitle={subtitle} />;
  }

  return (
    <>
      <div className="stat-strip" style={{ marginBottom: 16 }} role="tablist" aria-label="منظر التبويب الزمني">
        <button
          type="button" role="tab" aria-selected={view === 'events'}
          className={view === 'events' ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => setView('events')}
        >
          <Icon name="calgrid" /> التقويم والأجندة الشاملة ({events.length})
        </button>
        <button
          type="button" role="tab" aria-selected={view === 'list'}
          className={view === 'list' ? 'btn pri sm' : 'btn soft sm'}
          onClick={() => setView('list')}
        >
          <Icon name="cal" /> {listLabel}{typeof listCount === 'number' ? ` (${listCount})` : ''}
        </button>
      </div>

      {view === 'events'
        ? <CalendarPage events={events} feedUrl={feedUrl} webcalUrl={webcalUrl} title={title} subtitle={subtitle} />
        : listView}
    </>
  );
};
