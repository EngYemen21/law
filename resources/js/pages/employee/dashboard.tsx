import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';

// ============================================================
// لوحة الموظف وإدارة العمليات 360 درجة (360° Operational Command Center)
// تتيح الإحاطة بجميع مسارات العمل القانوني والإداري: تذاكر، جلسات، قضايا، تنفيذ، وتفرغ الفريق
// ============================================================

export interface EmpTicket {
  no: string;
  client: string;
  type: string;
  dept: string;
  lawyer: string;
  status: string;
  tone: string;
}

export interface TodayAppt {
  id: string; // ext_id
  type: string;
  ico: string;
  lawyer: string;
  day: string;
  time: string;
  place: string;
  status: string;
  tone: string;
  when: string;
  client?: string;
  consultRef?: string;
  pay?: string;
  gcal?: string;
  joinLink?: string;
  channel?: string;
  rawStartsAt?: string | null;
}

export interface CaseSummary {
  no: string;
  type: string;
  client: string;
  lawyer: string;
  status: string;
  tone: string;
  nextHearing: string;
  hearingDate?: string | null;
  court?: string;
}

export interface ExecSummary {
  no: string;
  client: string;
  subject: string;
  court: string;
  status: string;
  tone: string;
  amount: number;
  stage: number;
}

export interface LawyerLoad {
  id: number;
  name: string;
  dept: string;
  activeTickets: number;
  activeCases: number;
}

export interface ActivityItem {
  ico: string;
  tone: string;
  title: string;
  sub: string;
  time: string;
}

interface Counts {
  needAction: number;
  missingDocs: number;
  todayAppts: number;
  referred: number;
  activeCases?: number;
  activeExecs?: number;
}

interface Props {
  name?: string;
  tickets: EmpTicket[];
  todayAppts?: TodayAppt[];
  cases?: CaseSummary[];
  execs?: ExecSummary[];
  lawyers?: LawyerLoad[];
  recentActivities?: ActivityItem[];
  counts: Counts;
}

const EmployeeDashboard: React.FC<Props> = ({
  name,
  tickets = [],
  todayAppts = [],
  cases = [],
  execs = [],
  lawyers = [],
  recentActivities = [],
  counts,
}) => {
  // التبويب النشط في مساحة العمل
  const [activeTab, setActiveTab] = useState<'tickets' | 'consults' | 'cases' | 'execs'>('tickets');
  // البحث السريع داخل الجدول
  const [searchQuery, setSearchQuery] = useState('');

  // التنقلات السريعة
  const openTicket = (no: string) => router.visit(`/employee/tickets/${encodeURIComponent(no)}`);
  const openCase = (no: string) => router.visit(`/employee/cases?q=${encodeURIComponent(no)}`);
  const openExec = () => router.visit('/employee/execs');
  const openSchedule = () => router.visit('/employee/schedule');
  const openTransfer = () => router.visit('/employee/transfer');
  const openMeetReqs = () => router.visit('/employee/meetreqs');
  const openConsultRecv = () => router.visit('/employee/consultrecv');

  // إحصائيات النبض التشغيلي 360°
  const stats: StatItem[] = [
    ['t-blue', 'folder', counts?.needAction ?? 0, 'تذاكر بانتظار إجراء'],
    ['t-cyan', 'cal', counts?.todayAppts ?? todayAppts.length, 'جلسات واستشارات اليوم'],
    ['t-amber', 'upload', counts?.missingDocs ?? 0, 'نواقص مستندات مطلوبة'],
    ['t-green', 'scale', counts?.activeCases ?? cases.length, 'قضايا جارية بالمكتب'],
    ['t-blue', 'exec', counts?.activeExecs ?? execs.length, 'ملفات تنفيذ نشطة'],
  ];

  // فلترة العناصر حسب البحث
  const filteredTickets = useMemo(() => {
    if (!searchQuery.trim()) return tickets;
    const q = foldSearch(searchQuery);
    return tickets.filter(
      (t) =>
        foldSearch(t.no).includes(q) ||
        foldSearch(t.client).includes(q) ||
        foldSearch(t.type).includes(q) ||
        foldSearch(t.lawyer).includes(q) ||
        foldSearch(t.status).includes(q)
    );
  }, [tickets, searchQuery]);

  const filteredAppts = useMemo(() => {
    if (!searchQuery.trim()) return todayAppts;
    const q = foldSearch(searchQuery);
    return todayAppts.filter(
      (a) =>
        foldSearch(a.id).includes(q) ||
        (a.client && foldSearch(a.client).includes(q)) ||
        foldSearch(a.lawyer).includes(q) ||
        (a.consultRef && foldSearch(a.consultRef).includes(q)) ||
        foldSearch(a.type).includes(q)
    );
  }, [todayAppts, searchQuery]);

  const filteredCases = useMemo(() => {
    if (!searchQuery.trim()) return cases;
    const q = foldSearch(searchQuery);
    return cases.filter(
      (c) =>
        foldSearch(c.no).includes(q) ||
        foldSearch(c.client).includes(q) ||
        foldSearch(c.lawyer).includes(q) ||
        foldSearch(c.type).includes(q) ||
        foldSearch(c.status).includes(q)
    );
  }, [cases, searchQuery]);

  const filteredExecs = useMemo(() => {
    if (!searchQuery.trim()) return execs;
    const q = foldSearch(searchQuery);
    return execs.filter(
      (e) =>
        foldSearch(e.no).includes(q) ||
        foldSearch(e.client).includes(q) ||
        foldSearch(e.subject).includes(q) ||
        foldSearch(e.court).includes(q)
    );
  }, [execs, searchQuery]);

  return (
    <>
      {/* ── الترويسة الرئيسية والإجراءات السريعة (360° Header) ── */}
      <div className="hero">
        <h2>غرفة العمليات التشغيلية والإدارية 💼 {name ? `· ${name}` : ''}</h2>
        <p>
          نظرة شاملة 360° على كافة مسارات المكتب: متابعة تذاكر العملاء، إدارة جلسات واستشارات اليوم، مراقبة جلسات المحاكم، وتوزيع المهام على المستشارين.
        </p>
        <div className="hero-cta">
          <button className="hero-b" onClick={openSchedule} type="button">
            <Icon name="calplus" /> حجز موعد
          </button>
          <button className="hero-b ghost" onClick={openTransfer} type="button">
            <Icon name="reply" /> تحويل التذاكر
          </button>
          <button className="hero-b ghost" onClick={openConsultRecv} type="button">
            <Icon name="video" /> استقبال الاستشارات
          </button>
          <button className="hero-b ghost" onClick={openMeetReqs} type="button">
            <Icon name="send" /> دعوات الاجتماعات
          </button>
        </div>
      </div>

      {/* ── شريط مؤشرات النبض التشغيلي (360° KPI Strip) ── */}
      <StatRow items={stats} />

      {/* ── التقسيم الرئيسي: مساحة العمل التشغيلية + اللوحة الجانبية ── */}
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 320px', gap: 20, alignItems: 'start' }}>
        {/* العمود الرئيسي: مساحة العمل الموحدة متعددة التبويبات */}
        <div style={{ minWidth: 0 }}>
          <div className="card">
            {/* رأس التبويبات مع البحث السريع */}
            <div className="card-h" style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <button
                  type="button"
                  className={`btn sm ${activeTab === 'tickets' ? '' : 'soft'}`}
                  style={{ boxShadow: activeTab === 'tickets' ? undefined : 'none' }}
                  onClick={() => setActiveTab('tickets')}
                >
                  <Icon name="folder" /> التذاكر بانتظار إجراء ({tickets.length})
                </button>
                <button
                  type="button"
                  className={`btn sm ${activeTab === 'consults' ? '' : 'soft'}`}
                  style={{ boxShadow: activeTab === 'consults' ? undefined : 'none' }}
                  onClick={() => setActiveTab('consults')}
                >
                  <Icon name="cal" /> جلسات اليوم ({todayAppts.length})
                </button>
                <button
                  type="button"
                  className={`btn sm ${activeTab === 'cases' ? '' : 'soft'}`}
                  style={{ boxShadow: activeTab === 'cases' ? undefined : 'none' }}
                  onClick={() => setActiveTab('cases')}
                >
                  <Icon name="scale" /> جلسات المحاكم ({cases.length})
                </button>
                <button
                  type="button"
                  className={`btn sm ${activeTab === 'execs' ? '' : 'soft'}`}
                  style={{ boxShadow: activeTab === 'execs' ? undefined : 'none' }}
                  onClick={() => setActiveTab('execs')}
                >
                  <Icon name="exec" /> التنفيذ ({execs.length})
                </button>
              </div>

              <div className="search" style={{ width: 220, padding: '6px 10px' }}>
                <Icon name="search" />
                <input
                  placeholder="بحث سريع..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  style={{ fontSize: 12.5 }}
                />
              </div>
            </div>

            {/* ── التبويب 1: جدول التذاكر ── */}
            {/* البحث السريع يرشّح المعاينة: القضايا والتنفيذ مقصوصةٌ في الخادم (‏`take(6)`/`take(5)`)،
                ومواعيد اليوم جدولُ يومٍ لا أرشيف — فيُقال ذلك صراحةً بدل «لا نتائج» صامتة. */}
            {searchQuery.trim() !== '' && activeTab !== 'tickets' && (
              <div className="action-hint" style={{ margin: '8px 14px 0' }}>
                <Icon name="info" /> البحث هنا في المعاينة المعروضة فقط.{' '}
                <a href={({ consults: '/employee/calendar', cases: '/employee/cases', execs: '/employee/execs' } as Record<string, string>)[activeTab]}>
                  ابحث في القائمة الكاملة
                </a>
              </div>
            )}

            {activeTab === 'tickets' && (
              <div className="card-b t-wrap" style={{ padding: 0 }}>
                {filteredTickets.length ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>رقم التذكرة</th>
                        <th>العميل</th>
                        <th>نوع الطلب والخدمة</th>
                        <th>المستشار المكلف</th>
                        <th>الحالة</th>
                        <th>الإجراء</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredTickets.map((t) => (
                        <tr key={t.no} className="click" onClick={() => openTicket(t.no)}>
                          <td className="mono">{t.no}</td>
                          <td><b>{t.client}</b></td>
                          <td className="muted">{t.type}</td>
                          <td><b>{t.lawyer || '—'}</b></td>
                          <td><Badge text={t.status} tone={t.tone} /></td>
                          <td>
                            <button
                              className="btn sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                openTicket(t.no);
                              }}
                              type="button"
                            >
                              <Icon name="reply" /> فتح المحادثة
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty">
                    <Icon name="folder" />
                    <b>لا توجد تذاكر تحتاج إجراءً حالياً</b>
                  </div>
                )}
              </div>
            )}

            {/* ── التبويب 2: جدول جلسات واستشارات اليوم ── */}
            {activeTab === 'consults' && (
              <div className="card-b t-wrap" style={{ padding: 0 }}>
                {filteredAppts.length ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>النوع والقناة</th>
                        <th>العميل</th>
                        <th>المستشار</th>
                        <th>التوقيت والمكان</th>
                        <th>الحالة والسداد</th>
                        <th>الإجراء</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredAppts.map((a) => (
                        <tr key={a.id}>
                          <td>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                              <span style={{ fontSize: 13, fontWeight: 700 }}>
                                {a.channel === 'مرئية' || a.ico === 'video' ? '🎥 مرئية' : a.channel === 'هاتفية' || a.ico === 'phone' ? '📞 هاتفية' : '🏢 حضورية'}
                              </span>
                              <span className="sub">{a.type}</span>
                            </div>
                          </td>
                          <td><b>{a.client || '—'}</b></td>
                          <td><b>{a.lawyer || '—'}</b></td>
                          <td>
                            <div><b>{a.time}</b></div>
                            <span className="muted" style={{ fontSize: 11 }}>{a.place || '—'}</span>
                          </td>
                          <td>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                              <Badge text={a.status} tone={a.tone} />
                              {a.pay && (
                                <span style={{ fontSize: 11, color: a.pay === 'مدفوع' ? 'var(--success)' : 'var(--amber)', fontWeight: 700 }}>
                                  {a.pay}
                                </span>
                              )}
                            </div>
                          </td>
                          <td>
                            <div style={{ display: 'flex', gap: 6 }}>
                              {a.joinLink && (
                                <a className="btn sm" href={a.joinLink} target="_blank" rel="noopener noreferrer">
                                  <Icon name="video" /> دخول الجلسة
                                </a>
                              )}
                              <button className="btn soft sm" onClick={openSchedule} type="button">
                                <Icon name="cal" /> الجدولة
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty">
                    <Icon name="cal" />
                    <b>لا توجد جلسات أو استشارات مقررة لليوم</b>
                    <button className="btn soft sm" style={{ marginTop: 8 }} onClick={openSchedule} type="button">
                      + حجز موعد جديد
                    </button>
                  </div>
                )}
              </div>
            )}

            {/* ── التبويب 3: جدول قضايا المكتب وجلسات المحاكم ── */}
            {activeTab === 'cases' && (
              <div className="card-b t-wrap" style={{ padding: 0 }}>
                {filteredCases.length ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>رقم القضية</th>
                        <th>النوع والعميل</th>
                        <th>المستشار المترافع</th>
                        <th>الجلسة القادمة</th>
                        <th>المحكمة / الدائرة</th>
                        <th>الحالة</th>
                        <th>الإجراء</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredCases.map((c) => (
                        <tr key={c.no} className="click" onClick={() => openCase(c.no)}>
                          <td className="mono">{c.no}</td>
                          <td>
                            <b>{c.client}</b>
                            <div className="muted" style={{ fontSize: 11 }}>{c.type}</div>
                          </td>
                          <td><b>{c.lawyer}</b></td>
                          <td>
                            <span style={{ fontWeight: 700, color: 'var(--deep)' }}>{c.nextHearing}</span>
                          </td>
                          <td><span className="muted">{c.court}</span></td>
                          <td><Badge text={c.status} tone={c.tone} /></td>
                          <td>
                            <button
                              className="btn soft sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                openCase(c.no);
                              }}
                              type="button"
                            >
                              <Icon name="scale" /> التفاصيل
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty">
                    <Icon name="scale" />
                    <b>لا توجد قضايا نشطة مسجلة</b>
                  </div>
                )}
              </div>
            )}

            {/* ── التبويب 4: جدول ملفات التنفيذ ── */}
            {activeTab === 'execs' && (
              <div className="card-b t-wrap" style={{ padding: 0 }}>
                {filteredExecs.length ? (
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th>رقم طلب التنفيذ</th>
                        <th>المنفذ لصالحه (العميل)</th>
                        <th>موضوع السند</th>
                        <th>المحكمة المختصة</th>
                        <th>مبلغ المطالبة</th>
                        <th>المرحلة</th>
                        <th>الإجراء</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredExecs.map((e) => (
                        <tr key={e.no} className="click" onClick={() => openExec()}>
                          <td className="mono">{e.no}</td>
                          <td><b>{e.client}</b></td>
                          <td className="muted">{e.subject}</td>
                          <td><span className="muted">{e.court}</span></td>
                          <td><b>{e.amount ? `${e.amount.toLocaleString()} ر.س` : '—'}</b></td>
                          <td><Badge text={e.status} tone={e.tone} /></td>
                          <td>
                            <button
                              className="btn soft sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                openExec();
                              }}
                              type="button"
                            >
                              <Icon name="exec" /> متابعة
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <div className="empty">
                    <Icon name="exec" />
                    <b>لا توجد طلبات تنفيذ نشطة</b>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>

        {/* العمود الجانبي: تفرغ المستشارين وسجل النشاط الحي */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          {/* بطاقة تفرغ وحمل مستشاري المكتب */}
          <div className="card">
            <div className="card-h">
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <Icon name="user" />
                <h3>فريق المستشارين والعبء</h3>
              </div>
              <span className="sub">{lawyers.length} مستشار</span>
            </div>
            <div className="card-b" style={{ padding: '8px 14px' }}>
              {lawyers.length ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {lawyers.map((l) => (
                    <div
                      key={l.id}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        padding: '8px 0',
                        borderBottom: '1px solid var(--line-soft)',
                      }}
                    >
                      <div>
                        <b style={{ fontSize: 13, color: 'var(--deep)', display: 'block' }}>{l.name}</b>
                        <span style={{ fontSize: 11, color: 'var(--muted)' }}>{l.dept}</span>
                      </div>
                      <div style={{ display: 'flex', gap: 5 }}>
                        <span className="chip" style={{ fontSize: 11 }} title="تذاكر نشطة">
                          🎫 {l.activeTickets}
                        </span>
                        <span className="chip" style={{ fontSize: 11 }} title="قضايا جارية">
                          ⚖️ {l.activeCases}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="empty" style={{ padding: 12 }}>
                  <span className="sub">لا يوجد مستشارون متاحون</span>
                </div>
              )}
            </div>
          </div>

          {/* بطاقة النشاط والتنبيهات المباشرة */}
          <div className="card">
            <div className="card-h">
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <Icon name="bell" />
                <h3>سجل العمليات الأخير</h3>
              </div>
            </div>
            <div className="card-b" style={{ padding: '8px 14px' }}>
              {recentActivities.length ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {recentActivities.map((act, i) => (
                    <div
                      key={i}
                      style={{
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: 10,
                        padding: '8px 0',
                        borderBottom: i < recentActivities.length - 1 ? '1px solid var(--line-soft)' : 'none',
                      }}
                    >
                      <div
                        style={{
                          width: 32,
                          height: 32,
                          borderRadius: 8,
                          display: 'grid',
                          placeItems: 'center',
                          flex: '0 0 32px',
                          background: act.tone === 't-cyan' ? 'rgba(17,160,200,.12)' : 'rgba(14,92,156,.1)',
                          color: act.tone === 't-cyan' ? 'var(--cyan)' : 'var(--primary)',
                        }}
                      >
                        <Icon name={act.ico} />
                      </div>
                      <div style={{ minWidth: 0, flex: 1 }}>
                        <b style={{ fontSize: 12.5, color: 'var(--ink)', display: 'block' }}>{act.title}</b>
                        <span style={{ fontSize: 11, color: 'var(--muted)', display: 'block' }}>{act.sub}</span>
                        <time style={{ fontSize: 10.5, color: 'var(--faint)' }}>{act.time}</time>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="empty" style={{ padding: 12 }}>
                  <span className="sub">لا نشاط مسجل مؤخراً</span>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  );
};

export default EmployeeDashboard;
