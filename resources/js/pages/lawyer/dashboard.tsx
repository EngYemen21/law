import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import StatRow from '@/components/babylon/StatRow';
import type {StatItem} from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useCan } from '@/lib/permissions';

// ============================================================
// لوحة المحامي والمستشار القانوني 360 درجة (360° Legal Command Center)
// مركز قيادة العمليات القضائية: تذاكر، قضايا، جلسات محاكم، استشارات حية، وتنفيذ
// ============================================================

export interface EmpTicket {
  no: string;
  client: string;
  type: string;
  dept: string;
  lawyer: string;
  status: string;
  tone: string;
  hasSummary?: boolean;
  summaryStatus?: string;
  summaryRecommendation?: string;
  converted?: boolean;
  caseRef?: string;
  priority?: string;
  updatedAgo?: string;
}

export interface LawyerCaseItem {
  id: number;
  no: string;
  client: string;
  type: string;
  dept?: string;
  status: string;
  tone: string;
  pleadingStatus?: string;
  ruling?: string;
  nextHearing?: string;
  nextHearingDate?: string | null;
  nextHearingTime?: string | null;
  court?: string;
  updatedAgo?: string;
}

export interface LawyerHearingItem {
  id: number;
  caseNo: string;
  caseType: string;
  client: string;
  court: string;
  label: string;
  startsAt?: string | null;
  formattedDate: string;
  formattedTime: string;
  isToday: boolean;
  isTomorrow: boolean;
}

export interface LawyerConsultItem {
  id: number;
  ref: string;
  client: string;
  subject: string;
  specialty?: string;
  channel: string;
  lawyer: string;
  when: string;
  place?: string;
  phone?: string;
  canJoin: boolean;
  slink?: string;
  joinLink?: string;
  session: string;
  missed?: boolean;
  startable?: boolean;
  status: string;
  summary?: string;
  duration?: string;
  isToday?: boolean;
}

export interface LawyerExecItem {
  id: number;
  number: string;
  client: string;
  subject: string;
  court: string;
  stage: number;
  status: string;
  tone: string;
  amount: number;
  lastAction?: string;
}

export interface LawyerTaskItem {
  id: number;
  title: string;
  ref: string;
  owner: string;
  due: string;
  overdue: boolean;
  status: string;
  tone: string;
}

export interface LawyerCorrespondenceItem {
  id: number;
  refNo?: string;
  subject: string;
  client: string;
  type: string;
  status: string;
  tone: string;
  updatedAgo?: string;
}

export interface ActionAlert {
  id: string;
  type: string;
  title: string;
  desc: string;
  cta: string;
  link: string;
  tone: string;
}

export interface StatsData {
  assignedTickets: number;
  activeCases: number;
  upcomingHearings: number;
  pendingSummaries: number;
  openMeetings: number;
  todayConsults: number;
  openTasks: number;
  overdueTasks: number;
  activeExecutions: number;
  activeCorrespondences: number;
}

interface Props {
  lawyerName?: string;
  lawyerTitle?: string;
  lawyerDept?: string;
  stats?: StatsData;
  tickets?: EmpTicket[];
  cases?: LawyerCaseItem[];
  upcomingHearings?: LawyerHearingItem[];
  todayConsults?: LawyerConsultItem[];
  executions?: LawyerExecItem[];
  tasks?: LawyerTaskItem[];
  correspondences?: LawyerCorrespondenceItem[];
  actionAlerts?: ActionAlert[];
  // للتوافق التراجعي
  pendingSummaries?: number;
  openMeetings?: number;
  openTasks?: number;
  overdueTasks?: number;
}

const LawyerDashboard: React.FC<Props> = ({
  lawyerName,
  lawyerTitle = 'المستشار القانوني',
  lawyerDept = 'القسم القانوني والترافع',
  stats,
  tickets = [],
  cases = [],
  upcomingHearings = [],
  todayConsults = [],
  executions = [],
  tasks = [],
  correspondences = [],
  actionAlerts = [],
  pendingSummaries: legacyPendingSummaries,
  // openMeetings: legacyOpenMeetings,   ← مع finalOpenMeetings المعلّق
  openTasks: legacyOpenTasks,
  // overdueTasks: legacyOverdueTasks = 0, ← مع finalOverdueTasks المعلّق
}) => {
  const can = useCan();
  const toast = useToast();

  // الحالة للتبويب النشط والبحث
  const [activeTab, setActiveTab] = useState<'tickets' | 'cases' | 'hearings' | 'consults' | 'executions' | 'tasks' | 'correspondences'>('tickets');
  const [searchQuery, setSearchQuery] = useState('');

  // نافذة إضافة مهمة سريعة
  const [taskModalOpen, setTaskModalOpen] = useState(false);
  const [taskTitle, setTaskTitle] = useState('');
  const [taskRef, setTaskRef] = useState('');
  const [taskDue, setTaskDue] = useState('');
  const [taskBusy, setTaskBusy] = useState(false);

  // التنقلات السريعة
  const studyTicket = (no: string) => router.visit(`/lawyer/tickets/${encodeURIComponent(no)}`);
  const openCase = (no: string) => router.visit(`/lawyer/cases/${encodeURIComponent(no)}`);
  const openSummaries = () => router.visit('/lawyer/summaries');
  const openConsults = () => router.visit('/lawyer/consults');
  const openExecs = () => router.visit('/lawyer/execs');
  const openTasks = () => router.visit('/lawyer/tasks');
  const openAssistant = () => router.visit('/lawyer/assistant');
  const openCalendar = () => router.visit('/lawyer/calendar');
  const openMeetings = () => router.visit('/lawyer/meetings');

  // حساب الأرقام المحصية
  const finalPendingSummaries = stats?.pendingSummaries ?? legacyPendingSummaries ?? tickets.filter((t) => t.summaryStatus === 'awaiting_lawyer').length;
  // غير معروضين في بطاقات الإحصائيات الحالية — يُعاد تفعيلهما إن أُضيفت بطاقتاهما
  // const finalOpenMeetings = stats?.openMeetings ?? legacyOpenMeetings ?? 0;
  const finalOpenTasks = stats?.openTasks ?? legacyOpenTasks ?? tasks.filter((t) => t.status !== 'منجزة').length;
  // const finalOverdueTasks = stats?.overdueTasks ?? legacyOverdueTasks ?? tasks.filter((t) => t.overdue).length;
  const finalActiveCases = stats?.activeCases ?? cases.length;
  const finalUpcomingHearings = stats?.upcomingHearings ?? upcomingHearings.length;

  // إحصائيات قمرة القيادة 360°
  const statItems: StatItem[] = [
    ['t-blue', 'folder', tickets.length, 'تذاكر محالة إليّ'],
    ['t-green', 'scale', finalActiveCases, 'قضاياي الجارية'],
    ['t-red', 'clock', finalUpcomingHearings, 'جلسات محاكم قادمة'],
    ['t-amber', 'doc', finalPendingSummaries, 'ملخصات بانتظار اعتمادي'],
    ['t-cyan', 'video', stats?.todayConsults ?? todayConsults.length, 'استشارات واجتماعات'],
    ['t-blue', 'exec', finalOpenTasks, 'مهام مفتوحة'],
  ];

  // تصفية العناصر حسب البحث
  const filteredTickets = useMemo(() => {
    if (!searchQuery.trim()) {
return tickets;
}

    const q = foldSearch(searchQuery);

    return tickets.filter(
      (t) =>
        foldSearch(t.no).includes(q) ||
        foldSearch(t.client).includes(q) ||
        foldSearch(t.type).includes(q) ||
        foldSearch(t.status).includes(q) ||
        (t.dept && foldSearch(t.dept).includes(q))
    );
  }, [tickets, searchQuery]);

  const filteredCases = useMemo(() => {
    if (!searchQuery.trim()) {
return cases;
}

    const q = foldSearch(searchQuery);

    return cases.filter(
      (c) =>
        foldSearch(c.no).includes(q) ||
        foldSearch(c.client).includes(q) ||
        foldSearch(c.type).includes(q) ||
        foldSearch(c.status).includes(q) ||
        (c.court && foldSearch(c.court).includes(q))
    );
  }, [cases, searchQuery]);

  const filteredHearings = useMemo(() => {
    if (!searchQuery.trim()) {
return upcomingHearings;
}

    const q = foldSearch(searchQuery);

    return upcomingHearings.filter(
      (h) =>
        foldSearch(h.caseNo).includes(q) ||
        foldSearch(h.client).includes(q) ||
        foldSearch(h.court).includes(q) ||
        foldSearch(h.caseType).includes(q)
    );
  }, [upcomingHearings, searchQuery]);

  const filteredConsults = useMemo(() => {
    if (!searchQuery.trim()) {
return todayConsults;
}

    const q = foldSearch(searchQuery);

    return todayConsults.filter(
      (c) =>
        foldSearch(c.ref).includes(q) ||
        foldSearch(c.client).includes(q) ||
        foldSearch(c.subject).includes(q) ||
        foldSearch(c.channel).includes(q)
    );
  }, [todayConsults, searchQuery]);

  const filteredExecs = useMemo(() => {
    if (!searchQuery.trim()) {
return executions;
}

    const q = foldSearch(searchQuery);

    return executions.filter(
      (e) =>
        foldSearch(e.number).includes(q) ||
        foldSearch(e.client).includes(q) ||
        foldSearch(e.subject).includes(q) ||
        foldSearch(e.court).includes(q)
    );
  }, [executions, searchQuery]);

  const filteredTasks = useMemo(() => {
    if (!searchQuery.trim()) {
return tasks;
}

    const q = foldSearch(searchQuery);

    return tasks.filter(
      (t) =>
        foldSearch(t.title).includes(q) ||
        foldSearch(t.ref).includes(q) ||
        foldSearch(t.status).includes(q)
    );
  }, [tasks, searchQuery]);

  const filteredCorrespondences = useMemo(() => {
    if (!searchQuery.trim()) {
return correspondences;
}

    const q = foldSearch(searchQuery);

    return correspondences.filter(
      (c) =>
        (c.refNo && foldSearch(c.refNo).includes(q)) ||
        foldSearch(c.client).includes(q) ||
        foldSearch(c.subject).includes(q) ||
        foldSearch(c.type).includes(q)
    );
  }, [correspondences, searchQuery]);

  // إضافة مهمة سريعة
  const handleCreateTask = () => {
    if (!taskTitle.trim()) {
      toast('يرجى كتابة عنوان المهمة القانونية');

      return;
    }

    setTaskBusy(true);
    router.post(
      '/lawyer/tasks',
      { title: taskTitle.trim(), ref: taskRef.trim(), due: taskDue.trim() },
      {
        preserveScroll: true,
        onSuccess: () => {
          setTaskModalOpen(false);
          setTaskTitle('');
          setTaskRef('');
          setTaskDue('');
          toast('✅ تمت إضافة المهمة القانونية بنجاح');
        },
        onError: () => toast('⚠️ تعذّرت إضافة المهمة، يرجى المحاولة لاحقاً'),
        onFinish: () => setTaskBusy(false),
      }
    );
  };

  // إنجاز مهمة سريعة
  const handleCompleteTask = (id: number) => {
    router.post(
      `/lawyer/tasks/${id}/complete`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => toast('✅ تم تعليم المهمة كمنجزة'),
      }
    );
  };

  return (
    <>
      {/* 1. الترويسة القيادية للمستشار وشريط الإجراءات السريعة */}
      <div className="hero" style={{ padding: '26px 28px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
          <div style={{ maxWidth: 700 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, flexWrap: 'wrap' }}>
              <span className="chip" style={{ background: 'rgba(255,255,255,0.18)', color: '#fff', borderColor: 'rgba(255,255,255,0.3)', fontWeight: 700 }}>
                <Icon name="scale" cls="ic" /> {lawyerTitle} {lawyerName ? `· ${lawyerName}` : ''}
              </span>
              <span style={{ fontSize: 12, opacity: 0.9, color: '#e0f2fe' }}>
                {lawyerDept} · مساحة القيادة القضائية 360°
              </span>
            </div>
            <h2>لوحة المحامي والمستشار القانوني ⚖️</h2>
            <p>
              إحاطة شاملة ومباشرة بالتذاكر المحالة، جدول جلسات المحاكم، القضايا النشطة، الاستشارات الحية، ومختبر المساعد الذكي.
            </p>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <button
              className="hero-b ghost"
              onClick={() => router.reload()}
              title="تحديث البيانات لحظياً"
              type="button"
            >
              <Icon name="clock" /> تحديث البيانات
            </button>
            <button
              className="hero-b"
              onClick={() => setTaskModalOpen(true)}
              type="button"
            >
              <Icon name="plus" /> مهمة سريعة
            </button>
          </div>
        </div>

        {/* أزرار الانتقال السريع */}
        <div className="hero-cta" style={{ marginTop: 20 }}>
          {can('المساعد القانوني') && (
            <button className="hero-b" onClick={openAssistant} type="button">
              <Icon name="sparkles" /> المساعد الذكي (AI Lab)
            </button>
          )}
          {can('إدارة القضايا والأتعاب') && (
            <button className="hero-b ghost" onClick={() => router.visit('/lawyer/cases')} type="button">
              <Icon name="scale" /> قضاياي وجلساتي
            </button>
          )}
          {can('اعتماد الملخصات') && (
            <button className="hero-b ghost" onClick={openSummaries} type="button">
              <Icon name="doc" /> الملخصات والاعتماد
              {finalPendingSummaries > 0 && (
                <span style={{ background: '#f59e0b', color: '#fff', padding: '1px 7px', borderRadius: 999, fontSize: 11, fontWeight: 800 }}>
                  {finalPendingSummaries}
                </span>
              )}
            </button>
          )}
          {can('إدارة الاجتماعات') && (
            <button className="hero-b ghost" onClick={openMeetings} type="button">
              <Icon name="video" /> الاجتماعات
            </button>
          )}
          {can('استقبال الاستشارات') && (
            <button className="hero-b ghost" onClick={openConsults} type="button">
              <Icon name="compass" /> جلسات الاستشارات
            </button>
          )}
          {can('المخاطبات') && (
            <button className="hero-b ghost" onClick={() => router.visit('/lawyer/correspondences')} type="button">
              <Icon name="office" /> المخاطبات الرسمية
            </button>
          )}
          <button className="hero-b ghost" onClick={openCalendar} type="button">
            <Icon name="calgrid" /> التقويم والمواعيد
          </button>
        </div>
      </div>

      {/* 2. رادار الإجراءات والتنبيهات الذكية اللحظية للمحامي (Smart Lawyer Action Radar) */}
      {actionAlerts.length > 0 && (
        <div style={{ marginBottom: 24, display: 'flex', flexDirection: 'column', gap: 10 }}>
          {actionAlerts.map((alert) => (
            <div
              key={alert.id}
              className="card"
              style={{
                borderInlineStart: alert.tone === 'b-red' ? '4px solid #ef4444' : alert.tone === 'b-cyan' ? '4px solid #06b6d4' : '4px solid #f59e0b',
                background: '#fff',
                padding: '14px 20px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: 12,
                boxShadow: '0 4px 14px rgba(10,42,85,0.06)',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 14, flex: 1, minWidth: 260 }}>
                <div
                  style={{
                    width: 40,
                    height: 40,
                    borderRadius: 12,
                    display: 'grid',
                    placeItems: 'center',
                    background: alert.tone === 'b-red' ? 'rgba(239,68,68,0.1)' : alert.tone === 'b-cyan' ? 'rgba(6,182,212,0.1)' : 'rgba(245,158,11,0.1)',
                    color: alert.tone === 'b-red' ? '#ef4444' : alert.tone === 'b-cyan' ? '#0891b2' : '#d97706',
                    flexShrink: 0,
                  }}
                >
                  <Icon name={alert.type === 'video_ready' ? 'video' : alert.type === 'hearing' ? 'scale' : alert.type === 'tasks' ? 'clock' : 'doc'} />
                </div>
                <div>
                  <h4 style={{ margin: 0, fontSize: 14.5, fontWeight: 800, color: '#13314F' }}>
                    {alert.title}
                  </h4>
                  <p style={{ margin: '2px 0 0', fontSize: 12.5, color: '#607689' }}>
                    {alert.desc}
                  </p>
                </div>
              </div>

              <div>
                <button
                  className={`btn sm ${alert.tone === 'b-red' ? '' : 'soft'}`}
                  style={alert.tone === 'b-red' ? { background: '#ef4444', color: '#fff', boxShadow: 'none' } : {}}
                  onClick={() => router.visit(alert.link)}
                  type="button"
                >
                  {alert.cta} <Icon name="send" />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* 3. شريط مؤشرات الأداء والنبض القضائي 360° */}
      <StatRow
        items={statItems}
        onSelect={(idx) => {
          if (idx === 0) {
setActiveTab('tickets');
}

          if (idx === 1) {
setActiveTab('cases');
}

          if (idx === 2) {
setActiveTab('hearings');
}

          if (idx === 3) {
openSummaries();
}

          if (idx === 4) {
setActiveTab('consults');
}

          if (idx === 5) {
setActiveTab('tasks');
}
        }}
      />

      {/* 4. الهيكل الأساسي: مساحة العمل الموحدة + الجناح الذكي */}
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 340px', gap: 20, alignItems: 'start' }}>
        {/* العمود الرئيسي: مساحة العمل الموحدة */}
        <div style={{ minWidth: 0 }}>
          <div className="card">
            {/* رأس مساحة العمل وتبديل التبويبات */}
            <div className="card-h" style={{ flexWrap: 'wrap', gap: 12, padding: '14px 18px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <button
                  className={`btn sm ${activeTab === 'tickets' ? '' : 'soft'}`}
                  onClick={() => setActiveTab('tickets')}
                  type="button"
                >
                  <Icon name="folder" /> التذاكر المحالة ({tickets.length})
                </button>
                <button
                  className={`btn sm ${activeTab === 'cases' ? '' : 'soft'}`}
                  onClick={() => setActiveTab('cases')}
                  type="button"
                >
                  <Icon name="scale" /> قضاياي ({cases.length})
                </button>
                <button
                  className={`btn sm ${activeTab === 'hearings' ? '' : 'soft'}`}
                  onClick={() => setActiveTab('hearings')}
                  type="button"
                >
                  <Icon name="clock" /> جلسات المحاكم ({upcomingHearings.length})
                </button>
                <button
                  className={`btn sm ${activeTab === 'consults' ? '' : 'soft'}`}
                  onClick={() => setActiveTab('consults')}
                  type="button"
                >
                  <Icon name="video" /> الاستشارات ({todayConsults.length})
                </button>
                <button
                  className={`btn sm ${activeTab === 'executions' ? '' : 'soft'}`}
                  onClick={() => setActiveTab('executions')}
                  type="button"
                >
                  <Icon name="exec" /> التنفيذ ({executions.length})
                </button>
                <button
                  className={`btn sm ${activeTab === 'tasks' ? '' : 'soft'}`}
                  onClick={() => setActiveTab('tasks')}
                  type="button"
                >
                  <Icon name="check" /> المهام ({tasks.length})
                </button>
                <button
                  className={`btn sm ${activeTab === 'correspondences' ? '' : 'soft'}`}
                  onClick={() => setActiveTab('correspondences')}
                  type="button"
                >
                  <Icon name="office" /> المخاطبات ({correspondences.length})
                </button>
              </div>

              {/* البحث السريع داخل الجدول النشط */}
              <div className="search" style={{ width: 220, padding: '6px 10px' }}>
                <Icon name="search" />
                <input
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="بحث سريع في العناصر…"
                />
                {searchQuery && (
                  <button onClick={() => setSearchQuery('')} type="button" style={{ opacity: 0.6 }}>
                    <Icon name="close" />
                  </button>
                )}
              </div>
            </div>

            {/* محتوى مساحة العمل حسب التبويب */}
            {/* **البحث السريع يرشّح المعاينة لا القائمة.** الجلسات والاستشارات والتنفيذ والمهامّ
                والمخاطبات مقصوصةٌ في الخادم (‏`take(6..10)`)، فبحثٌ لا يجد هنا قد يجد في القائمة
                الكاملة — ويُقال ذلك صراحةً بدل «لا نتائج» صامتة. */}
            {searchQuery.trim() !== '' && activeTab !== 'tickets' && activeTab !== 'cases' && (
              <div className="action-hint" style={{ margin: '8px 14px 0' }}>
                <Icon name="info" /> البحث هنا في أحدث العناصر المعروضة فقط.{' '}
                <a href={({ hearings: '/lawyer/calendar', consults: '/lawyer/consults', executions: '/lawyer/execs', tasks: '/lawyer/tasks', correspondences: '/correspondences' } as Record<string, string>)[activeTab]}>
                  ابحث في القائمة الكاملة
                </a>
              </div>
            )}

            <div className="card-b t-wrap" style={{ padding: 0 }}>
              {/* تبويب التذاكر */}
              {activeTab === 'tickets' && (
                filteredTickets.length > 0 ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>رقم التذكرة</th>
                        <th>الموكل</th>
                        <th>النوع والتصنيف</th>
                        <th>حالة دراسة الذكاء الاصطناعي</th>
                        <th>الحالة</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredTickets.map((t) => (
                        <tr key={t.no} className="click" onClick={() => studyTicket(t.no)}>
                          <td className="mono" style={{ fontWeight: 800 }}>{t.no}</td>
                          <td>
                            <div style={{ fontWeight: 700 }}>{t.client}</div>
                            <div className="muted" style={{ fontSize: 11.5 }}>{t.updatedAgo}</div>
                          </td>
                          <td>
                            <div>{t.type}</div>
                            <div className="muted">{t.dept}</div>
                          </td>
                          <td>
                            {t.summaryStatus === 'awaiting_lawyer' ? (
                              <span className="badge-s b-amber" style={{ fontSize: 11 }}>
                                <span className="d" /> بانتظار اعتمادك 🟡
                              </span>
                            ) : t.summaryStatus === 'approved' ? (
                              <span className="badge-s b-green" style={{ fontSize: 11 }}>
                                <span className="d" /> معتمد ومغلق ✅
                              </span>
                            ) : (
                              <span className="badge-s b-grey" style={{ fontSize: 11 }}>
                                {t.summaryStatus ? 'قيد المعالجة' : 'بدون ملخص'}
                              </span>
                            )}
                          </td>
                          <td>
                            <Badge text={t.status} tone={t.tone} />
                          </td>
                          <td style={{ textAlign: 'end', paddingInlineEnd: 16 }}>
                            <button
                              className="btn soft sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                studyTicket(t.no);
                              }}
                              type="button"
                            >
                              <Icon name="scale" /> دراسة التذكرة
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty" style={{ padding: '40px 20px', textAlign: 'center' }}>
                    <Icon name="folder" />
                    <b style={{ display: 'block', marginTop: 10 }}>لا توجد تذاكر مطابقة لعملية البحث</b>
                  </div>
                )
              )}

              {/* تبويب القضايا */}
              {activeTab === 'cases' && (
                filteredCases.length > 0 ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>رقم القضية</th>
                        <th>الموكل</th>
                        <th>التصنيف والقسم</th>
                        <th>الجلسة القادمة</th>
                        <th>المحكمة</th>
                        <th>الحالة</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredCases.map((c) => (
                        <tr key={c.no} className="click" onClick={() => openCase(c.no)}>
                          <td className="mono" style={{ fontWeight: 800 }}>{c.no}</td>
                          <td style={{ fontWeight: 700 }}>{c.client}</td>
                          <td>
                            <div>{c.type}</div>
                            <div className="muted">{c.dept || 'القسم القضائي'}</div>
                          </td>
                          <td>
                            <div style={{ color: '#0E5C9C', fontWeight: 700 }}>{c.nextHearing || 'لم تُحدد'}</div>
                            {c.nextHearingDate && (
                              <div className="muted" style={{ fontSize: 11.5 }}>{c.nextHearingDate} {c.nextHearingTime}</div>
                            )}
                          </td>
                          <td className="muted">{c.court || 'المحكمة المختصة'}</td>
                          <td>
                            <Badge text={c.status} tone={c.tone} />
                          </td>
                          <td style={{ textAlign: 'end', paddingInlineEnd: 16 }}>
                            <button
                              className="btn soft sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                openCase(c.no);
                              }}
                              type="button"
                            >
                              <Icon name="scale" /> فتح الملف
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty" style={{ padding: '40px 20px', textAlign: 'center' }}>
                    <Icon name="scale" />
                    <b style={{ display: 'block', marginTop: 10 }}>لا توجد قضايا مسندة حالياً</b>
                  </div>
                )
              )}

              {/* تبويب جلسات المحاكم */}
              {activeTab === 'hearings' && (
                filteredHearings.length > 0 ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>القضية</th>
                        <th>الموكل</th>
                        <th>المحكمة المختصة</th>
                        <th>التاريخ واليوم</th>
                        <th>التوقيت</th>
                        <th>الموعد</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredHearings.map((h) => (
                        <tr key={h.id} className="click" onClick={() => openCase(h.caseNo)}>
                          <td className="mono" style={{ fontWeight: 800 }}>{h.caseNo}</td>
                          <td style={{ fontWeight: 700 }}>{h.client}</td>
                          <td className="muted">{h.court}</td>
                          <td style={{ fontWeight: 700 }}>{h.formattedDate}</td>
                          <td className="mono">{h.formattedTime}</td>
                          <td>
                            {h.isToday ? (
                              <span className="badge-s b-red" style={{ fontWeight: 800 }}>
                                <span className="d" /> اليوم 🔴
                              </span>
                            ) : h.isTomorrow ? (
                              <span className="badge-s b-amber" style={{ fontWeight: 800 }}>
                                <span className="d" /> غداً ⚖️
                              </span>
                            ) : (
                              <span className="badge-s b-blue">
                                قادمة
                              </span>
                            )}
                          </td>
                          <td style={{ textAlign: 'end', paddingInlineEnd: 16 }}>
                            <button
                              className="btn soft sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                openCase(h.caseNo);
                              }}
                              type="button"
                            >
                              <Icon name="scale" /> ملف القضية
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty" style={{ padding: '40px 20px', textAlign: 'center' }}>
                    <Icon name="clock" />
                    <b style={{ display: 'block', marginTop: 10 }}>لا توجد جلسات محاكم مجدولة قريباً</b>
                  </div>
                )
              )}

              {/* تبويب الاستشارات والاجتماعات */}
              {activeTab === 'consults' && (
                filteredConsults.length > 0 ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>المرجع</th>
                        <th>المستشير / الموكل</th>
                        <th>الموضوع</th>
                        <th>القناة</th>
                        <th>الموعد</th>
                        <th>الحالة</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredConsults.map((c) => (
                        <tr key={c.id}>
                          <td className="mono" style={{ fontWeight: 800 }}>{c.ref}</td>
                          <td style={{ fontWeight: 700 }}>{c.client}</td>
                          <td>
                            <div>{c.subject}</div>
                            <div className="muted">{c.specialty}</div>
                          </td>
                          <td>
                            <span className={`chip ${c.channel === 'مرئية' ? 'b-cyan' : ''}`}>
                              <Icon name={c.channel === 'مرئية' ? 'video' : c.channel === 'هاتفية' ? 'phone' : 'office'} /> {c.channel}
                            </span>
                          </td>
                          <td style={{ fontWeight: 600 }}>{c.when}</td>
                          <td>
                            <Badge text={c.status} tone={c.canJoin ? 'b-green' : 'b-blue'} />
                          </td>
                          <td style={{ textAlign: 'end', paddingInlineEnd: 16 }}>
                            {c.canJoin && c.joinLink ? (
                              <button
                                className="btn sm"
                                style={{ background: '#059669', color: '#fff' }}
                                onClick={() => router.visit(c.joinLink!)}
                                type="button"
                              >
                                <Icon name="video" /> دخول الجلسة
                              </button>
                            ) : (
                              <button
                                className="btn soft sm"
                                onClick={() => router.visit('/lawyer/consults')}
                                type="button"
                              >
                                <Icon name="compass" /> التفاصيل
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty" style={{ padding: '40px 20px', textAlign: 'center' }}>
                    <Icon name="video" />
                    <b style={{ display: 'block', marginTop: 10 }}>لا توجد استشارات مجدولة حالياً</b>
                  </div>
                )
              )}

              {/* تبويب التنفيذ */}
              {activeTab === 'executions' && (
                filteredExecs.length > 0 ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>رقم المعاملة</th>
                        <th>المنفّذ لصالحه</th>
                        <th>الموضوع</th>
                        <th>محكمة التنفيذ</th>
                        <th>المرحلة</th>
                        <th>المبلغ</th>
                        <th>الحالة</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredExecs.map((e) => (
                        <tr key={e.id} className="click" onClick={openExecs}>
                          <td className="mono" style={{ fontWeight: 800 }}>{e.number}</td>
                          <td style={{ fontWeight: 700 }}>{e.client}</td>
                          <td>{e.subject}</td>
                          <td className="muted">{e.court}</td>
                          <td>
                            <span className="chip" style={{ fontWeight: 700 }}>
                              {/* المراحل عشر (0‑9) — «من 9» كانت تنقص واحدة، وصفحة الإدارة تقول «/10» */}
                              المرحلة {e.stage + 1} من 10
                            </span>
                          </td>
                          <td className="mono" style={{ fontWeight: 800, color: '#0A2A55' }}>
                            {e.amount ? `${e.amount.toLocaleString()} ر.س` : '—'}
                          </td>
                          <td>
                            <Badge text={e.status} tone={e.tone} />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty" style={{ padding: '40px 20px', textAlign: 'center' }}>
                    <Icon name="exec" />
                    <b style={{ display: 'block', marginTop: 10 }}>لا توجد طلبات تنفيذ جارية</b>
                  </div>
                )
              )}

              {/* تبويب المهام */}
              {activeTab === 'tasks' && (
                filteredTasks.length > 0 ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>عنوان المهمة القضائية</th>
                        <th>المرجع</th>
                        <th>الاستحقاق</th>
                        <th>الحالة</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredTasks.map((t) => (
                        <tr key={t.id}>
                          <td style={{ fontWeight: 700 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                              {t.status === 'منجزة' ? (
                                <Icon name="check" cls="ic" />
                              ) : (
                                <span style={{ width: 8, height: 8, borderRadius: '50%', background: t.overdue ? '#ef4444' : '#0E5C9C' }} />
                              )}
                              <span style={{ textDecoration: t.status === 'منجزة' ? 'line-through' : 'none', opacity: t.status === 'منجزة' ? 0.6 : 1 }}>
                                {t.title}
                              </span>
                            </div>
                          </td>
                          <td className="mono muted">{t.ref}</td>
                          <td>
                            <span style={{ color: t.overdue ? '#ef4444' : 'inherit', fontWeight: t.overdue ? 800 : 500 }}>
                              {t.due}
                            </span>
                            {t.overdue && (
                              <span className="badge-s b-red" style={{ marginInlineStart: 6, fontSize: 10 }}>
                                متأخرة
                              </span>
                            )}
                          </td>
                          <td>
                            <Badge text={t.status} tone={t.tone} />
                          </td>
                          <td style={{ textAlign: 'end', paddingInlineEnd: 16 }}>
                            {t.status !== 'منجزة' && (
                              <button
                                className="btn soft sm"
                                onClick={() => handleCompleteTask(t.id)}
                                type="button"
                              >
                                <Icon name="check" /> إنجاز
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty" style={{ padding: '40px 20px', textAlign: 'center' }}>
                    <Icon name="check" />
                    <b style={{ display: 'block', marginTop: 10 }}>لا توجد مهام حالية</b>
                  </div>
                )
              )}

              {/* تبويب المخاطبات */}
              {activeTab === 'correspondences' && (
                filteredCorrespondences.length > 0 ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>رقم القيد</th>
                        <th>الموكل / الجهة</th>
                        <th>الموضوع</th>
                        <th>النوع</th>
                        <th>الحالة</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredCorrespondences.map((c) => (
                        <tr key={c.id} className="click" onClick={() => router.visit(`/lawyer/correspondences/${c.id}`)}>
                          <td className="mono" style={{ fontWeight: 800 }}>{c.refNo || `COR-${c.id}`}</td>
                          <td style={{ fontWeight: 700 }}>{c.client}</td>
                          <td>{c.subject}</td>
                          <td className="muted">{c.type}</td>
                          <td>
                            <Badge text={c.status} tone={c.tone} />
                          </td>
                          <td style={{ textAlign: 'end', paddingInlineEnd: 16 }}>
                            <button
                              className="btn soft sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                router.visit(`/lawyer/correspondences/${c.id}`);
                              }}
                              type="button"
                            >
                              <Icon name="office" /> فتح
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty" style={{ padding: '40px 20px', textAlign: 'center' }}>
                    <Icon name="office" />
                    <b style={{ display: 'block', marginTop: 10 }}>لا توجد مخاطبات رسمية</b>
                  </div>
                )
              )}
            </div>
          </div>
        </div>

        {/* العمود الجانبي: الجناح الذكي وأدوات المساعد القانوني */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          {/* بطاقة المساعد الذكي السريع */}
          <div className="card" style={{ borderTop: '3px solid #0E5C9C' }}>
            <div className="card-h">
              <h3>
                <Icon name="sparkles" /> المساعد القانوني الذكي
              </h3>
              <span className="chip b-blue" style={{ fontSize: 11 }}>AI Lab</span>
            </div>
            <div className="card-b" style={{ padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 10 }}>
              <p style={{ fontSize: 12.5, color: '#607689', margin: 0 }}>
                مختبر الذكاء الاصطناعي لصياغة المذكرات وتدقيق العقود وتحليل الدفوع:
              </p>

              <button
                className="tile"
                style={{ minHeight: 'auto', padding: 12, flexDirection: 'row', alignItems: 'center', gap: 12 }}
                onClick={() => router.visit('/lawyer/assistant?action=reply_memo')}
                type="button"
              >
                <div className="ti" style={{ width: 36, height: 36, borderRadius: 10, flexShrink: 0 }}>
                  <Icon name="reply" />
                </div>
                <div style={{ textAlign: 'start' }}>
                  <b style={{ fontSize: 13, display: 'block' }}>صياغة مذكرة رد ناجز</b>
                  <span style={{ fontSize: 11.5 }}>إعداد دفاع ودحض ادعاءات الخصم</span>
                </div>
              </button>

              <button
                className="tile"
                style={{ minHeight: 'auto', padding: 12, flexDirection: 'row', alignItems: 'center', gap: 12 }}
                onClick={() => router.visit('/lawyer/assistant?action=contract_check')}
                type="button"
              >
                <div className="ti" style={{ width: 36, height: 36, borderRadius: 10, flexShrink: 0 }}>
                  <Icon name="doc" />
                </div>
                <div style={{ textAlign: 'start' }}>
                  <b style={{ fontSize: 13, display: 'block' }}>فحص وتدقيق عقد</b>
                  <span style={{ fontSize: 11.5 }}>كشف الثغرات والشروط الباطلة</span>
                </div>
              </button>

              <button
                className="tile"
                style={{ minHeight: 'auto', padding: 12, flexDirection: 'row', alignItems: 'center', gap: 12 }}
                onClick={() => router.visit('/lawyer/assistant?action=strengths_weaknesses')}
                type="button"
              >
                <div className="ti" style={{ width: 36, height: 36, borderRadius: 10, flexShrink: 0 }}>
                  <Icon name="scale" />
                </div>
                <div style={{ textAlign: 'start' }}>
                  <b style={{ fontSize: 13, display: 'block' }}>نقاط القوة والضعف</b>
                  <span style={{ fontSize: 11.5 }}>تحليل الموقف القضائي والأدلة</span>
                </div>
              </button>

              <button
                className="btn block"
                onClick={openAssistant}
                type="button"
                style={{ marginTop: 4 }}
              >
                <Icon name="sparkles" /> فتح مختبر الصياغة الشامل
              </button>
            </div>
          </div>

          {/* مصغر جلسات المحاكم القادمة */}
          <div className="card">
            <div className="card-h">
              <h3>
                <Icon name="clock" /> أقرب الجلسات القضائية
              </h3>
              <span className="sub">{upcomingHearings.length} مجدولة</span>
            </div>
            <div className="card-b" style={{ padding: '12px 16px' }}>
              {upcomingHearings.length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {upcomingHearings.slice(0, 4).map((h) => (
                    <div
                      key={h.id}
                      onClick={() => openCase(h.caseNo)}
                      style={{
                        padding: '10px 12px',
                        borderRadius: 10,
                        background: h.isToday ? 'rgba(239,68,68,0.06)' : 'var(--paper-2)',
                        border: h.isToday ? '1px solid rgba(239,68,68,0.2)' : '1px solid var(--line-soft)',
                        cursor: 'pointer',
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                        <span className="mono" style={{ fontWeight: 800, fontSize: 12.5, color: '#0E5C9C' }}>
                          {h.caseNo}
                        </span>
                        {h.isToday ? (
                          <span className="badge-s b-red" style={{ fontSize: 10, padding: '2px 7px' }}>اليوم 🔴</span>
                        ) : h.isTomorrow ? (
                          <span className="badge-s b-amber" style={{ fontSize: 10, padding: '2px 7px' }}>غداً ⚖️</span>
                        ) : null}
                      </div>
                      <div style={{ fontSize: 12.5, fontWeight: 700, color: '#13314F' }}>{h.client}</div>
                      <div style={{ fontSize: 11.5, color: '#607689', marginTop: 2 }}>
                        {h.court} · {h.formattedDate} ({h.formattedTime})
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div style={{ padding: '20px 0', textAlign: 'center', color: '#607689', fontSize: 13 }}>
                  لا توجد جلسات محاكم مجدولة
                </div>
              )}
            </div>
          </div>

          {/* بطاقة المهام السريعة */}
          <div className="card">
            <div className="card-h">
              <h3>
                <Icon name="check" /> المهام العاجلة
              </h3>
              <button
                className="btn soft sm"
                onClick={() => setTaskModalOpen(true)}
                type="button"
                style={{ padding: '4px 8px', fontSize: 11.5 }}
              >
                <Icon name="plus" /> إضافة
              </button>
            </div>
            <div className="card-b" style={{ padding: '12px 16px' }}>
              {tasks.filter((t) => t.status !== 'منجزة').length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {tasks.filter((t) => t.status !== 'منجزة').slice(0, 4).map((t) => (
                    <div
                      key={t.id}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        padding: '8px 10px',
                        background: 'var(--paper-2)',
                        borderRadius: 8,
                        border: '1px solid var(--line-soft)',
                      }}
                    >
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <div style={{ fontSize: 12.5, fontWeight: 700, color: '#13314F', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                          {t.title}
                        </div>
                        <div style={{ fontSize: 11, color: t.overdue ? '#ef4444' : '#607689', marginTop: 2 }}>
                          {t.ref} · {t.due} {t.overdue ? '(متأخرة)' : ''}
                        </div>
                      </div>
                      <button
                        onClick={() => handleCompleteTask(t.id)}
                        type="button"
                        style={{
                          width: 26,
                          height: 26,
                          borderRadius: 6,
                          background: '#fff',
                          border: '1px solid #E1E8EE',
                          display: 'grid',
                          placeItems: 'center',
                          color: '#10b981',
                          flexShrink: 0,
                          marginInlineStart: 8,
                        }}
                        title="تعليم كمنجزة"
                      >
                        <Icon name="check" cls="ic" />
                      </button>
                    </div>
                  ))}
                  <button
                    className="btn soft sm block"
                    onClick={openTasks}
                    type="button"
                    style={{ marginTop: 6 }}
                  >
                    عرض جميع المهام ({tasks.length})
                  </button>
                </div>
              ) : (
                <div style={{ padding: '20px 0', textAlign: 'center', color: '#607689', fontSize: 13 }}>
                  جميع المهام القانونية منجزة بنجاح 🎉
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* نافذة المودال لإضافة مهمة سريعة */}
      <Modal
        open={taskModalOpen}
        onClose={() => !taskBusy && setTaskModalOpen(false)}
        title="إضافة مهمة قضائية جديدة"
      >
        <div style={{ padding: 18, display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div className="field">
            <label style={{ display: 'block', fontSize: 13, fontWeight: 700, marginBottom: 6, color: '#13314F' }}>
              عنوان المهمة <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <input
              value={taskTitle}
              onChange={(e) => setTaskTitle(e.target.value)}
              placeholder="مثال: إعداد اللائحة الجوابية للدعوى العمالية"
              style={{
                width: '100%',
                padding: '10px 12px',
                borderRadius: 8,
                border: '1px solid #E1E8EE',
                fontSize: 13.5,
                outline: 'none',
              }}
            />
          </div>

          <div className="field">
            <label style={{ display: 'block', fontSize: 13, fontWeight: 700, marginBottom: 6, color: '#13314F' }}>
              المرجع (رقم التذكرة أو القضية)
            </label>
            <input
              value={taskRef}
              onChange={(e) => setTaskRef(e.target.value)}
              placeholder="اختياري — مثال: ق-2026-0211 أو SB-2026-1042"
              style={{
                width: '100%',
                padding: '10px 12px',
                borderRadius: 8,
                border: '1px solid #E1E8EE',
                fontSize: 13.5,
                outline: 'none',
              }}
            />
          </div>

          <div className="field">
            <label style={{ display: 'block', fontSize: 13, fontWeight: 700, marginBottom: 6, color: '#13314F' }}>
              تاريخ الاستحقاق النهائي
            </label>
            <input
              type="date"
              value={taskDue}
              onChange={(e) => setTaskDue(e.target.value)}
              style={{
                width: '100%',
                padding: '10px 12px',
                borderRadius: 8,
                border: '1px solid #E1E8EE',
                fontSize: 13.5,
                outline: 'none',
              }}
            />
          </div>

          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 10 }}>
            <button
              className="btn ghost"
              onClick={() => setTaskModalOpen(false)}
              disabled={taskBusy}
              type="button"
            >
              إلغاء
            </button>
            <button
              className="btn"
              onClick={handleCreateTask}
              disabled={taskBusy}
              type="button"
            >
              <Icon name="check" /> {taskBusy ? 'جاري الحفظ…' : 'إضافة المهمة'}
            </button>
          </div>
        </div>
      </Modal>
    </>
  );
};

export default LawyerDashboard;
