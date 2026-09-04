import { router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import StatRow from '@/components/babylon/StatRow';
import type {StatItem} from '@/components/babylon/StatRow';
import TimeSlotPicker from '@/components/babylon/TimeSlotPicker';
import { useToast } from '@/components/babylon/Toast';
import { todayISO } from '@/components/SpecialistPicker';
import Icon from '@/lib/icons';

// الشاشة تُعرض داخل تقويم الموظف وتقويم الإدارة معًا — العناوين تُشتق من اللوحة الحالية
// (لم يعد الأدمن يمرّ عبر بوابة دور الموظف — قرار 2026-08-28، له مسارات admin مطابقة)
const apiBase = () => (window.location.pathname.startsWith('/admin') ? '/admin' : '/employee');

// ============================================================
// لوحة جدولة وإدارة مواعيد المكتب للموظف (Enterprise Scheduling Hub)
// تتيح متابعة تفرغ المستشارين، شبكة المواعيد، الجدولة الذكية، وتفاصيل المواعيد
// ============================================================

export interface ClientItem {
  id: number;
  name: string;
  phone?: string;
  email?: string;
}

export interface LawyerItem {
  id: number;
  name: string;
  dept?: string;
}

export interface AppointmentItem {
  id: string; // ext_id
  type: string;
  ico: string;
  lawyer: string;
  lawyerId?: number | null;
  clientId?: number | null;
  day: string;
  time: string;
  place: string;
  status: string;
  tone: string;
  when: string; // 'up' | 'past'
  client?: string;
  consultRef?: string;
  consultId?: number;
  pay?: string;
  gcal?: string;
  joinLink?: string;
  rawStartsAt?: string | null;
  rawDate?: string | null;
  rawTime?: string | null;
  phone?: string;
  channel?: string;
  subject?: string;
}

interface Counts {
  today?: number;
  upcoming?: number;
  video?: number;
  office?: number;
}

interface Props {
  clients: ClientItem[];
  lawyers: LawyerItem[];
  appointments?: AppointmentItem[];
  counts?: Counts;
}

const TYPES: [string, string, string][] = [
  ['office', 'حضورية', 'office'],
  ['video', 'مرئية', 'video'],
  ['phone', 'هاتفية', 'phone'],
];

// الساعات المعروضة في شبكة التقويم اليومي للمستشارين
const DAY_HOURS = [
  '09:00', '10:00', '11:00', '12:00', '13:00',
  '14:00', '15:00', '16:00', '17:00', '18:00',
  '19:00', '20:00', '21:00', '22:00'
];

const EmployeeSchedule: React.FC<Props> = ({
  clients = [],
  lawyers = [],
  appointments = [],
  counts,
}) => {
  const toast = useToast();

  // نمط العرض: تقويم وتفرغ الفريق | قائمة المواعيد
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');

  // اليوم المختار في عرض الشبكة / التقويم
  const [selectedDay, setSelectedDay] = useState(todayISO());

  // التصفية والبحث
  const [searchQuery, setSearchQuery] = useState('');
  const [filterLawyer, setFilterLawyer] = useState<string>('all');
  const [filterChannel, setFilterChannel] = useState<string>('all');
  const [filterStatus, setFilterStatus] = useState<string>('all');

  // موعد مختار لعرض التفاصيل
  const [selectedAppt, setSelectedAppt] = useState<AppointmentItem | null>(null);

  // مودال حجز موعد جديد
  const [bookOpen, setBookOpen] = useState(false);
  const [clientId, setClientId] = useState<number | ''>(clients[0]?.id ?? '');
  const [clientSearch, setClientSearch] = useState('');
  const [lawyerId, setLawyerId] = useState<number | ''>(lawyers[0]?.id ?? '');
  const [type, setType] = useState('office');
  const [subject, setSubject] = useState('');
  const [date, setDate] = useState(todayISO());
  const [time, setTime] = useState('');
  const [busy, setBusy] = useState(false);

  // الفترات المتاحة للمستشار في المودال
  const [slots, setSlots] = useState<{ time: string; taken: boolean }[]>([]);
  const [slotsLoading, setSlotsLoading] = useState(false);

  // جلب الفترات عند تغيير المستشار أو التاريخ في المودال
  useEffect(() => {
    if (!bookOpen || !lawyerId || !date) {
      setSlots([]);

      return;
    }

    let cancelled = false;
    setSlotsLoading(true);
    fetch(`${apiBase()}/schedule/slots?lawyer_id=${lawyerId}&date=${date}`, {
      headers: { Accept: 'application/json' },
    })
      .then((r) => r.json())
      .then((j: { slots?: { time: string; taken: boolean }[] }) => {
        if (!cancelled) {
setSlots(j.slots ?? []);
}
      })
      .catch(() => {
        if (!cancelled) {
setSlots([]);
}
      })
      .finally(() => {
        if (!cancelled) {
setSlotsLoading(false);
}
      });

    return () => {
      cancelled = true;
    };
  }, [bookOpen, lawyerId, date]);

  // أوقات اليوم المنقضية
  const nowHM = () => new Date().toTimeString().slice(0, 5);
  const isPast = date === todayISO() && time !== '' && time <= nowHM();

  // فتح نافذة الحجز مع تحديد المحامي واليوم والوقت مسبقاً من الشبكة
  const openBookingForSlot = (targetLawyerId: number, targetDate: string, targetTime: string) => {
    setLawyerId(targetLawyerId);
    setDate(targetDate);
    setTime(targetTime);
    setBookOpen(true);
  };

  const openNewBooking = () => {
    if (clients.length > 0 && clientId === '') {
setClientId(clients[0].id);
}

    if (lawyers.length > 0 && lawyerId === '') {
setLawyerId(lawyers[0].id);
}

    setDate(todayISO());
    setTime('');
    setSubject('');
    setBookOpen(true);
  };

  // تأكيد الجدولة
  const submitBooking = () => {
    if (!clientId || !date || !time) {
      toast('يرجى اختيار العميل والتاريخ والوقت');

      return;
    }

    if (isPast) {
      toast('لا يمكن اختيار وقت ماضٍ، فضلاً اختر وقتاً لاحقاً');

      return;
    }

    setBusy(true);
    router.post(
      `${apiBase()}/schedule`,
      {
        client_id: clientId,
        lawyer_id: lawyerId || null,
        type,
        subject: subject.trim(),
        date,
        time,
      },
      {
        preserveScroll: true,
        onFinish: () => setBusy(false),
        onSuccess: () => {
          toast('✅ تم إنشاء وحفظ الموعد بنجاح');
          setBookOpen(false);
          setSubject('');
          setTime('');
        },
        onError: (e) => toast(e.starts_at || e.time || e.date || 'تعذّر إنشاء الموعد'),
      }
    );
  };

  // التنقل بين الأيام في التقويم
  const shiftDay = (deltaDays: number) => {
    const curr = new Date(selectedDay);
    curr.setDate(curr.getDate() + deltaDays);
    const y = curr.getFullYear();
    const m = String(curr.getMonth() + 1).padStart(2, '0');
    const d = String(curr.getDate()).padStart(2, '0');
    setSelectedDay(`${y}-${m}-${d}`);
  };

  // تنسيق التاريخ العربي المقروء
  const formattedSelectedDay = useMemo(() => {
    try {
      const parts = selectedDay.split('-');

      if (parts.length === 3) {
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

        return d.toLocaleDateString('ar-SA', {
          weekday: 'long',
          year: 'numeric',
          month: 'long',
          day: 'numeric',
        });
      }
    } catch {
      // fallback
    }

    return selectedDay;
  }, [selectedDay]);

  // فلترة قائمة العملاء داخل المودال بالاسم أو الجوال
  const filteredModalClients = useMemo(() => {
    if (!clientSearch.trim()) {
return clients;
}

    const q = clientSearch.trim().toLowerCase();

    return clients.filter(
      (c) =>
        c.name.toLowerCase().includes(q) ||
        (c.phone && c.phone.includes(q)) ||
        (c.email && c.email.toLowerCase().includes(q))
    );
  }, [clients, clientSearch]);

  // فلترة المواعيد للعرض بالقائمة أو الشبكة
  const filteredAppointments = useMemo(() => {
    return appointments.filter((a) => {
      // تصفية المحامي
      if (filterLawyer !== 'all' && String(a.lawyerId) !== filterLawyer && a.lawyer !== filterLawyer) {
        return false;
      }

      // تصفية القناة
      if (filterChannel !== 'all' && (a.channel || a.type) !== filterChannel) {
        return false;
      }

      // تصفية الحالة
      if (filterStatus !== 'all') {
        if (filterStatus === 'up' && a.when !== 'up') {
return false;
}

        if (filterStatus === 'past' && a.when !== 'past') {
return false;
}

        if (filterStatus === 'attended' && a.status !== 'تم الحضور') {
return false;
}

        if (filterStatus === 'noshow' && a.status !== 'لم يحضر') {
return false;
}
      }

      // البحث النصي
      if (searchQuery.trim()) {
        const q = searchQuery.trim().toLowerCase();
        const clientMatch = a.client?.toLowerCase().includes(q) ?? false;
        const lawyerMatch = a.lawyer?.toLowerCase().includes(q) ?? false;
        const subMatch = a.subject?.toLowerCase().includes(q) ?? false;
        const idMatch = a.id?.toLowerCase().includes(q) ?? false;
        const phoneMatch = a.phone?.includes(q) ?? false;

        if (!clientMatch && !lawyerMatch && !subMatch && !idMatch && !phoneMatch) {
          return false;
        }
      }

      return true;
    });
  }, [appointments, filterLawyer, filterChannel, filterStatus, searchQuery]);

  // إحصائيات المواعيد
  const calculatedCounts = useMemo(() => {
    const todayCount = appointments.filter((a) => a.rawDate === todayISO()).length;
    const upCount = appointments.filter((a) => a.when === 'up').length;
    const vidCount = appointments.filter((a) => a.channel === 'مرئية' || a.ico === 'video').length;
    const offCount = appointments.filter((a) => a.channel === 'حضورية' || a.ico === 'office').length;

    return {
      today: counts?.today ?? todayCount,
      upcoming: counts?.upcoming ?? upCount,
      video: counts?.video ?? vidCount,
      office: counts?.office ?? offCount,
    };
  }, [appointments, counts]);

  const stats: StatItem[] = [
    ['t-cyan', 'cal', calculatedCounts.today, 'مواعيد اليوم'],
    ['t-blue', 'clock', calculatedCounts.upcoming, 'مواعيد قادمة'],
    ['t-green', 'video', calculatedCounts.video, 'جلسات مرئية'],
    ['t-amber', 'office', calculatedCounts.office, 'استشارات حضورية'],
  ];

  // المحامون المعروضون في شبكة التقويم (حسب الفلتر)
  const gridLawyers = useMemo(() => {
    if (filterLawyer === 'all') {
return lawyers;
}

    return lawyers.filter((l) => String(l.id) === filterLawyer || l.name === filterLawyer);
  }, [lawyers, filterLawyer]);

  // خريطة سريعة للمواعيد في اليوم المختار لمطابقة [lawyerId_time]
  const dayAppointmentsMap = useMemo(() => {
    const map = new Map<string, AppointmentItem>();
    appointments.forEach((a) => {
      if (a.rawDate === selectedDay && a.rawTime && a.lawyerId) {
        // مفتاح: lawyerId_HH:MM
        const key = `${a.lawyerId}_${a.rawTime.slice(0, 5)}`;
        map.set(key, a);
      }
    });

    return map;
  }, [appointments, selectedDay]);

  return (
    <>
      {/* ── الترويسة الرئيسية والإجراءات السريعة ── */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2>جدولة وإدارة المواعيد 📅</h2>
          <p>لوحة العمليات التشغيلية لمتابعة تفرغ المستشارين، حجز الاستشارات، ومتابعة الحضور.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          <div style={{ display: 'inline-flex', background: 'var(--paper)', border: '1px solid var(--line)', borderRadius: 10, padding: 3 }}>
            <button
              type="button"
              className={`btn sm ${viewMode === 'grid' ? '' : 'soft'}`}
              style={{ border: 'none', boxShadow: viewMode === 'grid' ? undefined : 'none' }}
              onClick={() => setViewMode('grid')}
            >
              <Icon name="calgrid" /> شبكة التفرغ والتقويم
            </button>
            <button
              type="button"
              className={`btn sm ${viewMode === 'list' ? '' : 'soft'}`}
              style={{ border: 'none', boxShadow: viewMode === 'list' ? undefined : 'none' }}
              onClick={() => setViewMode('list')}
            >
              <Icon name="ticket" /> قائمة المواعيد
            </button>
          </div>
          <button className="btn" type="button" onClick={openNewBooking}>
            <Icon name="calplus" /> + حجز موعد جديد
          </button>
        </div>
      </div>

      {/* ── شريط المؤشرات والإحصائيات ── */}
      <StatRow items={stats} />

      {/* ── شريط الفلاتر والبحث والتنقل ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px', display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'center', justifyContent: 'space-between' }}>
          {/* محدد اليوم في عرض الشبكة */}
          {viewMode === 'grid' && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <button className="btn soft sm" type="button" onClick={() => shiftDay(-1)} title="اليوم السابق">
                ◀
              </button>
              <button
                className={`btn sm ${selectedDay === todayISO() ? '' : 'soft'}`}
                type="button"
                onClick={() => setSelectedDay(todayISO())}
              >
                اليوم
              </button>
              <button className="btn soft sm" type="button" onClick={() => shiftDay(1)} title="اليوم التالي">
                ▶
              </button>
              <input
                type="date"
                className="input"
                style={{ width: 145, padding: '6px 10px', fontSize: 13 }}
                value={selectedDay}
                onChange={(e) => setSelectedDay(e.target.value)}
              />
              <span style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--deep)', marginInlineStart: 4 }}>
                {formattedSelectedDay}
              </span>
            </div>
          )}

          {/* محدد البحث العام في عرض القائمة */}
          {viewMode === 'list' && (
            <div className="search" style={{ width: 280 }}>
              <Icon name="search" />
              <input
                placeholder="بحث بالعميل، المستشار، الموضوع..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              {searchQuery && (
                <button type="button" onClick={() => setSearchQuery('')} style={{ color: 'var(--faint)' }}>
                  <Icon name="close" />
                </button>
              )}
            </div>
          )}

          {/* الفلاتر المنسدلة المشتركة */}
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', marginInlineStart: 'auto' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المستشار:</span>
              <select
                value={filterLawyer}
                onChange={(e) => setFilterLawyer(e.target.value)}
                style={{ width: 150, padding: '6px 28px 6px 10px', fontSize: 13 }}
              >
                <option value="all">كل المستشارين</option>
                {lawyers.map((l) => (
                  <option key={l.id} value={String(l.id)}>
                    {l.name}
                  </option>
                ))}
              </select>
            </div>

            {viewMode === 'list' && (
              <>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>النوع:</span>
                  <select
                    value={filterChannel}
                    onChange={(e) => setFilterChannel(e.target.value)}
                    style={{ width: 130, padding: '6px 28px 6px 10px', fontSize: 13 }}
                  >
                    <option value="all">كل الأنواع</option>
                    <option value="حضورية">حضورية 🏢</option>
                    <option value="مرئية">مرئية 🎥</option>
                    <option value="هاتفية">هاتفية 📞</option>
                  </select>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>الحالة:</span>
                  <select
                    value={filterStatus}
                    onChange={(e) => setFilterStatus(e.target.value)}
                    style={{ width: 130, padding: '6px 28px 6px 10px', fontSize: 13 }}
                  >
                    <option value="all">كل الحالات</option>
                    <option value="up">مواعيد قادمة</option>
                    <option value="attended">تم الحضور</option>
                    <option value="noshow">لم يحضر</option>
                    <option value="past">مواعيد سابقة</option>
                  </select>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* ── العرض 1: شبكة تفرغ الفريق والتقويم التفاعلي (Resource Grid) ── */}
      {viewMode === 'grid' && (
        <div className="card">
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <Icon name="calgrid" />
              <h3>شبكة تفرغ المستشارين ليوم {formattedSelectedDay}</h3>
            </div>
            <span className="sub">
              انقر على أي فترة شاغرة خضراء لحجز موعد فوري
            </span>
          </div>

          <div className="card-b t-wrap" style={{ padding: 0 }}>
            {gridLawyers.length === 0 ? (
              <div className="empty">
                <Icon name="user" />
                <b>لا يوجد مستشارون مسجلون</b>
              </div>
            ) : (
              <table className="tbl" style={{ borderCollapse: 'separate', borderSpacing: 0, minWidth: 720 }}>
                <thead>
                  <tr style={{ background: 'var(--paper-2)' }}>
                    <th style={{ width: 90, textAlign: 'center', borderRight: '1px solid var(--line-soft)' }}>الوقت</th>
                    {gridLawyers.map((l) => (
                      <th key={l.id} style={{ minWidth: 160, padding: '12px 14px' }}>
                        <div style={{ fontWeight: 800, color: 'var(--deep)', fontSize: 13.5 }}>{l.name}</div>
                        <div style={{ fontSize: 11, color: 'var(--muted)', fontWeight: 500 }}>{l.dept || 'القسم القانوني'}</div>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {DAY_HOURS.map((hourStr) => {
                    const isSlotPast = selectedDay === todayISO() && hourStr <= nowHM();

                    return (
                      <tr key={hourStr} style={{ borderBottom: '1px solid var(--line-soft)' }}>
                        <td
                          style={{
                            textAlign: 'center',
                            fontWeight: 700,
                            fontSize: 12.5,
                            color: 'var(--muted)',
                            background: 'var(--paper-2)',
                            borderRight: '1px solid var(--line-soft)',
                            padding: '10px 8px',
                          }}
                        >
                          {hourStr}
                        </td>
                        {gridLawyers.map((l) => {
                          const appt = dayAppointmentsMap.get(`${l.id}_${hourStr}`);

                          if (appt) {
                            // الخانة محجوزة بموعد
                            const isVid = appt.channel === 'مرئية' || appt.ico === 'video';
                            const isPhone = appt.channel === 'هاتفية' || appt.ico === 'phone';
                            const bgCard = isVid
                              ? 'rgba(17, 160, 200, 0.12)'
                              : isPhone
                              ? 'rgba(192, 131, 43, 0.12)'
                              : 'rgba(14, 92, 156, 0.1)';
                            const borderCol = isVid ? 'var(--cyan)' : isPhone ? 'var(--amber)' : 'var(--primary)';

                            return (
                              <td
                                key={l.id}
                                style={{ padding: '6px 8px', verticalAlign: 'middle' }}
                              >
                                <div
                                  onClick={() => setSelectedAppt(appt)}
                                  style={{
                                    background: bgCard,
                                    borderRight: `3px solid ${borderCol}`,
                                    borderRadius: 8,
                                    padding: '7px 10px',
                                    cursor: 'pointer',
                                    transition: '.13s',
                                  }}
                                  title="انقر لعرض تفاصيل الموعد"
                                >
                                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 6 }}>
                                    <b style={{ fontSize: 12.5, color: 'var(--deep)' }}>{appt.client || 'عميل'}</b>
                                    <span style={{ fontSize: 11, fontWeight: 700, color: borderCol }}>
                                      {isVid ? '🎥 مرئية' : isPhone ? '📞 هاتفية' : '🏢 حضورية'}
                                    </span>
                                  </div>
                                  <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 2, display: 'flex', justifyContent: 'space-between' }}>
                                    <span>{appt.subject || appt.type}</span>
                                    <Badge text={appt.status} tone={appt.tone} />
                                  </div>
                                </div>
                              </td>
                            );
                          }

                          // الخانة متاحة للحجز
                          return (
                            <td key={l.id} style={{ padding: '6px 8px', verticalAlign: 'middle' }}>
                              <button
                                type="button"
                                onClick={() => openBookingForSlot(l.id, selectedDay, hourStr)}
                                disabled={isSlotPast}
                                style={{
                                  width: '100%',
                                  padding: '7px 10px',
                                  borderRadius: 8,
                                  border: '1px dashed var(--line)',
                                  background: isSlotPast ? '#fafafa' : '#fff',
                                  color: isSlotPast ? 'var(--faint)' : 'var(--success)',
                                  fontSize: 12,
                                  fontWeight: 600,
                                  cursor: isSlotPast ? 'not-allowed' : 'pointer',
                                  display: 'flex',
                                  alignItems: 'center',
                                  justifyContent: 'center',
                                  gap: 5,
                                  transition: '.15s',
                                }}
                                onMouseEnter={(e) => {
                                  if (!isSlotPast) {
                                    e.currentTarget.style.borderColor = 'var(--success)';
                                    e.currentTarget.style.background = 'var(--success-bg)';
                                  }
                                }}
                                onMouseLeave={(e) => {
                                  if (!isSlotPast) {
                                    e.currentTarget.style.borderColor = 'var(--line)';
                                    e.currentTarget.style.background = '#fff';
                                  }
                                }}
                              >
                                {isSlotPast ? '— منقضٍ' : '+ متاح للحجز'}
                              </button>
                            </td>
                          );
                        })}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      {/* ── العرض 2: جدول قائمة المواعيد الشامل ── */}
      {viewMode === 'list' && (
        <div className="card">
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <Icon name="ticket" />
              <h3>سجل المواعيد والارتباطات</h3>
            </div>
            <span className="sub">{filteredAppointments.length} موعد</span>
          </div>

          <div className="card-b t-wrap" style={{ padding: 0 }}>
            {filteredAppointments.length > 0 ? (
              <table className="tbl">
                <thead>
                  <tr>
                    <th>النوع والقناة</th>
                    <th>العميل</th>
                    <th>المستشار المكلف</th>
                    <th>التاريخ والوقت</th>
                    <th>المكان / الرابط</th>
                    <th>الحالة والسداد</th>
                    <th>الإجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredAppointments.map((a) => (
                    <tr key={a.id} className="click" onClick={() => setSelectedAppt(a)}>
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <div
                            style={{
                              width: 32,
                              height: 32,
                              borderRadius: 8,
                              display: 'grid',
                              placeItems: 'center',
                              background:
                                a.channel === 'مرئية' || a.ico === 'video'
                                  ? 'rgba(17,160,200,.12)'
                                  : a.channel === 'هاتفية' || a.ico === 'phone'
                                  ? 'var(--amber-bg)'
                                  : 'rgba(14,92,156,.1)',
                              color:
                                a.channel === 'مرئية' || a.ico === 'video'
                                  ? 'var(--cyan)'
                                  : a.channel === 'هاتفية' || a.ico === 'phone'
                                  ? 'var(--amber)'
                                  : 'var(--primary)',
                            }}
                          >
                            <Icon name={a.channel === 'مرئية' || a.ico === 'video' ? 'video' : a.channel === 'هاتفية' || a.ico === 'phone' ? 'phone' : 'office'} />
                          </div>
                          <div>
                            <b style={{ fontSize: 13, color: 'var(--ink)' }}>{a.type}</b>
                            {a.consultRef && <div className="sub" style={{ fontSize: 11 }}>{a.consultRef}</div>}
                          </div>
                        </div>
                      </td>
                      <td>
                        <b>{a.client || '—'}</b>
                        {a.phone && <div className="sub">{a.phone}</div>}
                      </td>
                      <td>
                        <b>{a.lawyer || '—'}</b>
                      </td>
                      <td>
                        <div><b>{a.day || '—'}</b></div>
                        <span className="muted">{a.time || '—'}</span>
                      </td>
                      <td>
                        <span className="muted">{a.place || (a.channel === 'مرئية' ? 'جلسة إلكترونية' : '—')}</span>
                      </td>
                      <td>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, alignItems: 'flex-start' }}>
                          <Badge text={a.status} tone={a.tone} />
                          {a.pay && (
                            <span style={{ fontSize: 11, color: a.pay === 'مدفوع' ? 'var(--success)' : 'var(--amber)', fontWeight: 700 }}>
                              {a.pay}
                            </span>
                          )}
                        </div>
                      </td>
                      <td>
                        <div style={{ display: 'flex', gap: 6 }} onClick={(e) => e.stopPropagation()}>
                          <button
                            className="btn soft sm"
                            type="button"
                            onClick={() => setSelectedAppt(a)}
                            title="عرض التفاصيل"
                          >
                            <Icon name="info" /> التفاصيل
                          </button>
                          {a.joinLink && (
                            <a
                              className="btn sm"
                              href={a.joinLink}
                              target="_blank"
                              rel="noopener noreferrer"
                              title="الدخول إلى غرفة الجلسة المرئية"
                            >
                              <Icon name="video" /> الغرفة
                            </a>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <div className="empty">
                <Icon name="cal" />
                <b>لا توجد مواعيد مطابقة لخيارات البحث والتصفية</b>
                {(searchQuery || filterLawyer !== 'all' || filterChannel !== 'all' || filterStatus !== 'all') && (
                  <button
                    className="btn soft sm"
                    type="button"
                    style={{ marginTop: 10 }}
                    onClick={() => {
                      setSearchQuery('');
                      setFilterLawyer('all');
                      setFilterChannel('all');
                      setFilterStatus('all');
                    }}
                  >
                    إعادة ضبط الفلاتر
                  </button>
                )}
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── مودال 1: نافذة حجز موعد جديد الذكية ── */}
      <Modal
        title="جدولة موعد استشارة جديد"
        subtitle="إنشاء وحجز موعد حقيقي في النظام وإسناده للمستشار"
        open={bookOpen}
        onClose={() => setBookOpen(false)}
        maxWidth={620}
      >
        <div style={{ padding: '4px 0' }}>
          {clients.length === 0 && (
            <div className="empty" style={{ padding: 14 }}>
              <Icon name="user" />
              <b>لا يوجد عملاء مسجلون بعد</b>
            </div>
          )}

          {/* العميل مع ميزة البحث السريع */}
          <div className="field">
            <label>
              العميل <span className="req">*</span>
            </label>
            <div style={{ display: 'flex', gap: 8, marginBottom: 6 }}>
              <input
                className="input"
                style={{ padding: '7px 10px', fontSize: 13 }}
                placeholder="🔍 تصفية العملاء بالاسم أو الجوال..."
                value={clientSearch}
                onChange={(e) => setClientSearch(e.target.value)}
              />
            </div>
            <select
              value={clientId}
              onChange={(e) => setClientId(Number(e.target.value))}
            >
              <option value="" disabled>-- اختر العميل --</option>
              {filteredModalClients.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} {c.phone ? `(${c.phone})` : ''}
                </option>
              ))}
            </select>
          </div>

          {/* المستشار ونوع الاستشارة */}
          <div className="grid-2" style={{ gap: 14 }}>
            <div className="field">
              <label>المستشار المكلف</label>
              <select
                value={lawyerId}
                onChange={(e) => {
                  setLawyerId(Number(e.target.value));
                  setTime('');
                }}
              >
                {lawyers.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.name} {l.dept ? `(${l.dept})` : ''}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label>قناة ونوع الاستشارة <span className="req">*</span></label>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 6 }}>
                {TYPES.map(([v, l, icon]) => (
                  <button
                    key={v}
                    type="button"
                    className={`btn sm ${type === v ? '' : 'soft'}`}
                    style={{ padding: '8px 4px', fontSize: 12.5 }}
                    onClick={() => setType(v)}
                  >
                    <Icon name={icon} /> {l}
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* موضوع الاستشارة والتاريخ */}
          <div className="grid-2" style={{ gap: 14 }}>
            <div className="field">
              <label>موضوع الاستشارة</label>
              <input
                className="input"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                placeholder="مثال: استشارة عقارية / مراجعة عقد"
              />
            </div>

            <div className="field">
              <label>تاريخ الموعد <span className="req">*</span></label>
              <input
                className="input"
                type="date"
                min={todayISO()}
                value={date}
                onChange={(e) => {
                  setDate(e.target.value);
                  setTime('');
                }}
              />
            </div>
          </div>

          {/* منتقي الفترات الزمنية التفاعلية الحقيقية */}
          {/* اختيار الوقت المتاح */}
          <TimeSlotPicker
            value={time}
            onChange={setTime}
            date={date}
            slots={slots}
            label="الوقت المتاح للموعد"
            helperText={slotsLoading ? 'جارٍ فحص الأوقات المتاحة لدى المستشار…' : undefined}
            required
            allowCustom={false}
/>

          {isPast && (
            <div style={{ color: 'var(--red)', fontSize: 12.5, margin: '4px 0 10px' }}>
              ⚠️ الوقت المختار مضى — يرجى اختيار وقت لاحق.
            </div>
          )}

          {/* أزرار الإجراءات */}
          <div style={{ display: 'flex', gap: 10, marginTop: 18, borderTop: '1px solid var(--line-soft)', paddingTop: 14 }}>
            <button
              className="btn soft block"
              type="button"
              onClick={() => setBookOpen(false)}
            >
              إلغاء
            </button>
            <button
              className="btn block"
              type="button"
              onClick={submitBooking}
              disabled={busy || isPast || !clientId || !date || !time}
            >
              <Icon name="calplus" /> {busy ? 'جارٍ الحفظ…' : 'تأكيد الجدولة وحفظ الموعد'}
            </button>
          </div>
        </div>
      </Modal>

      {/* ── مودال 2: بطاقة تفاصيل الموعد والإجراءات ── */}
      {selectedAppt && (
        <Modal
          title={`تفاصيل الموعد: ${selectedAppt.id}`}
          badge={<Badge text={selectedAppt.status} tone={selectedAppt.tone} />}
          open={!!selectedAppt}
          onClose={() => setSelectedAppt(null)}
          maxWidth={540}
        >
          <div style={{ padding: '6px 0' }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, background: 'var(--paper-2)', padding: 14, borderRadius: 12, marginBottom: 16 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">العميل:</span>
                <b>{selectedAppt.client || '—'}</b>
              </div>
              {selectedAppt.phone && (
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                  <span className="muted">الهاتف:</span>
                  <a href={`tel:${selectedAppt.phone}`} style={{ color: 'var(--primary)', fontWeight: 700 }}>
                    {selectedAppt.phone}
                  </a>
                </div>
              )}
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">المستشار المكلف:</span>
                <b>{selectedAppt.lawyer || '—'}</b>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">تاريخ ووقت الموعد:</span>
                <b>{selectedAppt.day} · {selectedAppt.time}</b>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span className="muted">القناة والمكان:</span>
                <span>{selectedAppt.place || (selectedAppt.channel === 'مرئية' ? 'جلسة إلكترونية' : '—')}</span>
              </div>
              {selectedAppt.pay && (
                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                  <span className="muted">حالة السداد:</span>
                  <b style={{ color: selectedAppt.pay === 'مدفوع' ? 'var(--success)' : 'var(--amber)' }}>
                    {selectedAppt.pay}
                  </b>
                </div>
              )}
            </div>

            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {selectedAppt.joinLink && (
                <a
                  className="btn block"
                  href={selectedAppt.joinLink}
                  target="_blank"
                  rel="noopener noreferrer"
                  style={{ flex: 1 }}
                >
                  <Icon name="video" /> دخول غرفة الجلسة المرئية
                </a>
              )}
              {selectedAppt.gcal && (
                <a
                  className="btn soft sm"
                  href={selectedAppt.gcal}
                  target="_blank"
                  rel="noopener noreferrer"
                  style={{ flex: 1 }}
                >
                  <Icon name="calplus" /> إضافة لتقويم جوجل
                </a>
              )}
            </div>

            {/* كانت اللوحة بلا أي إجراء بعد الإنشاء — القدرة موجودة عبر الاستشارة المرافقة
                (إعادة الجدولة تُلغي الموعد القديم فعلاً) لكن بلا جسر واجهة */}
            {selectedAppt.consultId && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 10 }}>
                <button
                  className="btn soft sm"
                  type="button"
                  style={{ flex: 1 }}
                  onClick={() => router.post(`${apiBase()}/consults/${selectedAppt.consultId}/reschedule`, {}, {
                    preserveScroll: true,
                    onSuccess: () => {
 toast('أُلغي الموعد وطُلب من العميل اختيار موعد جديد'); setSelectedAppt(null); 
},
                    onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّرت إعادة الجدولة')),
                  })}
                >
                  <Icon name="cal" /> إعادة جدولة الموعد
                </button>
                <button
                  className="btn ghost sm"
                  type="button"
                  style={{ flex: 1 }}
                  onClick={() => router.post(`${apiBase()}/consults/${selectedAppt.consultId}/no-show`, {}, {
                    preserveScroll: true,
                    onSuccess: () => {
 toast('وُسم الموعد «لم يحضر»'); setSelectedAppt(null); 
},
                    onError: (e) => toast(String(Object.values(e)[0] ?? 'تعذّر الوسم')),
                  })}
                >
                  <Icon name="clock" /> لم يحضر
                </button>
              </div>
            )}
          </div>
        </Modal>
      )}
    </>
  );
};

export default EmployeeSchedule;
