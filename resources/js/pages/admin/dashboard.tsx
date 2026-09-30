import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Icon from '@/lib/icons';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import { CAPACITY } from '@/components/babylon/LawyerFileModal';
import type { LawyerLoad } from '@/components/babylon/LawyerFileModal';
import { useToast } from '@/components/babylon/Toast';
import { countNoun, NOUN } from '@/lib/arabic-count';
import { useSettings } from '@/lib/settings';

// ============================================================
// لوحة الإدارة العليا والتحكم العام 360 درجة (360° Executive Command Center)
// منصة سلاسل بابل لإدارة مكاتب المحاماة
// ============================================================

const fmt = (n: number) => (n || 0).toLocaleString('en-US');

export interface OverviewStats {
  clientsCount: number;
  /** غير الموقوفين — السطر تحت العدد الكلّيّ */
  activeClients: number;
  activeCases: number;
  openTickets: number;
  activeExecutions: number;
  activeExecAmount: number;
  upcomingSessions: number;
}

export interface FinanceStats {
  totalCollected: number;
  totalUnpaid: number;
  totalBilled: number;
  collectionRate: number;
  currentMonthCollected: number;
  prevMonthCollected: number;
  revenueGrowth: number | null;
  overdueCount: number;
}

export interface RadarItem {
  id: string;
  title: string;
  count: number;
  desc: string;
  cta: string;
  link: string;
  tone: 'amber' | 'blue' | 'purple' | 'cyan' | 'red';
  icon: string;
}

export interface HearingItem {
  id: number;
  /** ملفّ القضيّة نفسه (`admin.cases.show`) — `null` لجلسةٍ بلا قضيّة */
  caseUrl: string | null;
  title: string;
  caseNumber: string;
  caseType: string;
  court: string;
  startsAt: string | null;
  formattedDate: string;
  formattedTime: string;
  isToday: boolean;
  lawyer: string;
}

export interface LawyerWorkload {
  id: number;
  name: string;
  jobTitle: string;
  department: string;
  initials: string;
  activeCases: number;
  activeTickets: number;
  activeExecutions: number;
  openConsults: number;
  /** من `LawyerWorkload::capacity` — التعريف الواحد للحِمل في اللوحة وصفحة المحامين وشاشة التوزيع. */
  status: LawyerLoad['capacity'];
}

export interface RevenuePoint {
  month: string;
  billed: number;
  collected: number;
}

export interface PracticeArea {
  name: string;
  count: number;
  percentage: number;
}

export interface LiveActivityItem {
  id: string;
  ico: string;
  title: string;
  sub: string;
  time: string;
  tag: string;
  tone: string;
}

interface Props {
  overview?: OverviewStats;
  finance?: FinanceStats;
  radar?: RadarItem[];
  upcomingHearings?: HearingItem[];
  lawyersWorkload?: LawyerWorkload[];
  revenueTrajectory?: RevenuePoint[];
  practiceAreas?: PracticeArea[];
  liveActivity?: LiveActivityItem[];
  /** أداة التصفير متاحةٌ هنا؟ من الخادم (`DashboardController::resetAllowed`) — لا تظهر حيث يردّها 403 */
  canReset?: boolean;
}

const AdminDashboard: React.FC<Props> = ({
  overview,
  finance,
  radar = [],
  upcomingHearings = [],
  lawyersWorkload = [],
  revenueTrajectory = [],
  practiceAreas = [],
  liveActivity = [],
  canReset = false,
}) => {
  const toast = useToast();
  const askFor = usePrompt();
  const { office_name: officeName } = useSettings();
  const [busy, setBusy] = useState(false);
  const [maintenanceOpen, setMaintenanceOpen] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const finalOverview = useMemo(() => {
    return {
      clients: overview?.clientsCount ?? 0,
      activeClients: overview?.activeClients ?? 0,
      openTickets: overview?.openTickets ?? 0,
      activeCases: overview?.activeCases ?? 0,
      activeExecs: overview?.activeExecutions ?? 0,
      execAmount: overview?.activeExecAmount ?? 0,
      upcomingSessions: overview?.upcomingSessions ?? 0,
      totalRevenue: finance?.totalCollected ?? 0,
      collectionRate: finance?.collectionRate ?? 100,
      revenueGrowth: finance?.revenueGrowth ?? null,
      totalUnpaid: finance?.totalUnpaid ?? 0,
      overdueCount: finance?.overdueCount ?? 0,
    };
  }, [overview, finance]);

  // تحديث البيانات اللحظي
  const handleRefresh = () => {
    setRefreshing(true);
    router.visit(window.location.pathname + '?fresh=1', {
      preserveScroll: true,
      onFinish: () => {
        setRefreshing(false);
        toast('تم تحديث المؤشرات الإدارية بنجاح 🔄');
      },
    });
  };

  /**
   * **تصفير بيانات الاختبار — تأكيدٌ يكتبه المدير بيده.** كان الزرّ يرسل `confirm: 'RESET'` ثابتةً
   * من الشفرة، فحارس الخادم (عبارة تأكيد صريحة) يُستوفى آليّاً بنقرة «تأكيد» في نافذةٍ عاديّة. الآن
   * تُرسل العبارة كما كتبها، والخادم يرفض غيرها — والرسالة (نجاحاً أو رفضاً) من الخادم.
   */
  const handleResetDatabase = async () => {
    const typed = await askFor({
      title: 'تأكيد تصفير بيانات الاختبار',
      message: (
        <div style={{ fontSize: 13, lineHeight: 1.6, color: '#991b1b' }}>
          <strong>تحذير:</strong> سيُحذف نهائياً كلّ ما في الجداول التشغيلية: التذاكر ومحادثاتها ومستنداتها والملخّصات،
          والقضايا وجلساتها وفواتيرها، وملفات التنفيذ، والاستشارات ومواعيدها والاجتماعات والمهام.
          <div style={{ marginTop: 6, color: '#15803d', fontWeight: 700 }}>✓ يُبقى على حسابات المستخدمين وأدوارهم وصلاحياتهم.</div>
        </div>
      ),
      label: 'اكتب RESET بأحرفٍ لاتينيّة كبيرة للتأكيد',
      placeholder: 'RESET',
      confirmLabel: 'تصفير البيانات نهائياً',
    });

    if (typed === null) {
      return;
    }

    if (typed.trim() !== 'RESET') {
      toast('⚠️ لم يُصفَّر شيء — عبارة التأكيد يجب أن تكون RESET حرفياً', 'error');

      return;
    }

    setBusy(true);
    router.post(
      '/admin/reset-database',
      { confirm: typed.trim() },
      {
        onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تصفير قاعدة البيانات'}`, 'error'),
        onFinish: () => setBusy(false),
      }
    );
  };

  // حساب أقصى قيمة للرسم البياني
  const maxRevenue = useMemo(() => {
    if (!revenueTrajectory.length) return 1;
    return Math.max(...revenueTrajectory.map((r) => Math.max(r.billed, r.collected))) || 1;
  }, [revenueTrajectory]);

  const finalActivity = liveActivity;

  return (
    <>
      {/* 1. الهيدر التنفيذي وشريط القيادة السريعة */}
      <div className="hero" style={{ padding: '26px 28px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
          <div style={{ maxWidth: 680 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
              <span className="chip" style={{ background: 'rgba(255,255,255,0.18)', color: '#fff', borderColor: 'rgba(255,255,255,0.3)', fontWeight: 700 }}>
                <Icon name="scale" cls="ic" /> الإدارة العليا · {officeName}
              </span>
              <span style={{ fontSize: 12, opacity: 0.85, color: '#e0f2fe' }}>
                رؤية 360° مباشرة
              </span>
            </div>
            <h2>مركز القيادة والتحكم الإداري الشامل 🏛️</h2>
            <p>
              متابعة مباشرة ومؤشرات حية للأداء المالي، التذاكر، قضايا المحاكم، ومصفوفة أحمال وتفرغ فريق المستشارين بالمكتب.
            </p>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <button
              className="hero-b ghost"
              onClick={handleRefresh}
              disabled={refreshing}
              title="تحديث البيانات من السيرفر"
              type="button"
            >
              <Icon name="clock" /> {refreshing ? 'جاري التحديث…' : 'تحديث البيانات'}
            </button>
          </div>
        </div>

        {/* أزرار الانتقال السريع */}
        <div className="hero-cta" style={{ marginTop: 20 }}>
          <button className="hero-b" onClick={() => router.visit('/admin/distribute')} type="button">
            <Icon name="reply" /> توزيع وإسناد الأعمال
          </button>
          <button className="hero-b" onClick={() => router.visit('/admin/consult-requests')} type="button">
            <Icon name="card" /> تسعير الاستشارات
          </button>
          <button className="hero-b ghost" onClick={() => router.visit('/admin/cases')} type="button">
            <Icon name="scale" /> ملفات القضايا
          </button>
          <button className="hero-b ghost" onClick={() => router.visit('/admin/finance')} type="button">
            <Icon name="doc" /> المحاسبة والإيرادات
          </button>
          <button className="hero-b ghost" onClick={() => router.visit('/admin/staff')} type="button">
            <Icon name="user" /> إدارة الطاقم
          </button>
        </div>
      </div>

      {/* 2. شريط مؤشرات الأداء المالي والتشغيلي الكبرى (Executive KPI Cockpit) */}
      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', marginBottom: 22 }}>
        {/* بطاقة الإيرادات والتحصيل */}
        <div className="stat t-green" onClick={() => router.visit('/admin/finance')} title="عرض المحاسبة">
          <div className="si"><Icon name="card" /></div>
          <div className="num" style={{ fontSize: 25 }}>{fmt(finalOverview.totalRevenue)} <span style={{ fontSize: 13, fontWeight: 600 }}>ر.س</span></div>
          <div className="lbl">الإيراد المحصّل</div>
          <div style={{ marginTop: 6, fontSize: 11.5, color: '#047857', display: 'flex', alignItems: 'center', gap: 6, fontWeight: 700 }}>
            <span>نسبة التحصيل: {finalOverview.collectionRate}%</span>
            {/* لا مقارنة بلا شهرٍ سابق — الخادم يرسل `null` بدل «+100%» مختلَقة */}
            {finalOverview.revenueGrowth != null && finalOverview.revenueGrowth !== 0 && (
              <span style={{ color: finalOverview.revenueGrowth > 0 ? '#15803d' : '#b91c1c' }}>
                ({finalOverview.revenueGrowth > 0 ? '+' : ''}{finalOverview.revenueGrowth}%)
              </span>
            )}
          </div>
          <div className="go"><Icon name="out" /></div>
        </div>

        {/* بطاقة القضايا والتنفيذ */}
        <div className="stat t-blue" onClick={() => router.visit('/admin/cases')} title="عرض القضايا">
          <div className="si"><Icon name="scale" /></div>
          <div className="num" style={{ fontSize: 25 }}>{finalOverview.activeCases} <span style={{ fontSize: 13, fontWeight: 600 }}>{countNoun(finalOverview.activeCases, NOUN.case)}</span></div>
          <div className="lbl">قضايا جارية بالمحاكم</div>
          <div style={{ marginTop: 6, fontSize: 11.5, color: '#0369a1', fontWeight: 600 }}>
            {finalOverview.activeExecs} {countNoun(finalOverview.activeExecs, NOUN.execFile)}
          </div>
          <div className="go"><Icon name="out" /></div>
        </div>

        {/* بطاقة التذاكر والاستشارات */}
        <div className="stat t-cyan" onClick={() => router.visit('/admin/tickets')} title="عرض التذاكر">
          <div className="si"><Icon name="folder" /></div>
          <div className="num" style={{ fontSize: 25 }}>{finalOverview.openTickets} <span style={{ fontSize: 13, fontWeight: 600 }}>{countNoun(finalOverview.openTickets, NOUN.ticket)}</span></div>
          <div className="lbl">تذاكر نشطة بانتظار المعالجة</div>
          <div style={{ marginTop: 6, fontSize: 11.5, color: '#0e7490', fontWeight: 600 }}>
            {finalOverview.upcomingSessions} {countNoun(finalOverview.upcomingSessions, NOUN.videoConsult)}
          </div>
          <div className="go"><Icon name="out" /></div>
        </div>

        {/* بطاقة العملاء والموكلين */}
        <div className="stat t-amber" onClick={() => router.visit('/admin/clients')} title="عرض الموكلين">
          <div className="si"><Icon name="user" /></div>
          <div className="num" style={{ fontSize: 25 }}>{finalOverview.clients} <span style={{ fontSize: 13, fontWeight: 600 }}>{countNoun(finalOverview.clients, NOUN.client)}</span></div>
          <div className="lbl">الموكلين والعملاء المسجلين</div>
          <div style={{ marginTop: 6, fontSize: 11.5, color: '#b45309', fontWeight: 600 }}>
            {finalOverview.activeClients} {countNoun(finalOverview.activeClients, NOUN.client)} بحسابٍ نشط
          </div>
          <div className="go"><Icon name="out" /></div>
        </div>
      </div>

      {/* 3. رادار الإجراءات والقرارات العاجلة (Executive Action Radar) */}
      {radar.length > 0 ? (
        <div className="card" style={{ marginBottom: 20, border: '1px solid #fed7aa', backgroundColor: '#fffbf5' }}>
          <div className="card-h" style={{ borderBottomColor: '#ffedd5', backgroundColor: '#fff7ed' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#9a3412' }}>
              <Icon name="alert" />
              <h3 style={{ color: '#9a3412', margin: 0, fontSize: 15 }}>رادار الإجراءات والقرارات العاجلة المطلوبة من الإدارة 🚨</h3>
            </div>
            <span className="badge-s b-amber">
              <span className="d" /> {radar.reduce((acc, r) => acc + r.count, 0)} إجراء معلق
            </span>
          </div>
          <div className="card-b" style={{ padding: '14px 18px' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 260px), 1fr))', gap: 12 }}>
              {radar.map((item) => (
                <div
                  key={item.id}
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'space-between',
                    padding: '12px 14px',
                    borderRadius: 10,
                    border: '1px solid #fed7aa',
                    backgroundColor: '#ffffff',
                    transition: 'transform 0.15s ease, box-shadow 0.15s ease',
                  }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 6 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <div className={`badge-s b-${item.tone || 'amber'}`} style={{ padding: '4px 8px', fontSize: 13, fontWeight: 800 }}>
                        {item.count}
                      </div>
                      <b style={{ fontSize: 13.5, color: 'var(--ink)' }}>{item.title}</b>
                    </div>
                  </div>
                  <p style={{ fontSize: 12, color: 'var(--muted)', margin: '0 0 12px', lineHeight: 1.5 }}>
                    {item.desc}
                  </p>
                  <button
                    className={`btn sm ${item.tone === 'amber' ? '' : 'soft'}`}
                    onClick={() => router.visit(item.link)}
                    type="button"
                    style={{ width: '100%', fontSize: 12.5, fontWeight: 700 }}
                  >
                    <Icon name={item.icon || 'reply'} /> {item.cta}
                  </button>
                </div>
              ))}
            </div>
          </div>
        </div>
      ) : (
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 10,
            padding: '12px 16px',
            borderRadius: 12,
            backgroundColor: '#f0fdf4',
            border: '1px solid #bbf7d0',
            color: '#15803d',
            marginBottom: 20,
            fontSize: 13.5,
            fontWeight: 600,
          }}
        >
          <Icon name="check" />
          <span>ممتاز! جميع العمليات الإدارية مكتملة ولا توجد طلبات متأخرة بانتظار الاعتماد أو التسعير.</span>
        </div>
      )}

      {/* 4. شبكة العمليات التشغيلية (جلسات المحاكم القادمة + مصفوفة أحمال المحامين) */}
      <div className="grid-2" style={{ marginBottom: 20 }}>
        {/* جلسات المحاكم القادمة والمواعيد الحرجة */}
        <div className="card" style={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="scale" />
              <h3>جلسات المحاكم القادمة والمواعيد الحرجة</h3>
            </div>
            <button className="btn ghost sm" onClick={() => router.visit('/admin/cases')} type="button">
              عرض كل القضايا
            </button>
          </div>
          <div className="card-b" style={{ flex: 1, padding: '8px 16px' }}>
            {upcomingHearings.length > 0 ? (
              <div style={{ display: 'flex', flexDirection: 'column' }}>
                {upcomingHearings.map((h) => (
                  <div
                    key={h.id}
                    className="item"
                    style={{ padding: '11px 0', cursor: 'pointer' }}
                    onClick={() => router.visit(h.caseUrl ?? '/admin/cases')}
                  >
                    <div className="item-top">
                      <div className={`iico ${h.isToday ? 'pay-ico' : 'file-ico'}`}>
                        <Icon name={h.isToday ? 'alert' : 'scale'} />
                      </div>
                      <div className="imeta">
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 2 }}>
                          <b>{h.title || 'جلسة مرافعة قضائية'}</b>
                          {h.isToday ? (
                            <span className="badge-s b-red" style={{ fontSize: 10, padding: '2px 6px' }}>
                              اليوم ⚠️
                            </span>
                          ) : (
                            <span className="chip" style={{ fontSize: 11, padding: '1px 6px' }}>
                              {h.caseType}
                            </span>
                          )}
                        </div>
                        <span>
                          {h.court} · رقم القضية: <strong style={{ color: 'var(--ink)' }}>{h.caseNumber}</strong> · المستشار: {h.lawyer}
                        </span>
                      </div>
                    </div>
                    <div className="iact" style={{ textAlign: 'left' }}>
                      <span className="chip" style={{ fontWeight: 700, color: h.isToday ? 'var(--red)' : 'var(--primary)' }}>
                        <Icon name="clock" /> {h.formattedDate} ({h.formattedTime})
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="empty" style={{ padding: '28px 12px' }}>
                <Icon name="cal" />
                <b>لا توجد جلسات محاكم مجدولة للأيام القادمة</b>
                <p style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>
                  ستظهر هنا تلقائياً الجلسات المحددة للقضايا النشطة بالمكتب.
                </p>
              </div>
            )}
          </div>
        </div>

        {/* مصفوفة أحمال وتفرغ فريق المستشارين بالمكتب */}
        <div className="card" style={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="user" />
              <h3>مصفوفة أحمال وتفرغ المستشارين</h3>
            </div>
            {/* شاشة الأحمال هي التوزيع — زرّ «إدارة الطاقم» في رأس الصفحة يغني عن تكراره هنا */}
            <button className="btn ghost sm" onClick={() => router.visit('/admin/distribute')} type="button">
              توزيع الأعمال
            </button>
          </div>
          <div className="card-b" style={{ flex: 1, padding: '8px 16px' }}>
            {lawyersWorkload.length > 0 ? (
              <div style={{ display: 'flex', flexDirection: 'column' }}>
                {lawyersWorkload.map((lawyer) => {
                  const capacity = CAPACITY[lawyer.status];
                  return (
                    <div
                      key={lawyer.id}
                      className="item"
                      style={{ padding: '10px 0', cursor: 'pointer' }}
                      onClick={() => router.visit(`/admin/distribute`)}
                    >
                      <div className="item-top">
                        <div className="avatar" style={{ width: 38, height: 38, fontSize: 13 }}>
                          {lawyer.initials || 'مح'}
                        </div>
                        <div className="imeta">
                          <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 2 }}>
                            <b>{lawyer.name}</b>
                            <span style={{ fontSize: 11, color: 'var(--faint)' }}>({lawyer.department || 'القسم القانوني'})</span>
                          </div>
                          <span>
                            {lawyer.activeCases} قضايا · {lawyer.activeTickets} تذاكر · {lawyer.activeExecutions} تنفيذ · {lawyer.openConsults} استشارات
                          </span>
                        </div>
                      </div>
                      <div className="iact">
                        <span
                          className={`badge-s ${capacity.tone}`}
                          style={{ fontSize: 11.5 }}
                        >
                          <span className="d" />
                          {capacity.label}
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="empty" style={{ padding: '28px 12px' }}>
                <Icon name="user" />
                <b>لم يتم تسجيل محامين نشطين بعد</b>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* 5. التحليلات البيانية ومسار الإيرادات وتوزيع التخصصات */}
      <div className="grid-2" style={{ marginBottom: 20 }}>
        {/* مسار الإيرادات الشهرية لآخر 6 أشهر */}
        <div className="card">
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="card" />
              <h3>مسار الإيرادات والتحصيل (آخر 6 أشهر)</h3>
            </div>
            <span style={{ fontSize: 12, color: 'var(--faint)' }}>بالريال السعودي</span>
          </div>
          <div className="card-b" style={{ padding: 18 }}>
            {revenueTrajectory.length > 0 ? (
              <div>
                <div className="bars" style={{ height: 160, marginBottom: 12 }}>
                  {revenueTrajectory.map((r, i) => {
                    const billedHeight = Math.max(8, Math.round((r.billed / maxRevenue) * 100));
                    const collectedHeight = Math.max(8, Math.round((r.collected / maxRevenue) * 100));
                    return (
                      <div key={i} className="bar-col">
                        <div style={{ display: 'flex', gap: 4, height: '100%', alignItems: 'flex-end', width: '100%', justifyContent: 'center' }}>
                          {/* عمود المفوتر */}
                          <div
                            style={{
                              width: '45%',
                              height: `${billedHeight}%`,
                              backgroundColor: 'rgba(14,92,156,0.22)',
                              borderRadius: '6px 6px 0 0',
                              transition: 'height 0.4s ease',
                              position: 'relative',
                            }}
                            title={`المفوتر: ${fmt(r.billed)} ر.س`}
                          />
                          {/* عمود المحصل */}
                          <div
                            style={{
                              width: '45%',
                              height: `${collectedHeight}%`,
                              background: 'var(--brand)',
                              borderRadius: '6px 6px 0 0',
                              transition: 'height 0.4s ease',
                              position: 'relative',
                            }}
                            title={`المحصل: ${fmt(r.collected)} ر.س`}
                          >
                            <span className="val" style={{ fontSize: 10 }}>{fmt(r.collected)}</span>
                          </div>
                        </div>
                        <div className="cap" style={{ fontSize: 11 }}>{r.month}</div>
                      </div>
                    );
                  })}
                </div>
                {/* دليل الألوان */}
                <div style={{ display: 'flex', justifyContent: 'center', gap: 18, borderTop: '1px solid var(--line-soft)', paddingTop: 10, fontSize: 12 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ width: 12, height: 12, borderRadius: 3, background: 'var(--brand)', display: 'inline-block' }} />
                    <span style={{ color: 'var(--ink)', fontWeight: 600 }}>الإيراد المحصّل الفعلي</span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ width: 12, height: 12, borderRadius: 3, backgroundColor: 'rgba(14,92,156,0.25)', display: 'inline-block' }} />
                    <span style={{ color: 'var(--muted)' }}>إجمالي الفواتير الصادرة</span>
                  </div>
                </div>
              </div>
            ) : (
              <div className="empty" style={{ padding: 24 }}>
                <Icon name="card" />
                <b>لا توجد حركات مالية مسجلة بعد</b>
              </div>
            )}
          </div>
        </div>

        {/* توزيع القضايا حسب التخصص القضائي */}
        <div className="card">
          <div className="card-h">
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Icon name="folder" />
              <h3>توزيع القضايا حسب مجالات التخصص</h3>
            </div>
            <span style={{ fontSize: 12, color: 'var(--faint)' }}>التخصصات الأكثر طلباً</span>
          </div>
          <div className="card-b" style={{ padding: 18 }}>
            {practiceAreas.length > 0 ? (
              <div className="bars2">
                {practiceAreas.map((pa, idx) => (
                  <div key={idx} className="bar2-row">
                    <span className="bl" style={{ flex: '0 0 130px', fontWeight: 700 }}>
                      {pa.name || 'استشارات عامة'}
                    </span>
                    <div className="bt" style={{ height: 12 }}>
                      <div
                        className="bf"
                        style={{
                          width: `${Math.max(6, pa.percentage)}%`,
                          background: idx === 0 ? 'var(--brand)' : idx === 1 ? 'var(--cyan)' : 'var(--primary)',
                        }}
                      />
                    </div>
                    <span className="bv" style={{ flex: '0 0 70px', textAlign: 'left', fontWeight: 700, fontSize: 12 }}>
                      {pa.count} قضية ({pa.percentage}%)
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <div className="empty" style={{ padding: 24 }}>
                <Icon name="folder" />
                <b>لا توجد قضايا مصنفة بعد</b>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* 6. نبض العمليات الحية (Live Operations Stream) */}
      <div className="card" style={{ marginBottom: 20 }}>
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="clock" />
            <h3>نبض العمليات الحية وأحدث الحركات بالمكتب ⚡</h3>
          </div>
          <span style={{ fontSize: 12, color: 'var(--faint)' }}>تحديثات لحظية شاملة</span>
        </div>
        <div className="card-b" style={{ padding: '6px 18px 14px' }}>
          {finalActivity.length > 0 ? (
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              {finalActivity.map((a, i) => (
                <div key={a.id || i} className="item" style={{ padding: '10px 0' }}>
                  <div className="item-top">
                    <div className="iico" style={{ width: 38, height: 38, flex: '0 0 38px' }}>
                      <Icon name={a.ico || 'folder'} />
                    </div>
                    <div className="imeta">
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <b>{a.title}</b>
                        {a.tag && (
                          <span className="chip" style={{ fontSize: 10.5, padding: '1px 6px' }}>
                            {a.tag}
                          </span>
                        )}
                      </div>
                      <span style={{ fontSize: 12 }}>{a.sub}</span>
                    </div>
                  </div>
                  <div className="iact">
                    <span style={{ fontSize: 11.5, color: 'var(--faint)' }}>{a.time}</span>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="empty"><Icon name="folder" /><b>لا يوجد نشاط مسجل بعد</b></div>
          )}
        </div>
      </div>

      {/* 7. منطقة الصيانة والإشراف المتقدم — حيث يقبل الخادم التصفير وحده (`canReset`) */}
      {canReset && (
      <div
        className="card"
        style={{
          border: '1px solid #e2e8f0',
          backgroundColor: '#f8fafc',
          marginBottom: 16,
        }}
      >
        <div
          className="card-h"
          style={{
            cursor: 'pointer',
            padding: '12px 18px',
            backgroundColor: '#f1f5f9',
          }}
          onClick={() => setMaintenanceOpen(!maintenanceOpen)}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: '#475569' }}>
            <Icon name="lock" />
            <h4 style={{ margin: 0, fontSize: 13.5, color: '#334155' }}>
              أدوات الإشراف والصيانة المتقدمة (بيئة التطوير والاختبار)
            </h4>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ fontSize: 12, color: '#64748b' }}>
              {maintenanceOpen ? 'إخفاء ▲' : 'إظهار ▼'}
            </span>
          </div>
        </div>

        {maintenanceOpen && (
          <div className="card-b" style={{ padding: '16px 18px' }}>
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 12,
                padding: '12px 14px',
                borderRadius: 8,
                backgroundColor: '#fff',
                border: '1px dashed #fca5a5',
              }}
            >
              <div>
                <b style={{ color: '#991b1b', fontSize: 13, display: 'block', marginBottom: 2 }}>
                  أداة تصفير بيانات الاختبار (Reset Test Database)
                </b>
                <p style={{ margin: 0, fontSize: 12, color: '#7f1d1d' }}>
                  حذف السجلات التشغيلية التجريبية (التذاكر، القضايا، الفواتير، الاستشارات) مع الاحتفاظ الكامل بحسابات المستخدمين وصلاحياتهم.
                </p>
              </div>
              <button
                className="btn sm"
                type="button"
                disabled={busy}
                onClick={handleResetDatabase}
                style={{
                  backgroundColor: '#dc2626',
                  borderColor: '#b91c1c',
                  color: '#fff',
                }}
              >
                <Icon name="trash" /> {busy ? 'جارٍ التصفير…' : 'تصفير البيانات'}
              </button>
            </div>
          </div>
        )}
      </div>
      )}
    </>
  );
};

export default AdminDashboard;
