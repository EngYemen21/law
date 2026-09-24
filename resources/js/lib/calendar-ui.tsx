import React, { useMemo } from 'react';
import { UnifiedCalendar, type UnifiedCalendarItem } from '@/components/babylon/UnifiedCalendar';
import { hearingTone } from '@/lib/case-ui';
import { meetStatusTone } from '@/lib/meeting-ui';

// عرض التقويم المشترك والمطور بين أدوار المكتب (المحامي والموظف والإدارة)
// يعتمد على UnifiedCalendar لتوفير تقويم شهري تفاعلي + شريط أسبوعي + أجندة ذكية

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

export const CalendarPage: React.FC<CalendarPageProps> = ({ events, title, subtitle }) => {
  const calendarItems: UnifiedCalendarItem[] = useMemo(() => {
    return events.map((e, idx) => ({
      id: `${e.kindKey}-${e.startsAt ?? 'na'}-${idx}`,
      kind: e.kind,
      kindKey: e.kindKey,
      tone: e.tone,
      title: e.title,
      day: e.day,
      time: e.time,
      where: e.where,
      status: e.status,
      statusTone: statusTone(e),
      startsAt: e.startsAt,
    }));
  }, [events]);

  return (
    <UnifiedCalendar
      items={calendarItems}
      title={title}
      subtitle={subtitle}
      emptyMessage="لا توجد أحداث أو ارتباطات مسجلة في التقويم"
    />
  );
};
