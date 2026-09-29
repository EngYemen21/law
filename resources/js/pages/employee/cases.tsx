import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow from '@/components/babylon/StatRow';
import type { StatItem } from '@/components/babylon/StatRow';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { truncateWords } from '@/lib/utils';

// ============================================================
// لوحة متابعة وتنسيق القضايا للموظف (Legal Case Management Desk)
// فلاتر الجلسات، مؤشرات المراحل، بحث متعدد الحقول، ومتابعة المحاكم
// ============================================================

export interface EmpCaseRow {
  no: string;
  client: string;
  clientId?: number;
  type: string;
  dept?: string;
  court?: string;
  lawyer: string;
  lawyerId?: number | null;
  status: string;
  tone: string;
  /** أعلام الحالة من الخادم (`LegalCase::stateFlags`) — في البطاقة والبثّ. */
  isActive: boolean;
  postJudgment: boolean;
  next?: string | null;
  hasNextHearing?: boolean;
  updatedAgo?: string;
  najizCaseNo?: string | null;
}

interface Counts {
  total?: number;
  active?: number;
  withHearings?: number;
  preparing?: number;
  awaiting?: number;
  inCourt?: number;
  ruled?: number;
  closed?: number;
}

interface LawyerOption {
  id: number;
  name: string;
}

interface Props {
  cases: EmpCaseRow[];
  counts?: Counts;
  departments?: string[];
  types?: string[];
  lawyers?: LawyerOption[];
}

const open = (no: string) => router.visit(`/employee/cases/${encodeURIComponent(no)}`);

const EmployeeCases: React.FC<Props> = ({
  cases = [],
  counts,
  departments = [],
  types = [],
  lawyers = [],
}) => {
  // التبويب النشط
  const [activeTab, setActiveTab] = useState<'active' | 'hearings' | 'preparing' | 'awaiting' | 'inCourt' | 'ruled' | 'closed'>('active');

  // البحث والفلاتر
  const [searchQuery, setSearchQuery] = useState('');
  const [filterType, setFilterType] = useState('all');
  const [filterLawyer, setFilterLawyer] = useState('all');

  // حساب الإحصائيات التراكمية
  const calculatedCounts = useMemo(() => {
    const active = cases.filter((c) => c.isActive).length;
    const withHearings = cases.filter((c) => c.hasNextHearing || (c.next && c.next !== '—')).length;
    const preparing = cases.filter((c) => c.status === 'قيد التحضير').length;
    const awaiting = cases.filter((c) => c.status === 'بانتظار القيد').length;
    const inCourt = cases.filter((c) => c.status === 'منظورة').length;
    const ruled = cases.filter((c) => c.status === 'صدر الحكم').length;
    const closed = cases.filter((c) => !c.isActive).length;

    return {
      total: counts?.total ?? cases.length,
      active: counts?.active ?? active,
      withHearings: counts?.withHearings ?? withHearings,
      preparing: counts?.preparing ?? preparing,
      awaiting: counts?.awaiting ?? awaiting,
      inCourt: counts?.inCourt ?? inCourt,
      ruled: counts?.ruled ?? ruled,
      closed: counts?.closed ?? closed,
    };
  }, [cases, counts]);

  const stats: StatItem[] = [
    ['t-blue', 'scale', calculatedCounts.active, 'قضايا جارية بالمكتب'],
    ['t-red', 'cal', calculatedCounts.withHearings, 'بجلسات محكمة قادمة'],
    ['t-amber', 'folder', calculatedCounts.preparing, 'قيد التحضير واللوائح'],
    ['t-grey', 'send', calculatedCounts.awaiting, 'مرفوعة بانتظار القيد'],
    ['t-cyan', 'doc', calculatedCounts.inCourt, 'منظورة بالمحاكم'],
    ['t-green', 'check', calculatedCounts.ruled, 'صدر فيها حكم'],
  ];

  // تصفية القضايا
  const filteredCases = useMemo(() => {
    return cases.filter((c) => {
      // فلترة التبويب
      if (activeTab === 'active' && !c.isActive) {
        return false;
      }

      if (activeTab === 'hearings' && !(c.hasNextHearing || (c.next && c.next !== '—'))) {
        return false;
      }

      if (activeTab === 'preparing' && c.status !== 'قيد التحضير') {
        return false;
      }

      if (activeTab === 'awaiting' && c.status !== 'بانتظار القيد') {
        return false;
      }

      if (activeTab === 'inCourt' && c.status !== 'منظورة') {
        return false;
      }

      if (activeTab === 'ruled' && c.status !== 'صدر الحكم') {
        return false;
      }

      if (activeTab === 'closed' && c.isActive) {
        return false;
      }

      // فلترة نوع القضية / القسم
      if (filterType !== 'all' && c.type !== filterType && c.dept !== filterType) {
        return false;
      }

      // فلترة المستشار
      if (filterLawyer !== 'all' && String(c.lawyerId) !== filterLawyer && c.lawyer !== filterLawyer) {
        return false;
      }

      // البحث النصي
      if (searchQuery.trim()) {
        const q = foldSearch(searchQuery);
        const noMatch = foldSearch(c.no).includes(q);
        const clientMatch = foldSearch(c.client).includes(q);
        const typeMatch = foldSearch(c.type).includes(q);
        const lawyerMatch = foldSearch(c.lawyer).includes(q);
        const courtMatch = c.court?.toLowerCase().includes(q) ?? false;
        const najizMatch = c.najizCaseNo?.toLowerCase().includes(q) ?? false;

        if (!noMatch && !clientMatch && !typeMatch && !lawyerMatch && !courtMatch && !najizMatch) {
          return false;
        }
      }

      return true;
    });
  }, [cases, activeTab, filterType, filterLawyer, searchQuery]);

  return (
    <>
      {/* ── الترويسة الرئيسية ── */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2>متابعة قضايا المكتب والمحاكم ⚖️</h2>
          <p>مركز التنسيق الإداري لملفات القضايا، متابعة الجلسات والمذكرات، والتواصل اللحظي مع العملاء.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <button className="btn ghost" onClick={() => router.visit('/employee/schedule')} type="button">
            <Icon name="cal" /> مواعيد وجلسات اليوم
          </button>
          <button className="btn" onClick={() => router.visit('/employee/tickets')} type="button">
            <Icon name="ticket" /> تذاكر العملاء
          </button>
        </div>
      </div>

      {/* ── شريط مؤشرات الأداء للقضايا ── */}
      <StatRow items={stats} />

      {/* ── شريط التبويبات والبحث والفلاتر ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px', display: 'flex', flexDirection: 'column', gap: 12 }}>
          {/* التبويبات العلوية */}
          <div className="filter-pills" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', borderBottom: '1px solid var(--line-soft)', paddingBottom: 12 }}>
            <button
              type="button"
              className={`btn sm ${activeTab === 'active' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'active' ? undefined : 'none' }}
              onClick={() => setActiveTab('active')}
            >
              <Icon name="scale" /> كل الجارية ({calculatedCounts.active})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'hearings' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'hearings' ? undefined : 'none' }}
              onClick={() => setActiveTab('hearings')}
            >
              <Icon name="cal" /> جلسات قادمة ({calculatedCounts.withHearings})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'preparing' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'preparing' ? undefined : 'none' }}
              onClick={() => setActiveTab('preparing')}
            >
              <Icon name="folder" /> قيد التحضير ({calculatedCounts.preparing})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'awaiting' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'awaiting' ? undefined : 'none' }}
              onClick={() => setActiveTab('awaiting')}
            >
              <Icon name="send" /> بانتظار القيد ({calculatedCounts.awaiting})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'inCourt' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'inCourt' ? undefined : 'none' }}
              onClick={() => setActiveTab('inCourt')}
            >
              <Icon name="doc" /> منظورة بالمحكمة ({calculatedCounts.inCourt})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'ruled' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'ruled' ? undefined : 'none' }}
              onClick={() => setActiveTab('ruled')}
            >
              <Icon name="check" /> صدر الحكم ({calculatedCounts.ruled})
            </button>
            <button
              type="button"
              className={`btn sm ${activeTab === 'closed' ? '' : 'soft'}`}
              style={{ boxShadow: activeTab === 'closed' ? undefined : 'none', marginInlineStart: 'auto' }}
              onClick={() => setActiveTab('closed')}
            >
              <Icon name="folder" /> الأرشيف ({calculatedCounts.closed})
            </button>
          </div>

          {/* شريط البحث والتصفية */}
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
            <div className="search" style={{ width: 280, padding: '7px 12px' }}>
              <Icon name="search" />
              <input
                placeholder="بحث برقم القضية، العميل، المحكمة..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              {searchQuery && (
                <button type="button" onClick={() => setSearchQuery('')} style={{ color: 'var(--faint)' }}>
                  <Icon name="close" />
                </button>
              )}
            </div>

            <div className="filter-selects" style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
              {(types.length > 0 || departments.length > 0) && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>النوع / القسم:</span>
                  <select
                    value={filterType}
                    onChange={(e) => setFilterType(e.target.value)}
                    style={{ width: 140, padding: '6px 28px 6px 10px', fontSize: 13 }}
                  >
                    <option value="all">كل الأنواع</option>
                    {Array.from(new Set([...types, ...departments])).map((t) => (
                      <option key={t} value={t}>{t}</option>
                    ))}
                  </select>
                </div>
              )}

              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>المستشار:</span>
                <select
                  value={filterLawyer}
                  onChange={(e) => setFilterLawyer(e.target.value)}
                  style={{ width: 140, padding: '6px 28px 6px 10px', fontSize: 13 }}
                >
                  <option value="all">كل المستشارين</option>
                  {lawyers.map((l) => (
                    <option key={l.id} value={String(l.id)}>{l.name}</option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* ── جدول القضايا الرئيسي ── */}
      <div className="card">
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="scale" />
            <h3>قضايا المكتب ({filteredCases.length})</h3>
          </div>
          <span className="sub">انقر على أي صف لفتح ملف القضية والمحادثة</span>
        </div>

        <div className="card-b t-wrap" style={{ padding: 0 }}>
          {filteredCases.length ? (
            <table className="tbl" style={{ minWidth: 760 }}>
              <thead>
                <tr>
                  <th style={{ width: 130 }}>رقم القضية</th>
                  <th style={{ minWidth: 180, maxWidth: 280 }}>العميل والمحكمة</th>
                  <th>نوع الدعوى</th>
                  <th>المستشار المترافع</th>
                  <th>الجلسة القادمة</th>
                  <th>المرحلة والحالة</th>
                  <th style={{ width: 120, textAlign: 'center' }}>الإجراء</th>
                </tr>
              </thead>
              <tbody>
                {filteredCases.map((c) => {
                  const hasUpcoming = c.hasNextHearing || (c.next && c.next !== '—');

                  return (
                    <tr key={c.no} className="click" onClick={() => open(c.no)}>
                      <td className="nowrap">
                        <div className="mono" style={{ fontWeight: 800, fontSize: 13.5 }}>{c.no}</div>
                        <div className="muted" style={{ fontSize: 11 }}>{c.updatedAgo || 'الآن'}</div>
                      </td>
                      <td style={{ minWidth: 180, maxWidth: 280 }}>
                        <b title={c.client}>{truncateWords(c.client, 4)}</b>
                        {c.court && (
                          <div className="muted" title={c.court} style={{ fontSize: 11.5, marginTop: 2 }}>
                            🏛️ {truncateWords(c.court, 5)}
                          </div>
                        )}
                      </td>
                      <td>
                        <div style={{ fontWeight: 600, color: 'var(--ink)' }}>{c.type}</div>
                        {c.dept && c.dept !== c.type && <div className="muted" style={{ fontSize: 11 }}>{c.dept}</div>}
                      </td>
                      <td className="nowrap">
                        <b title={c.lawyer}>{truncateWords(c.lawyer, 4)}</b>
                      </td>
                      <td className="nowrap">
                        {hasUpcoming ? (
                          <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                            <span className="badge-s b-amber" title={c.next ?? ''}><span className="d" /> {truncateWords(c.next ?? '', 4)}</span>
                          </div>
                        ) : (
                          <span className="muted" style={{ fontSize: 12 }}>—</span>
                        )}
                      </td>
                      <td className="nowrap">
                        <Badge text={c.status} tone={c.tone} />
                      </td>
                      <td className="nowrap" style={{ textAlign: 'center' }}>
                        <button
                          className="btn soft sm"
                          type="button"
                          style={{ whiteSpace: 'nowrap' }}
                          onClick={(e) => {
                            e.stopPropagation();
                            open(c.no);
                          }}
                          title="فتح ملف ومحادثة القضية"
                        >
                          <Icon name="scale" /> متابعة الملف
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          ) : (
            <div className="empty">
              <Icon name="scale" />
              <b>{cases.length === 0 ? 'لا توجد قضايا بعد' : 'لا توجد قضايا مطابقة لخيارات البحث والتصفية'}</b>
              {(searchQuery || filterType !== 'all' || filterLawyer !== 'all' || activeTab !== 'active') && (
                <button
                  className="btn soft sm"
                  style={{ marginTop: 10 }}
                  onClick={() => {
                    setSearchQuery('');
                    setFilterType('all');
                    setFilterLawyer('all');
                    setActiveTab('active');
                  }}
                  type="button"
                >
                  إعادة ضبط الفلاتر
                </button>
              )}
            </div>
          )}
        </div>
      </div>
    </>
  );
};

export default EmployeeCases;

