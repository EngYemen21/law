import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

/* ─────────────────────────────────────────────────────────────
   مركز توزيع التذاكر القانونية — الإدارة العليا
   يستخدم نظام CSS المخصص لمنصة سلاسل بابل حصراً
   (card / hero / stat / tbl / btn / chip / tabs …)
───────────────────────────────────────────────────────────── */

export interface TicketItem {
  id: number;
  no: string;
  client: string;
  realClientName: string;
  userAvatar: string;
  type: string;
  subject: string;
  dept: string;
  priority: string;
  lawyer: string;
  lawyerId: number | null;
  status: string;
  tone: string;
  date: string;
  createdAt: string;
  caseRef?: string | null;
  claimAmount?: string | null;
  courtName?: string | null;
  suggestedLawyerId?: number | null;
  suggestedLawyerName?: string | null;
}

export interface LawyerCapacity {
  id: number;
  name: string;
  department: string;
  jobTitle: string;
  initials: string;
  distributionMode: 'auto' | 'manual';
  activeTicketsCount: number;
  activeCasesCount: number;
  totalLoad: number;
  capacityStatus: 'available' | 'moderate' | 'busy';
}

export interface DeptFilter {
  name: string;
  count: number;
}

interface Props {
  tickets: TicketItem[];
  lawyers: LawyerCapacity[];
  departments?: DeptFilter[];
  kpis?: {
    total: number;
    unassigned: number;
    assigned: number;
    urgent: number;
    activeLawyersCount: number;
    availableLawyersCount: number;
  };
}

const priorityWeight = (p: string) => {
  if (p === 'عاجلة جداً') return 4;
  if (p === 'عاجلة' || p === 'عالية') return 3;
  if (p === 'متوسطة') return 2;
  return 1;
};

const priorityTone = (p: string) => {
  if (p === 'عاجلة جداً' || p === 'عاجلة') return 'b-red';
  if (p === 'عالية') return 'b-amber';
  if (p === 'متوسطة') return 'b-blue';
  return 'b-grey';
};

const capacityLabel = (s: 'available' | 'moderate' | 'busy') => {
  if (s === 'available') return { text: 'متاح', tone: 'b-green' };
  if (s === 'moderate') return { text: 'نشط', tone: 'b-amber' };
  return { text: 'ضغط عالٍ', tone: 'b-red' };
};

export const AdminDistribute: React.FC<Props> = ({
  tickets = [],
  lawyers = [],
  departments = [],
}) => {
  const toast = useToast();

  /* ── State ── */
  const [selLawyer, setSelLawyer] = useState<Record<string, number>>(() =>
    Object.fromEntries(
      tickets.map((t) => [t.no, t.suggestedLawyerId ?? t.lawyerId ?? lawyers[0]?.id ?? 0])
    )
  );
  const [search, setSearch] = useState('');
  const [deptFilter, setDeptFilter] = useState('all');
  const [tab, setTab] = useState<'unassigned' | 'assigned' | 'all'>('unassigned');
  const [lawyerFilter, setLawyerFilter] = useState<number | null>(null);
  const [sortBy, setSortBy] = useState<'priority' | 'newest' | 'oldest'>('priority');
  const [assigningNo, setAssigningNo] = useState<string | null>(null);
  const [autoBusy, setAutoBusy] = useState(false);
  const [selectedNos, setSelectedNos] = useState<string[]>([]);
  const [bulkLawyer, setBulkLawyer] = useState<number>(lawyers[0]?.id ?? 0);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [preview, setPreview] = useState<TicketItem | null>(null);

  /* ── Derived ── */
  const unassignedCount = useMemo(
    () => tickets.filter((t) => !t.lawyerId || t.lawyer === '—').length,
    [tickets]
  );

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return tickets
      .filter((t) => {
        if (q) {
          const hit =
            t.no.toLowerCase().includes(q) ||
            t.subject.toLowerCase().includes(q) ||
            t.client.toLowerCase().includes(q) ||
            t.dept.toLowerCase().includes(q) ||
            (t.caseRef ?? '').toLowerCase().includes(q);
          if (!hit) return false;
        }
        if (deptFilter !== 'all' && t.dept !== deptFilter) return false;
        if (tab === 'unassigned' && t.lawyerId && t.lawyer !== '—') return false;
        if (tab === 'assigned' && (!t.lawyerId || t.lawyer === '—')) return false;
        if (lawyerFilter !== null && t.lawyerId !== lawyerFilter) return false;
        return true;
      })
      .sort((a, b) => {
        if (sortBy === 'priority') return priorityWeight(b.priority) - priorityWeight(a.priority);
        if (sortBy === 'newest') return b.id - a.id;
        return a.id - b.id;
      });
  }, [tickets, search, deptFilter, tab, lawyerFilter, sortBy]);

  /* ── Actions ── */
  const assign = (no: string, lawyerId?: number) => {
    const lid = lawyerId ?? selLawyer[no];
    if (!lid) return toast('يرجى اختيار المستشار أولاً');
    setAssigningNo(no);
    router.post(
      `/admin/distribute/${encodeURIComponent(no)}`,
      { lawyer_id: lid },
      {
        preserveScroll: true,
        onSuccess: () => { setAssigningNo(null); toast(`تم إسناد التذكرة ${no} ✨`); },
        onError: () => { setAssigningNo(null); toast('تعذّر الإسناد، حاول مجدداً'); },
      }
    );
  };

  const runAuto = () => {
    if (!unassignedCount || autoBusy) return;
    if (!window.confirm(`سيتم توزيع ${unassignedCount} تذكرة تلقائياً حسب التخصص والحمل. متابعة؟`)) return;
    setAutoBusy(true);
    router.post('/admin/distribute/auto', {}, {
      preserveScroll: true,
      onSuccess: () => toast('اكتمل التوزيع التلقائي ⚡'),
      onError: () => toast('فشل التوزيع، حاول مجدداً'),
      onFinish: () => setAutoBusy(false),
    });
  };

  const bulkAssign = () => {
    if (!selectedNos.length || !bulkLawyer || bulkBusy) return;
    setBulkBusy(true);
    let done = 0;
    selectedNos.forEach((no) => {
      router.post(
        `/admin/distribute/${encodeURIComponent(no)}`,
        { lawyer_id: bulkLawyer },
        {
          preserveScroll: true,
          onFinish: () => {
            done++;
            if (done >= selectedNos.length) {
              setBulkBusy(false);
              setSelectedNos([]);
              toast(`تم إسناد ${selectedNos.length} تذاكر بنجاح!`);
            }
          },
        }
      );
    });
  };

  const toggleSelect = (no: string) =>
    setSelectedNos((p) => p.includes(no) ? p.filter((x) => x !== no) : [...p, no]);

  const toggleAll = () =>
    setSelectedNos(selectedNos.length === filtered.length ? [] : filtered.map((t) => t.no));

  /* ─────────────────── RENDER ─────────────────── */
  return (
    <>
      {/* ── 1. بانر هيدر ── */}
      <div className="hero" style={{ marginBottom: 20 }}>
        <div className="hero-cta" style={{ marginTop: 0, marginBottom: 12 }}>
          <span style={{ fontSize: 11, fontWeight: 700, opacity: .75, letterSpacing: '.5px', textTransform: 'uppercase' }}>
            ● منظومة الفرز والإسناد الذكي · الإدارة العليا
          </span>
        </div>
        <h2 style={{ fontSize: 26, marginBottom: 6 }}>مركز توزيع التذاكر القانونية</h2>
        <p>إسناد طلبات الموكلين للمستشارين القانونيين يدوياً أو تلقائياً حسب التخصص والحمل والأقدمية.</p>
        <div className="hero-cta">
          <button
            className={`hero-b${unassignedCount ? '' : ' ghost'}`}
            disabled={autoBusy || !unassignedCount}
            onClick={runAuto}
            type="button"
          >
            <Icon name="scale" />
            {autoBusy ? 'جارٍ التوزيع…' : `توزيع ذكي تلقائي (${unassignedCount})`}
          </button>
          <button
            className="hero-b ghost"
            type="button"
            onClick={() => router.visit(window.location.pathname)}
          >
            <Icon name="cal" /> تحديث
          </button>
        </div>
      </div>

      {/* ── 2. شبكة مؤشرات KPI ── */}
      <div className="stats" style={{ gridTemplateColumns: 'repeat(4,1fr)', marginBottom: 20 }}>
        <div
          className={`stat t-amber${tab === 'unassigned' ? ' sel' : ''}`}
          style={{ cursor: 'pointer', outline: tab === 'unassigned' ? '2px solid var(--amber)' : 'none' }}
          onClick={() => setTab('unassigned')}
        >
          <div className="si"><Icon name="reply" /></div>
          <div className="num">{unassignedCount}</div>
          <div className="lbl">بانتظار التوزيع</div>
        </div>
        <div
          className="stat t-red"
          style={{ cursor: 'pointer' }}
          onClick={() => setTab('all')}
        >
          <div className="si"><Icon name="alert" /></div>
          <div className="num">{tickets.filter((t) => ['عاجلة جداً','عاجلة','عالية'].includes(t.priority)).length}</div>
          <div className="lbl">أولوية عاجلة</div>
        </div>
        <div
          className={`stat t-blue${tab === 'assigned' ? ' sel' : ''}`}
          style={{ cursor: 'pointer', outline: tab === 'assigned' ? '2px solid var(--primary)' : 'none' }}
          onClick={() => setTab('assigned')}
        >
          <div className="si"><Icon name="folder" /></div>
          <div className="num">{tickets.filter((t) => t.lawyerId && t.lawyer !== '—').length}</div>
          <div className="lbl">مسندة للمستشارين</div>
        </div>
        <div className="stat t-green">
          <div className="si"><Icon name="user" /></div>
          <div className="num">{lawyers.filter((l) => l.capacityStatus === 'available').length}<span style={{ fontSize: 16, fontWeight: 600, color: 'var(--muted)' }}>/{lawyers.length}</span></div>
          <div className="lbl">مستشار متاح</div>
        </div>
      </div>

      {/* ── 3. مصفوفة سعة المستشارين ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="user" />
            <h3>سعة وعبء عمل فريق المستشارين القانونيين</h3>
          </div>
          {lawyerFilter !== null && (
            <button
              type="button"
              className="btn soft sm"
              onClick={() => setLawyerFilter(null)}
            >
              عرض الكل ✕
            </button>
          )}
        </div>
        <div className="card-b">
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(190px,1fr))', gap: 12 }}>
            {lawyers.map((l) => {
              const cap = capacityLabel(l.capacityStatus);
              const isActive = lawyerFilter === l.id;
              return (
                <div
                  key={l.id}
                  onClick={() => setLawyerFilter(isActive ? null : l.id)}
                  style={{
                    border: `1.5px solid ${isActive ? 'var(--primary)' : 'var(--line)'}`,
                    borderRadius: 'var(--r-sm)',
                    padding: '13px 14px',
                    background: isActive ? 'rgba(14,92,156,.04)' : 'var(--paper-2)',
                    cursor: 'pointer',
                    transition: '.15s',
                    boxShadow: isActive ? '0 0 0 3px rgba(14,92,156,.1)' : 'none',
                  }}
                >
                  {/* رأس البطاقة: الأحرف الأولى + الاسم */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
                    <div className="avatar" style={{ width: 36, height: 36, fontSize: 13, flex: '0 0 36px' }}>
                      {(l.initials || l.name.slice(0, 2))}
                    </div>
                    <div>
                      <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--ink)' }}>{l.name}</div>
                      <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 1 }}>{l.department}</div>
                    </div>
                  </div>

                  {/* إحصاءات الحمل */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: 'var(--muted)', marginBottom: 8 }}>
                    <span>تذاكر: <strong style={{ color: 'var(--ink)' }}>{l.activeTicketsCount}</strong></span>
                    <span>قضايا: <strong style={{ color: 'var(--ink)' }}>{l.activeCasesCount}</strong></span>
                  </div>

                  {/* شريط حمل العمل */}
                  <div style={{ background: 'var(--line)', borderRadius: 4, height: 4, marginBottom: 8, overflow: 'hidden' }}>
                    <div style={{
                      height: '100%',
                      borderRadius: 4,
                      width: `${Math.min((l.totalLoad / 15) * 100, 100)}%`,
                      background: l.capacityStatus === 'available' ? 'var(--success)' : l.capacityStatus === 'moderate' ? 'var(--amber)' : 'var(--red)',
                      transition: 'width .4s',
                    }} />
                  </div>

                  {/* حالة السعة + وضع التوزيع */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <span className={`badge-s ${cap.tone}`} style={{ fontSize: 11, padding: '3px 9px' }}>
                      <span className="d" /> {cap.text}
                    </span>
                    <span style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 600 }}>
                      {l.distributionMode === 'auto' ? 'آلي' : 'يدوي'}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* ── 4. شريط الفلترة والبحث ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          {/* بحث */}
          <div className="search" style={{ width: '100%', marginBottom: 14 }}>
            <Icon name="search" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="ابحث برقم التذكرة، الموضوع، اسم العميل، أو مرجع القضية…"
            />
            {search && (
              <button type="button" style={{ color: 'var(--faint)', fontWeight: 700 }} onClick={() => setSearch('')}>✕</button>
            )}
          </div>

          {/* تبويبات الحالة + ترتيب */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <div className="tabs" style={{ margin: 0 }}>
              <button className={`tab${tab === 'unassigned' ? ' on' : ''}`} type="button" onClick={() => setTab('unassigned')}>
                غير مسندة ({unassignedCount})
              </button>
              <button className={`tab${tab === 'assigned' ? ' on' : ''}`} type="button" onClick={() => setTab('assigned')}>
                المسندة ({tickets.length - unassignedCount})
              </button>
              <button className={`tab${tab === 'all' ? ' on' : ''}`} type="button" onClick={() => setTab('all')}>
                الكل ({tickets.length})
              </button>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>ترتيب:</span>
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as any)}
                style={{ width: 'auto', padding: '7px 32px 7px 12px', fontSize: 13 }}
              >
                <option value="priority">الأولوية (الأعلى أولاً)</option>
                <option value="newest">الأحدث وصولاً</option>
                <option value="oldest">الأقدم انتظاراً</option>
              </select>
            </div>
          </div>

          {/* فلاتر الأقسام */}
          {departments.length > 0 && (
            <div className="chips" style={{ marginTop: 12 }}>
              <button
                type="button"
                className={`chip${deptFilter === 'all' ? '' : ' muted'} sel-toggle${deptFilter === 'all' ? ' on' : ''}`}
                onClick={() => setDeptFilter('all')}
              >
                جميع الأقسام ({tickets.length})
              </button>
              {departments.map((d) => (
                <button
                  key={d.name}
                  type="button"
                  className={`chip sel-toggle${deptFilter === d.name ? ' on' : ' muted'}`}
                  onClick={() => setDeptFilter(deptFilter === d.name ? 'all' : d.name)}
                >
                  {d.name} ({d.count})
                </button>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* ── 5. شريط الإجراءات الجماعية (يظهر عند التحديد) ── */}
      {selectedNos.length > 0 && (
        <div
          style={{
            position: 'sticky', top: 12, zIndex: 40,
            background: 'var(--deep)', color: '#fff',
            borderRadius: 'var(--r-sm)', padding: '12px 18px',
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            gap: 12, marginBottom: 12, boxShadow: 'var(--shadow-lg)', flexWrap: 'wrap',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span style={{
              background: 'var(--primary)', borderRadius: 8,
              padding: '2px 10px', fontWeight: 800, fontSize: 15,
            }}>
              {selectedNos.length}
            </span>
            <span style={{ fontWeight: 600, fontSize: 13 }}>تذكرة محددة — الإسناد الجماعي</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <select
              value={bulkLawyer}
              onChange={(e) => setBulkLawyer(Number(e.target.value))}
              style={{ background: 'rgba(255,255,255,.12)', color: '#fff', border: '1px solid rgba(255,255,255,.25)', borderRadius: 10, padding: '7px 32px 7px 12px', fontSize: 12 }}
            >
              {lawyers.map((l) => (
                <option key={l.id} value={l.id} style={{ background: 'var(--deep)' }}>
                  {l.name} · {l.activeTicketsCount} تذاكر
                </option>
              ))}
            </select>
            <button type="button" className="hero-b" disabled={bulkBusy} onClick={bulkAssign} style={{ padding: '8px 16px', fontSize: 13 }}>
              <Icon name="reply" /> {bulkBusy ? 'جارٍ الإسناد…' : 'إسناد للمختار ⚡'}
            </button>
            <button type="button" className="hero-b ghost" onClick={() => setSelectedNos([])} style={{ padding: '8px 14px', fontSize: 12 }}>
              إلغاء
            </button>
          </div>
        </div>
      )}

      {/* ── 6. جدول التذاكر ── */}
      <div className="card">
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <input
              type="checkbox"
              checked={filtered.length > 0 && selectedNos.length === filtered.length}
              onChange={toggleAll}
              style={{ width: 16, height: 16, cursor: 'pointer' }}
            />
            <h3>قائمة التذاكر ({filtered.length})</h3>
          </div>
          <span className="sub" style={{ color: unassignedCount > 0 ? 'var(--amber)' : 'var(--faint)' }}>
            {unassignedCount > 0 ? `• ${unassignedCount} بحاجة لتعيين مستشار` : 'كل التذاكر مسندة'}
          </span>
        </div>
        <div className="card-b t-wrap" style={{ padding: 0 }}>
          {filtered.length > 0 ? (
            <table className="tbl">
              <thead>
                <tr>
                  <th style={{ width: 40 }}></th>
                  <th>التذكرة</th>
                  <th>الموضوع</th>
                  <th>القسم</th>
                  <th>الأولوية</th>
                  <th>المحامي الحالي</th>
                  <th>الإسناد إلى</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((t) => {
                  const isUnassigned = !t.lawyerId || t.lawyer === '—';
                  const isAssigning = assigningNo === t.no;
                  const isChecked = selectedNos.includes(t.no);
                  return (
                    <tr
                      key={t.no}
                      className="click"
                      style={{ background: isChecked ? 'rgba(14,92,156,.03)' : undefined }}
                    >
                      {/* checkbox */}
                      <td style={{ paddingRight: 14 }}>
                        <input
                          type="checkbox"
                          checked={isChecked}
                          onChange={() => toggleSelect(t.no)}
                          onClick={(e) => e.stopPropagation()}
                          style={{ width: 15, height: 15, cursor: 'pointer' }}
                        />
                      </td>

                      {/* رقم التذكرة */}
                      <td>
                        <span className="mono">{t.no}</span>
                        {t.caseRef && (
                          <div style={{ fontSize: 11, color: 'var(--primary)', marginTop: 3 }}>
                            📁 {t.caseRef}
                          </div>
                        )}
                      </td>

                      {/* الموضوع + العميل */}
                      <td>
                        <div
                          style={{ fontWeight: 600, fontSize: 13.5, color: 'var(--ink)', cursor: 'pointer' }}
                          onClick={() => setPreview(t)}
                        >
                          {t.subject}
                        </div>
                        <div style={{ fontSize: 12, color: 'var(--faint)', marginTop: 2 }}>
                          {t.client} · {t.date}
                        </div>
                      </td>

                      {/* القسم */}
                      <td className="muted">{t.dept}</td>

                      {/* الأولوية */}
                      <td>
                        <Badge text={t.priority} tone={priorityTone(t.priority)} />
                      </td>

                      {/* المحامي الحالي */}
                      <td>
                        {isUnassigned ? (
                          <span style={{ color: 'var(--faint)', fontSize: 13 }}>—</span>
                        ) : (
                          <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)' }}>{t.lawyer}</span>
                        )}
                      </td>

                      {/* الإسناد */}
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          {/* مقترح الذكاء الاصطناعي */}
                          {isUnassigned && t.suggestedLawyerId && t.suggestedLawyerName && (
                            <button
                              type="button"
                              className="btn soft sm"
                              disabled={isAssigning}
                              onClick={() => assign(t.no, t.suggestedLawyerId!)}
                              style={{ fontSize: 11.5, padding: '5px 10px', color: 'var(--success)', borderColor: 'var(--success)' }}
                              title="إسناد الأنسب فوراً"
                            >
                              ⚡ {t.suggestedLawyerName}
                            </button>
                          )}
                          <select
                            value={selLawyer[t.no] ?? 0}
                            onChange={(e) => setSelLawyer((p) => ({ ...p, [t.no]: Number(e.target.value) }))}
                            style={{ padding: '6px 28px 6px 10px', fontSize: 12.5, minWidth: 140 }}
                            onClick={(e) => e.stopPropagation()}
                          >
                            {lawyers.map((l) => (
                              <option key={l.id} value={l.id}>
                                {l.name} ({l.activeTicketsCount})
                              </option>
                            ))}
                          </select>
                        </div>
                      </td>

                      {/* أزرار الإجراء */}
                      <td>
                        <div style={{ display: 'flex', gap: 6 }}>
                          <button
                            type="button"
                            className={`btn${isUnassigned ? '' : ' soft'} sm`}
                            disabled={isAssigning}
                            onClick={() => assign(t.no)}
                          >
                            <Icon name="reply" />
                            {isAssigning ? '…' : isUnassigned ? 'إسناد' : 'تغيير'}
                          </button>
                          <button
                            type="button"
                            className="btn soft sm"
                            onClick={() => setPreview(t)}
                            title="معاينة التذكرة"
                          >
                            <Icon name="search" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          ) : (
            <div className="empty">
              <Icon name="folder" />
              <b>لا توجد تذاكر مطابقة للفلاتر المحددة</b>
              <button
                type="button"
                className="btn ghost sm"
                style={{ margin: '12px auto 0' }}
                onClick={() => { setSearch(''); setDeptFilter('all'); setTab('all'); setLawyerFilter(null); }}
              >
                إعادة ضبط الفلاتر
              </button>
            </div>
          )}
        </div>
      </div>

      {/* ── 7. نافذة معاينة التذكرة ── */}
      {preview && (
        <Modal
          title={`التذكرة ${preview.no}`}
          open={Boolean(preview)}
          onClose={() => setPreview(null)}
        >
          <div>
            {/* معلومات أساسية */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10, background: 'var(--paper-2)', borderRadius: 'var(--r-sm)', padding: '12px 14px', marginBottom: 14 }}>
              <div>
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>القسم</div>
                <div style={{ fontSize: 13, fontWeight: 700 }}>{preview.dept}</div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>الأولوية</div>
                <div style={{ marginTop: 3 }}><Badge text={preview.priority} tone={priorityTone(preview.priority)} /></div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>الحالة</div>
                <div style={{ fontSize: 13, fontWeight: 700 }}>{preview.status}</div>
              </div>
            </div>

            {/* الموضوع */}
            <div className="kv">
              <span className="k">الموضوع</span>
              <span className="v" style={{ maxWidth: '70%', textAlign: 'end' }}>{preview.subject}</span>
            </div>
            <div className="kv">
              <span className="k">العميل</span>
              <span className="v">{preview.client}</span>
            </div>
            {preview.caseRef && (
              <div className="kv">
                <span className="k">مرجع القضية</span>
                <span className="v">{preview.caseRef}</span>
              </div>
            )}
            {preview.claimAmount && (
              <div className="kv">
                <span className="k">قيمة المطالبة</span>
                <span className="v">{preview.claimAmount}</span>
              </div>
            )}
            <div className="kv">
              <span className="k">المستشار الحالي</span>
              <span className="v">{preview.lawyer}</span>
            </div>

            {/* إسناد من النافذة */}
            <div style={{ marginTop: 16, borderTop: '1px solid var(--line-soft)', paddingTop: 14 }}>
              <div className="field" style={{ marginBottom: 10 }}>
                <label>إسناد إلى مستشار آخر:</label>
                <select
                  value={selLawyer[preview.no] ?? 0}
                  onChange={(e) => setSelLawyer((p) => ({ ...p, [preview.no]: Number(e.target.value) }))}
                >
                  {lawyers.map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name} — {l.department} ({l.activeTicketsCount} تذاكر نشطة)
                    </option>
                  ))}
                </select>
              </div>
              <button
                type="button"
                className="btn block"
                onClick={() => { assign(preview.no); setPreview(null); }}
              >
                <Icon name="reply" /> تأكيد الإسناد
              </button>
            </div>
          </div>
        </Modal>
      )}
    </>
  );
};

export default AdminDistribute;
