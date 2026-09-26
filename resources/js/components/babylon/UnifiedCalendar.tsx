import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';

// ============================================================
// المكون الموحد للتقويم والمواعيد والأجندة الذكية (Unified Responsive Calendar)
// يدعم: العرض الشهري التفاعلي + شريط الأسبوع + الأجندة الزمنية الذكية
// متجاوب 100% للشاشات الكبيرة (Split-View) والشاشات الصغيرة (Mobile-First Agenda)
// ============================================================

export interface UnifiedCalendarItem {
    id?: string | number;
    kind: string; // 'جلسة' | 'استشارة' | 'اجتماع' | 'موعد'
    kindKey: string; // 'hearing' | 'consult' | 'meeting' | 'appointment'
    tone: string; // 'b-blue' | 'b-green' | 'b-cyan' | 'b-amber'
    title: string;
    subtitle?: string;
    day: string | null; // 'YYYY-MM-DD' أو نص التاريخ
    time?: string | null;
    where?: string | null;
    status: string;
    statusTone?: string;
    startsAt?: string | null; // ISO string للفرز الزمني
    duration?: string | null; // «المدّة المتوقّعة …» مصاغةً — تُعرض إن وُجدت فقط، فلا نهاية مختلَقة
    joinLink?: string;
    cardUrl?: string;
    actionButton?: React.ReactNode;
}

export interface UnifiedCalendarProps {
    items: UnifiedCalendarItem[];
    title?: string;
    subtitle?: string;
    headerActions?: React.ReactNode;
    topBanner?: React.ReactNode;
    emptyMessage?: string;
    initialDate?: Date;
    onItemClick?: (item: UnifiedCalendarItem) => void;
    feedUrl?: string;
    webcalUrl?: string;
    pager?: React.ReactNode;
    filterToolbar?: React.ReactNode;
    defaultViewMode?: 'calendar' | 'table' | 'agenda';
}

const WEEK_DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const MONTH_NAMES = [
    'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
    'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'
];

/** استخراج مفتاح التاريخ بصيغة YYYY-MM-DD */
function toDateKey(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/** فحص ما إذا كان التاريخ يطابق اليوم الحالي */
function isToday(dateStr: string): boolean {
    return dateStr === toDateKey(new Date());
}

/** لون النقطة الدلالية حسب نوع الحدث */
function getDotColor(kindKey: string): string {
    switch (kindKey) {
        case 'hearing':
            return '#0e5c9c'; // أزرق رسمي لجلسات المحاكم
        case 'consult':
        case 'appointment':
            return '#10b981'; // أخضر للاستشارات والمواعيد
        case 'meeting':
            return '#06b6d4'; // سماوي للاجتماعات
        default:
            return '#f59e0b'; // كهرماني للمهام والحالات الأخرى
    }
}

export const UnifiedCalendar: React.FC<UnifiedCalendarProps> = ({
    items,
    title,
    subtitle,
    headerActions,
    topBanner,
    emptyMessage = 'لا توجد مواعيد أو أحداث مجدولة',
    initialDate,
    onItemClick,
    pager,
    filterToolbar,
    defaultViewMode = 'calendar',
}) => {
    const [currentDate, setCurrentDate] = useState(() => initialDate || new Date());
    const [selectedDayKey, setSelectedDayKey] = useState<string | null>(() => toDateKey(new Date()));
    const [filterKind, setFilterKind] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [viewMode, setViewMode] = useState<'calendar' | 'table' | 'agenda'>(defaultViewMode);

    const todayKey = useMemo(() => toDateKey(new Date()), []);

    // تصفية الأحداث بحسب نوع الفلتر والبحث السريع
    const filteredItems = useMemo(() => {
        let list = items;
        if (filterKind !== 'all') {
            list = list.filter((item) => item.kindKey === filterKind);
        }
        if (searchQuery.trim()) {
            const q = searchQuery.trim().toLowerCase();
            list = list.filter(
                (item) =>
                    item.title?.toLowerCase().includes(q) ||
                    item.subtitle?.toLowerCase().includes(q) ||
                    item.where?.toLowerCase().includes(q) ||
                    item.status?.toLowerCase().includes(q) ||
                    item.day?.toLowerCase().includes(q)
            );
        }
        return list;
    }, [items, filterKind, searchQuery]);

    // تجميع الأحداث حسب يوم التاريخ YYYY-MM-DD
    const itemsByDay = useMemo(() => {
        const map = new Map<string, UnifiedCalendarItem[]>();
        for (const item of filteredItems) {
            if (!item.day) continue;
            // استخراج YYYY-MM-DD حتى لو كان في التاريخ توقيت أو نصوص
            const match = item.day.match(/\d{4}-\d{2}-\d{2}/);
            const key = match ? match[0] : item.day;
            const list = map.get(key) || [];
            list.push(item);
            map.set(key, list);
        }
        return map;
    }, [filteredItems]);

    // إحصائيات سريعة للأنواع
    const counts = useMemo(() => {
        const hearingCount = items.filter((i) => i.kindKey === 'hearing').length;
        const consultCount = items.filter((i) => i.kindKey === 'consult' || i.kindKey === 'appointment').length;
        const meetingCount = items.filter((i) => i.kindKey === 'meeting').length;
        return {
            all: items.length,
            hearing: hearingCount,
            consult: consultCount,
            meeting: meetingCount,
        };
    }, [items]);

    // بيانات شبكة الشهر الحالي
    const { monthGrid, monthLabel, year } = useMemo(() => {
        const y = currentDate.getFullYear();
        const m = currentDate.getMonth();

        const firstDayIndex = new Date(y, m, 1).getDay(); // 0 الأحد ... 6 السبت
        const daysInMonth = new Date(y, m + 1, 0).getDate();
        const daysInPrevMonth = new Date(y, m, 0).getDate();

        const grid: {
            dayNum: number;
            dateKey: string;
            isCurrentMonth: boolean;
            items: UnifiedCalendarItem[];
        }[] = [];

        // الأيام من نهاية الشهر السابق لملء أول سطر
        for (let i = firstDayIndex - 1; i >= 0; i--) {
            const d = daysInPrevMonth - i;
            const prevDate = new Date(y, m - 1, d);
            const key = toDateKey(prevDate);
            grid.push({
                dayNum: d,
                dateKey: key,
                isCurrentMonth: false,
                items: itemsByDay.get(key) || [],
            });
        }

        // أيام الشهر الحالي
        for (let d = 1; d <= daysInMonth; d++) {
            const currDate = new Date(y, m, d);
            const key = toDateKey(currDate);
            grid.push({
                dayNum: d,
                dateKey: key,
                isCurrentMonth: true,
                items: itemsByDay.get(key) || [],
            });
        }

        // إكمال أيام الشهر التالي حتى 35 أو 42 خلية
        const totalSlots = grid.length <= 35 ? 35 : 42;
        const remaining = totalSlots - grid.length;
        for (let d = 1; d <= remaining; d++) {
            const nextDate = new Date(y, m + 1, d);
            const key = toDateKey(nextDate);
            grid.push({
                dayNum: d,
                dateKey: key,
                isCurrentMonth: false,
                items: itemsByDay.get(key) || [],
            });
        }

        return {
            monthGrid: grid,
            monthLabel: `${MONTH_NAMES[m]} ${y}`,
            year: y,
        };
    }, [currentDate, itemsByDay]);

    // قائمة أحداث اليوم المحدد (أو كل الأحداث إذا لم يُحدد يوم)
    const selectedDayItems = useMemo(() => {
        if (!selectedDayKey) return filteredItems;
        return itemsByDay.get(selectedDayKey) || [];
    }, [selectedDayKey, itemsByDay, filteredItems]);

    // التنقل بين الشهور
    const prevMonth = () => {
        setCurrentDate((d) => new Date(d.getFullYear(), d.getMonth() - 1, 1));
    };
    const nextMonth = () => {
        setCurrentDate((d) => new Date(d.getFullYear(), d.getMonth() + 1, 1));
    };
    const goToday = () => {
        const now = new Date();
        setCurrentDate(now);
        setSelectedDayKey(toDateKey(now));
    };

    // شريط الأيام المصغر للشاشات الصغيرة
    const miniWeekDays = useMemo(() => {
        const center = selectedDayKey ? new Date(selectedDayKey) : new Date();
        const days = [];
        for (let offset = -3; offset <= 3; offset++) {
            const d = new Date(center);
            d.setDate(center.getDate() + offset);
            const key = toDateKey(d);
            days.push({
                name: WEEK_DAYS[d.getDay()],
                dayNum: d.getDate(),
                key,
                count: (itemsByDay.get(key) || []).length,
            });
        }
        return days;
    }, [selectedDayKey, itemsByDay]);

    return (
        <div className="unified-calendar-container" style={{ width: '100%' }}>
            {/* 1. الترويسة الرئيسية والإجراءات */}
            {(title || headerActions) && (
                <div
                    className="greet"
                    style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'flex-start',
                        flexWrap: 'wrap',
                        gap: 12,
                        marginBottom: 16,
                    }}
                >
                    <div>
                        {title && <h2 style={{ margin: 0 }}>{title}</h2>}
                        {subtitle && <p style={{ margin: '4px 0 0 0', color: 'var(--muted)' }}>{subtitle}</p>}
                    </div>

                    {headerActions && (
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                            {headerActions}
                        </div>
                    )}
                </div>
            )}

            {/* بنر إداري أو تنبيهات علوية إن وجدت */}
            {topBanner && <div style={{ marginBottom: 16 }}>{topBanner}</div>}

            {/* شريط ترشيح إضافي إن وجد */}
            {filterToolbar && <div style={{ marginBottom: 16 }}>{filterToolbar}</div>}

            {/* 2. شريط أدوات التصفية والبحث والتبديل */}
            <div
                className="card"
                style={{
                    marginBottom: 16,
                    padding: '12px 16px',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    flexWrap: 'wrap',
                    gap: 12,
                }}
            >
                {/* أزرار تصفية النوع السريعة */}
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                    <button
                        type="button"
                        className={filterKind === 'all' ? 'chip b-blue' : 'chip'}
                        style={{ cursor: 'pointer', fontWeight: filterKind === 'all' ? 700 : 500 }}
                        onClick={() => setFilterKind('all')}
                    >
                        الكل ({counts.all})
                    </button>
                    <button
                        type="button"
                        className={filterKind === 'hearing' ? 'chip b-blue' : 'chip'}
                        style={{ cursor: 'pointer', fontWeight: filterKind === 'hearing' ? 700 : 500 }}
                        onClick={() => setFilterKind('hearing')}
                    >
                        🏛️ جلسات المحاكم ({counts.hearing})
                    </button>
                    <button
                        type="button"
                        className={filterKind === 'consult' || filterKind === 'appointment' ? 'chip b-green' : 'chip'}
                        style={{ cursor: 'pointer', fontWeight: filterKind === 'consult' ? 700 : 500 }}
                        onClick={() => setFilterKind('consult')}
                    >
                        ⚖️ الاستشارات والمواعيد ({counts.consult})
                    </button>
                    {counts.meeting > 0 && (
                        <button
                            type="button"
                            className={filterKind === 'meeting' ? 'chip b-cyan' : 'chip'}
                            style={{ cursor: 'pointer', fontWeight: filterKind === 'meeting' ? 700 : 500 }}
                            onClick={() => setFilterKind('meeting')}
                        >
                            🤝 اجتماعات العمل ({counts.meeting})
                        </button>
                    )}
                </div>

                {/* البحث السريع ومبدل نمط العرض (الجديد vs القديم) */}
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                    {/* حقل البحث السريع */}
                    <div style={{ position: 'relative', minWidth: 190 }}>
                        <span
                            style={{
                                position: 'absolute',
                                right: 9,
                                top: '50%',
                                transform: 'translateY(-50%)',
                                color: 'var(--muted)',
                                pointerEvents: 'none',
                                display: 'flex',
                                alignItems: 'center',
                            }}
                        >
                            <Icon name="search" />
                        </span>
                        <input
                            type="search"
                            placeholder="بحث في الارتباطات..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            style={{
                                width: '100%',
                                padding: '6px 30px 6px 10px',
                                fontSize: 12.5,
                                borderRadius: 8,
                                border: '1px solid var(--line-soft)',
                                background: 'var(--paper-2)',
                                color: 'var(--ink)',
                            }}
                        />
                    </div>

                    {/* مبدل نمط العرض (Segmented Toggle) */}
                    <div
                        style={{
                            display: 'flex',
                            gap: 2,
                            background: 'var(--paper-2)',
                            padding: 3,
                            borderRadius: 8,
                            border: '1px solid var(--line-soft)',
                        }}
                    >
                        <button
                            type="button"
                            className={`btn sm ${viewMode === 'calendar' ? 'pri' : 'ghost'}`}
                            style={{ padding: '4px 10px', fontSize: 12, fontWeight: viewMode === 'calendar' ? 700 : 500 }}
                            onClick={() => setViewMode('calendar')}
                            title="عرض التقويم الشهري التفاعلي والأجندة الذكية (التصميم الحديث)"
                        >
                            <Icon name="calgrid" /> تقويم تفاعلي
                        </button>
                        <button
                            type="button"
                            className={`btn sm ${viewMode === 'table' ? 'pri' : 'ghost'}`}
                            style={{ padding: '4px 10px', fontSize: 12, fontWeight: viewMode === 'table' ? 700 : 500 }}
                            onClick={() => setViewMode('table')}
                            title="عرض جدول الأحداث والارتباطات المفصل (التصميم الكلاسيكي)"
                        >
                            <Icon name="doc" /> جدول الارتباطات
                        </button>
                        <button
                            type="button"
                            className={`btn sm ${viewMode === 'agenda' ? 'pri' : 'ghost'}`}
                            style={{ padding: '4px 10px', fontSize: 12, fontWeight: viewMode === 'agenda' ? 700 : 500 }}
                            onClick={() => {
                                setViewMode('agenda');
                                setSelectedDayKey(null);
                            }}
                            title="عرض قائمة الأجندة الزمنية"
                        >
                            <Icon name="cal" /> قائمة الأجندة
                        </button>
                    </div>
                </div>
            </div>

            {/* 3. العرض الرئيسي (جدول الارتباطات الكلاسيكي أو التقويم التفاعلي) */}
            {viewMode === 'table' ? (
                <div className="card">
                    <div
                        className="card-h"
                        style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            padding: '12px 16px',
                            borderBottom: '1px solid var(--line-soft)',
                        }}
                    >
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <Icon name="calgrid" />
                            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800 }}>جدول الأحداث والارتباطات</h3>
                        </div>
                        <span className="sub" style={{ fontSize: 12 }}>
                            {filteredItems.length} حدث {searchQuery ? `(مطابق للبحث)` : ''}
                        </span>
                    </div>
                    <div className="card-b t-wrap" style={{ padding: 0 }}>
                        {filteredItems.length ? (
                            <table className="tbl" style={{ minWidth: 700 }}>
                                <thead>
                                    <tr>
                                        <th style={{ width: 110 }}>النوع</th>
                                        <th>العنوان والتفاصيل</th>
                                        <th style={{ width: 130 }}>اليوم والتاريخ</th>
                                        <th style={{ width: 90 }}>الوقت</th>
                                        <th style={{ minWidth: 150 }}>المكان / الجهة</th>
                                        <th style={{ width: 110 }}>الحالة</th>
                                        <th style={{ width: 120, textAlign: 'center' }}>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredItems.map((item, i) => (
                                        <tr
                                            key={item.id || i}
                                            className={onItemClick ? 'click' : ''}
                                            onClick={() => onItemClick && onItemClick(item)}
                                        >
                                            <td><Badge text={item.kind} tone={item.tone} /></td>
                                            <td>
                                                <b style={{ color: 'var(--deep)' }}>{item.title}</b>
                                                {item.subtitle && <div className="sub" style={{ fontSize: 11.5, marginTop: 2 }}>{item.subtitle}</div>}
                                            </td>
                                            <td className="muted" style={{ whiteSpace: 'nowrap' }}>{item.day || '—'}</td>
                                            <td className="muted" style={{ whiteSpace: 'nowrap' }}>{item.time || '—'}</td>
                                            <td className="muted">{item.where || '—'}</td>
                                            <td><Badge text={item.status} tone={item.statusTone || 'b-muted'} /></td>
                                            <td style={{ textAlign: 'center' }}>
                                                <div style={{ display: 'flex', gap: 6, justifyContent: 'center' }} onClick={(e) => e.stopPropagation()}>
                                                    {item.joinLink && (
                                                        <a
                                                            className="btn pri sm"
                                                            href={item.joinLink}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            title="دخول الجلسة"
                                                            style={{ padding: '3px 8px', fontSize: 11 }}
                                                        >
                                                            <Icon name="video" /> الغرفة
                                                        </a>
                                                    )}
                                                    {item.cardUrl && (
                                                        <a
                                                            className="btn soft sm"
                                                            href={item.cardUrl}
                                                            title="عرض التفاصيل"
                                                            style={{ padding: '3px 8px', fontSize: 11 }}
                                                        >
                                                            <Icon name="doc" /> التفاصيل
                                                        </a>
                                                    )}
                                                    {item.actionButton}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <div className="empty" style={{ padding: '36px 16px' }}>
                                <Icon name="cal" />
                                <b>{searchQuery ? 'لا توجد نتائج مطابقة لبحثك' : emptyMessage}</b>
                                {searchQuery && (
                                    <button
                                        type="button"
                                        className="btn soft sm"
                                        style={{ marginTop: 8 }}
                                        onClick={() => setSearchQuery('')}
                                    >
                                        مسح البحث
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                    {pager && (
                        <div style={{ borderTop: '1px solid var(--line-soft)', padding: '8px 16px' }}>
                            {pager}
                        </div>
                    )}
                </div>
            ) : (
                <div
                    className="calendar-main-layout"
                    style={{
                        display: 'grid',
                        gridTemplateColumns: viewMode === 'calendar' ? 'repeat(auto-fit, minmax(min(100%, 360px), 1fr))' : '1fr',
                        gap: 16,
                        alignItems: 'start',
                    }}
                >
                    {/* ── الجانب الأيمن: التقويم الشهري التفاعلي ── */}
                    {viewMode === 'calendar' && (
                        <div className="card" style={{ overflow: 'hidden' }}>
                            {/* شريط التحكم بالشهر */}
                            <div
                                className="card-h"
                                style={{
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'center',
                                    padding: '12px 16px',
                                    borderBottom: '1px solid var(--line-soft)',
                                }}
                            >
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800 }}>{monthLabel}</h3>
                                    <button
                                        type="button"
                                        className="btn soft sm"
                                        style={{ fontSize: 11, padding: '2px 8px' }}
                                        onClick={goToday}
                                    >
                                        اليوم
                                    </button>
                                </div>

                                <div style={{ display: 'flex', gap: 4 }}>
                                    <button
                                        type="button"
                                        className="btn soft sm"
                                        style={{ width: 32, height: 32, padding: 0 }}
                                        onClick={prevMonth}
                                        title="الشهر السابق"
                                    >
                                        ▶
                                    </button>
                                    <button
                                        type="button"
                                        className="btn soft sm"
                                        style={{ width: 32, height: 32, padding: 0 }}
                                        onClick={nextMonth}
                                        title="الشهر التالي"
                                    >
                                        ◀
                                    </button>
                                </div>
                            </div>

                            {/* شريط الأسبوع السريع للهواتف والشاشات الصغيرة (< 640px) */}
                            <div
                                className="mobile-week-strip"
                                style={{
                                    display: 'none',
                                    overflowX: 'auto',
                                    padding: '10px 8px',
                                    borderBottom: '1px solid var(--line-soft)',
                                    background: 'var(--paper-2)',
                                    gap: 6,
                                }}
                            >
                                {miniWeekDays.map((d) => {
                                    const isSel = d.key === selectedDayKey;
                                    const isTd = d.key === todayKey;
                                    return (
                                        <button
                                            key={d.key}
                                            type="button"
                                            onClick={() => setSelectedDayKey(d.key)}
                                            style={{
                                                flex: '0 0 68px',
                                                display: 'flex',
                                                flexDirection: 'column',
                                                alignItems: 'center',
                                                padding: '8px 4px',
                                                borderRadius: 10,
                                                border: isSel ? '2px solid var(--brand)' : '1px solid var(--line-soft)',
                                                background: isSel ? 'var(--paper)' : isTd ? 'rgba(14, 92, 156, 0.08)' : 'transparent',
                                                cursor: 'pointer',
                                            }}
                                        >
                                            <span style={{ fontSize: 11, color: isSel ? 'var(--brand)' : 'var(--muted)', fontWeight: 600 }}>
                                                {d.name}
                                            </span>
                                            <span style={{ fontSize: 16, fontWeight: 800, color: isSel ? 'var(--brand)' : 'var(--ink)' }}>
                                                {d.dayNum}
                                            </span>
                                            {d.count > 0 && (
                                                <span
                                                    style={{
                                                        fontSize: 10,
                                                        fontWeight: 700,
                                                        background: 'var(--brand)',
                                                        color: '#fff',
                                                        borderRadius: 8,
                                                        padding: '1px 6px',
                                                        marginTop: 4,
                                                    }}
                                                >
                                                    {d.count}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* شبكة التقويم الشهرية المتكاملة */}
                            <div className="card-b" style={{ padding: 12 }}>
                                {/* ترويسة أيام الأسبوع */}
                                <div
                                    style={{
                                        display: 'grid',
                                        gridTemplateColumns: 'repeat(7, 1fr)',
                                        textAlign: 'center',
                                        marginBottom: 8,
                                        fontWeight: 700,
                                        fontSize: 12,
                                        color: 'var(--muted)',
                                    }}
                                >
                                    {WEEK_DAYS.map((w) => (
                                        <div key={w} style={{ padding: '4px 0' }}>{w}</div>
                                    ))}
                                </div>

                                {/* خلايا الأيام */}
                                <div
                                    style={{
                                        display: 'grid',
                                        gridTemplateColumns: 'repeat(7, 1fr)',
                                        gap: 4,
                                    }}
                                >
                                    {monthGrid.map((cell, idx) => {
                                        const isSel = cell.dateKey === selectedDayKey;
                                        const isTd = cell.dateKey === todayKey;
                                        const hasEvents = cell.items.length > 0;

                                        return (
                                            <div
                                                key={`${cell.dateKey}-${idx}`}
                                                onClick={() => setSelectedDayKey(cell.dateKey)}
                                                style={{
                                                    minHeight: 58,
                                                    padding: '6px 4px',
                                                    borderRadius: 8,
                                                    border: isSel
                                                        ? '2px solid var(--brand)'
                                                        : isTd
                                                            ? '1.5px solid var(--cyan)'
                                                            : '1px solid var(--line-soft)',
                                                    background: isSel
                                                        ? 'rgba(14, 92, 156, 0.08)'
                                                        : !cell.isCurrentMonth
                                                            ? 'var(--paper-2)'
                                                            : 'var(--paper)',
                                                    opacity: cell.isCurrentMonth ? 1 : 0.45,
                                                    cursor: 'pointer',
                                                    display: 'flex',
                                                    flexDirection: 'column',
                                                    justifyContent: 'space-between',
                                                    transition: 'all 0.15s ease',
                                                }}
                                                title={`${cell.dateKey}: ${cell.items.length} أحداث`}
                                            >
                                                {/* رقم اليوم */}
                                                <div
                                                    style={{
                                                        display: 'flex',
                                                        justifyContent: 'space-between',
                                                        alignItems: 'center',
                                                        padding: '0 2px',
                                                    }}
                                                >
                                                    <span
                                                        style={{
                                                            fontSize: 12.5,
                                                            fontWeight: isTd || isSel ? 800 : 600,
                                                            color: isSel ? 'var(--brand)' : isTd ? 'var(--cyan)' : 'var(--ink)',
                                                        }}
                                                    >
                                                        {cell.dayNum}
                                                    </span>
                                                    {isTd && (
                                                        <span
                                                            style={{
                                                                fontSize: 9,
                                                                fontWeight: 700,
                                                                background: 'var(--cyan)',
                                                                color: '#fff',
                                                                borderRadius: 4,
                                                                padding: '1px 3px',
                                                            }}
                                                        >
                                                            اليوم
                                                        </span>
                                                    )}
                                                </div>

                                                {/* مؤشرات الأحداث الملونة */}
                                                {hasEvents && (
                                                    <div style={{ display: 'flex', gap: 3, flexWrap: 'wrap', marginTop: 3 }}>
                                                        {cell.items.slice(0, 3).map((item, i) => (
                                                            <span
                                                                key={i}
                                                                style={{
                                                                    width: 7,
                                                                    height: 7,
                                                                    borderRadius: '50%',
                                                                    backgroundColor: getDotColor(item.kindKey),
                                                                    display: 'inline-block',
                                                                }}
                                                            />
                                                        ))}
                                                        {cell.items.length > 3 && (
                                                            <span style={{ fontSize: 9, fontWeight: 700, color: 'var(--muted)', lineHeight: 1 }}>
                                                                +{cell.items.length - 3}
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* ── الجانب الأيسر: أجندة المواعيد والأحداث ── */}
                    <div className="card" style={{ height: '100%' }}>
                        <div
                            className="card-h"
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                padding: '12px 16px',
                                borderBottom: '1px solid var(--line-soft)',
                            }}
                        >
                            <div>
                                <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800 }}>
                                    {viewMode === 'calendar'
                                        ? selectedDayKey
                                            ? `أجندة يوم ${selectedDayKey}`
                                            : 'أجندة الأحداث'
                                        : 'كافة المواعيد والارتباطات'}
                                </h3>
                                <span className="sub" style={{ fontSize: 11.5 }}>
                                    {selectedDayItems.length} ارتباط مسجل
                                </span>
                            </div>

                            {selectedDayKey && (
                                <button
                                    type="button"
                                    className="btn soft sm"
                                    style={{ fontSize: 11 }}
                                    onClick={() => setSelectedDayKey(null)}
                                >
                                    عرض الكل
                                </button>
                            )}
                        </div>

                        <div className="card-b" style={{ padding: 14 }}>
                            {selectedDayItems.length === 0 ? (
                                <div className="empty" style={{ padding: '36px 16px' }}>
                                    <Icon name="cal" />
                                    <b>{emptyMessage}</b>
                                    <span className="muted" style={{ fontSize: 12, marginTop: 4 }}>
                                        لا توجد أي جلسات أو استشارات مسجلة في هذا اليوم.
                                    </span>
                                </div>
                            ) : (
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                    {selectedDayItems.map((item, idx) => (
                                        <div
                                            key={item.id || idx}
                                            onClick={() => onItemClick && onItemClick(item)}
                                            style={{
                                                border: '1px solid var(--line-soft)',
                                                borderRadius: 10,
                                                padding: 12,
                                                background: 'var(--paper)',
                                                transition: 'transform 0.15s ease, box-shadow 0.15s ease',
                                                cursor: onItemClick ? 'pointer' : 'default',
                                                position: 'relative',
                                                borderInlineStart: `4px solid ${getDotColor(item.kindKey)}`,
                                            }}
                                        >
                                            {/* السطر الأول: شارة النوع + الوقت + الحالة */}
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    justifyContent: 'space-between',
                                                    alignItems: 'center',
                                                    flexWrap: 'wrap',
                                                    gap: 6,
                                                    marginBottom: 6,
                                                }}
                                            >
                                                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    <Badge text={item.kind} tone={item.tone} />
                                                    {item.time && (
                                                        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--ink)' }}>
                                                            ⏰ {item.time}
                                                        </span>
                                                    )}
                                                </div>

                                                <Badge text={item.status} tone={item.statusTone || 'b-muted'} />
                                            </div>

                                            {/* عنوان الحدث */}
                                            <div style={{ fontWeight: 800, fontSize: 13.5, color: 'var(--deep)', marginBottom: 4 }}>
                                                {item.title}
                                            </div>

                                            {/* تفاصيل إضافية: المكان / المستشار / العميل */}
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    flexWrap: 'wrap',
                                                    gap: 12,
                                                    fontSize: 11.5,
                                                    color: 'var(--muted)',
                                                    marginTop: 4,
                                                }}
                                            >
                                                {item.day && (
                                                    <span>📅 {item.day}</span>
                                                )}
                                                {item.where && (
                                                    <span>📍 {item.where}</span>
                                                )}
                                                {item.duration && (
                                                    <span>⏱ {item.duration}</span>
                                                )}
                                                {item.subtitle && (
                                                    <span>👤 {item.subtitle}</span>
                                                )}
                                            </div>

                                            {/* روابط سريعة: دخول الاجتماع المرئي أو بطاقة الموعد */}
                                            {(item.joinLink || item.cardUrl || item.actionButton) && (
                                                <div
                                                    style={{
                                                        display: 'flex',
                                                        gap: 6,
                                                        marginTop: 10,
                                                        paddingTop: 8,
                                                        borderTop: '1px dashed var(--line-soft)',
                                                        flexWrap: 'wrap',
                                                    }}
                                                    onClick={(e) => e.stopPropagation()}
                                                >
                                                    {item.joinLink && (
                                                        <a
                                                            className="btn pri sm"
                                                            href={item.joinLink}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            style={{ fontSize: 11.5, padding: '4px 10px' }}
                                                        >
                                                            <Icon name="video" /> دخول الجلسة
                                                        </a>
                                                    )}
                                                    {item.cardUrl && (
                                                        <a
                                                            className="btn soft sm"
                                                            href={item.cardUrl}
                                                            style={{ fontSize: 11.5, padding: '4px 10px' }}
                                                        >
                                                            <Icon name="doc" /> بطاقة الموعد
                                                        </a>
                                                    )}
                                                    {item.actionButton}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default UnifiedCalendar;
