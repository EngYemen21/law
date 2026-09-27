import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import LawyerSuggestionHint, { type LawyerSuggestionData } from '@/components/babylon/LawyerSuggestionHint';
import Modal from '@/components/babylon/Modal';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import { useToast } from '@/components/babylon/Toast';
import { foldSearch, isUrgentTicket, TICKET_PRIORITIES } from '@/lib/employee-data';
import Icon from '@/lib/icons';

/* ─────────────────────────────────────────────────────────────
   مركز التوزيع والإسناد الشامل للأعمال القانونية — الإدارة العليا
   يغطي: التذاكر والطلبات · القضايا القضائية · ملفات التنفيذ · الاستشارات
   يستخدم نظام CSS المخصص لمنصة سلاسل بابل حصراً
   (card / hero / stat / tbl / btn / chip / tabs …)
───────────────────────────────────────────────────────────── */

export type WorkItemKind = 'ticket' | 'case' | 'execution' | 'consult';

export interface DistributeItem {
  id: number;
  no: string;
  client: string;
  userAvatar: string;
  type: string;
  subject: string;
  dept: string;
  /** `null` للقضايا وملفّات التنفيذ — لا عمود أولويّة لهما، فلا شارة ولا وزن في الفرز. */
  priority: string | null;
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
  /** اقتراح النظام موسوماً بالتخصّص — للتذاكر غير المسنَدة وحدها */
  suggestion?: LawyerSuggestionData | null;
  itemKind: WorkItemKind;
  itemKindLabel: string;
  badgeTone: string;
}

// التوافق العكسي مع أي تصدير سابق
export type TicketItem = DistributeItem;

export interface LawyerCapacity {
  id: number;
  name: string;
  department: string;
  jobTitle: string;
  initials: string;
  distributionMode: 'auto' | 'manual';
  activeTicketsCount: number;
  activeCasesCount: number;
  activeExecutionsCount?: number;
  activeConsultsCount?: number;
  totalLoad: number;
  capacityStatus: 'available' | 'moderate' | 'busy';
}

export interface DeptFilter {
  name: string;
  count: number;
}

interface Props {
  tickets?: DistributeItem[];
  cases?: DistributeItem[];
  executions?: DistributeItem[];
  consults?: DistributeItem[];
  lawyers: LawyerCapacity[];
  departments?: DeptFilter[];
  kpis?: {
    total: number;
    unassigned: number;
    assigned: number;
    urgent: number;
    activeLawyersCount: number;
    availableLawyersCount: number;
    ticketsTotal?: number;
    ticketsUnassigned?: number;
    casesTotal?: number;
    casesUnassigned?: number;
    executionsTotal?: number;
    executionsUnassigned?: number;
    consultsTotal?: number;
    consultsUnassigned?: number;
    grandTotal?: number;
    grandUnassigned?: number;
  };
}

/**
 * وزن الفرز «الأعلى أولاً» من كتالوج الأولويّات الواحد (`TicketJourney::PRIORITIES`) — لا من
 * مفرداتٍ ميّتة («عاجلة»…) لم تعد تُكتب. عالية 3 · متوسطة 2 · منخفضة 1، وغير المعروف 1 والفارغ 0 كما كانا.
 */
const priorityWeight = (p: string | null) => {
  if (!p) return 0;
  const rank = TICKET_PRIORITIES.indexOf(p);

  return rank < 0 ? 1 : TICKET_PRIORITIES.length - rank;
};

const priorityTone = (p: string) => {
  if (isUrgentTicket(p) || p === 'عالية') return 'b-red';
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
  cases = [],
  executions = [],
  consults = [],
  lawyers = [],
  departments = [],
  kpis,
}) => {
  const ask = useConfirm();
  const toast = useToast();

  // تجميع كافة الأعمال في مصفوفة موحدة
  const allItems = useMemo<DistributeItem[]>(() => {
    return [
      ...tickets.map(t => ({ ...t, itemKind: 'ticket' as const, itemKindLabel: t.itemKindLabel || 'تذكرة طلب', badgeTone: t.badgeTone || 'b-blue' })),
      ...cases.map(c => ({ ...c, itemKind: 'case' as const, itemKindLabel: c.itemKindLabel || 'قضية قضائية', badgeTone: c.badgeTone || 'b-amber' })),
      ...executions.map(e => ({ ...e, itemKind: 'execution' as const, itemKindLabel: e.itemKindLabel || 'ملف تنفيذ', badgeTone: e.badgeTone || 'b-purple' })),
      ...consults.map(cn => ({ ...cn, itemKind: 'consult' as const, itemKindLabel: cn.itemKindLabel || 'جلسة استشارة', badgeTone: cn.badgeTone || 'b-teal' })),
    ];
  }, [tickets, cases, executions, consults]);

  const getItemKey = (item: DistributeItem) => `${item.itemKind}:${item.id}`;

  /* ── State ── */
  const [kindFilter, setKindFilter] = useState<'all' | WorkItemKind>('all');
  const [selLawyer, setSelLawyer] = useState<Record<string, number>>(() => {
    const initial: Record<string, number> = {};
    allItems.forEach((it) => {
      initial[getItemKey(it)] = it.suggestedLawyerId ?? it.lawyerId ?? lawyers[0]?.id ?? 0;
    });
    return initial;
  });

  const [search, setSearch] = useState('');
  const [deptFilter, setDeptFilter] = useState('all');
  const [statusTab, setStatusTab] = useState<'unassigned' | 'assigned' | 'all'>('unassigned');
  const [lawyerFilter, setLawyerFilter] = useState<number | null>(null);
  const [sortBy, setSortBy] = useState<'priority' | 'newest' | 'oldest'>('priority');
  const [assigningKey, setAssigningKey] = useState<string | null>(null);
  const [autoBusy, setAutoBusy] = useState(false);
  const [selectedKeys, setSelectedKeys] = useState<string[]>([]);
  const [bulkLawyer, setBulkLawyer] = useState<number>(lawyers[0]?.id ?? 0);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [preview, setPreview] = useState<DistributeItem | null>(null);

  /* ── Counts & Metrics ── */
  const grandTotal = kpis?.grandTotal ?? allItems.length;
  const grandUnassigned = kpis?.grandUnassigned ?? allItems.filter(i => !i.lawyerId || i.lawyer === '—').length;
  const unassignedTicketsCount = kpis?.ticketsUnassigned ?? tickets.filter(t => !t.lawyerId || t.lawyer === '—').length;
  const unassignedCasesCount = kpis?.casesUnassigned ?? cases.filter(c => !c.lawyerId || c.lawyer === '—').length;
  const unassignedExecsCount = kpis?.executionsUnassigned ?? executions.filter(e => !e.lawyerId || e.lawyer === '—').length;
  const unassignedConsultsCount = kpis?.consultsUnassigned ?? consults.filter(cn => !cn.lawyerId || cn.lawyer === '—').length;

  // العناصر المصفاة بنوع العمل أولاً
  const itemsByKind = useMemo(() => {
    if (kindFilter === 'all') return allItems;
    return allItems.filter(i => i.itemKind === kindFilter);
  }, [allItems, kindFilter]);

  const currentKindUnassigned = useMemo(() => {
    return itemsByKind.filter(i => !i.lawyerId || i.lawyer === '—').length;
  }, [itemsByKind]);

  const filtered = useMemo(() => {
    const q = foldSearch(search);
    return itemsByKind
      .filter((it) => {
        if (q) {
          const hit =
            foldSearch(it.no).includes(q) ||
            foldSearch(it.subject).includes(q) ||
            foldSearch(it.client).includes(q) ||
            foldSearch(it.dept).includes(q) ||
            (it.caseRef ?? '').toLowerCase().includes(q) ||
            (it.courtName ?? '').toLowerCase().includes(q);
          if (!hit) return false;
        }
        if (deptFilter !== 'all' && it.dept !== deptFilter) return false;
        if (statusTab === 'unassigned' && it.lawyerId && it.lawyer !== '—') return false;
        if (statusTab === 'assigned' && (!it.lawyerId || it.lawyer === '—')) return false;
        if (lawyerFilter !== null && it.lawyerId !== lawyerFilter) return false;
        return true;
      })
      .sort((a, b) => {
        if (sortBy === 'priority') return priorityWeight(b.priority) - priorityWeight(a.priority);
        if (sortBy === 'newest') return b.id - a.id;
        return a.id - b.id;
      });
  }, [itemsByKind, search, deptFilter, statusTab, lawyerFilter, sortBy]);

  /* ── Actions ── */
  const assign = (item: DistributeItem, lawyerId?: number) => {
    const key = getItemKey(item);
    const lid = lawyerId ?? selLawyer[key] ?? lawyers[0]?.id;
    if (!lid) return toast('يرجى اختيار المستشار أولاً', 'warning');

    setAssigningKey(key);

    let url = `/admin/distribute/${encodeURIComponent(item.no)}`;
    if (item.itemKind === 'case') {
      url = `/admin/distribute/case/${encodeURIComponent(item.no)}`;
    } else if (item.itemKind === 'execution') {
      url = `/admin/distribute/execution/${encodeURIComponent(item.no)}`;
    } else if (item.itemKind === 'consult') {
      url = `/admin/distribute/consult/${encodeURIComponent(item.id)}`;
    }

    router.post(
      url,
      { lawyer_id: lid },
      {
        preserveScroll: true,
        // رسالة النجاح من الخادم (flash) يعرضها التخطيط — لا إشعار ثانٍ هنا
        onSuccess: () => setAssigningKey(null),
        onError: (errors) => {
          setAssigningKey(null);
          const msg =
            (errors && (errors.message || Object.values(errors)[0])) ||
            'تعذّر الإسناد، تحقق من صلاحية المعاملة أو حالة المستشار';
          toast(String(msg), 'error');
        },
      }
    );
  };

  const runAuto = async () => {
    if (!unassignedTicketsCount || autoBusy) return;

    const ok = await ask({
      title: 'التوزيع التلقائيّ للتذاكر',
      message: `سيتم توزيع ${unassignedTicketsCount} تذكرة مفتوحة تلقائياً على المستشارين حسب التخصص ومعدل الحمل.`,
      confirmLabel: 'توزيع الآن',
    });

    if (!ok) return;
    setAutoBusy(true);
    router.post('/admin/distribute/auto', {}, {
      preserveScroll: true,
      // الخادم يقول ما وقع فعلاً («جارٍ توزيع N في الخلفية») — لا «اكتمل» مختلَقة هنا
      onError: (errors) => {
        const msg =
          (errors && (errors.message || Object.values(errors)[0])) ||
          'فشل التوزيع التلقائي، يرجى المحاولة لاحقاً';
        toast(String(msg), 'error');
      },
      onFinish: () => setAutoBusy(false),
    });
  };

  /**
   * **الإسناد الجماعيّ طلبٌ واحد** (`DistributeController::bulk`). كانت حلقةٌ تطلق `router.post` لكلّ
   * عنصر، وInertia يلغي الزيارة الجارية عند بدء أخرى — فيُسنَد بعضها ويُلغى بعضها، والإشعار يقول
   * «تم إسناد N أعمال» في كلّ حال. الخادم الآن يُسند كلّاً بمساره الفرديّ ويُعلن: ما أُسند (flash)
   * وما رُفض ولماذا (خطأ) — فالإشعاران من الخادم لا من عدٍّ في المتصفّح.
   */
  const bulkAssign = () => {
    if (!selectedKeys.length) return toast('يرجى اختيار معاملة واحدة على الأقل للإسناد الجماعي', 'warning');
    if (!bulkLawyer) return toast('يرجى اختيار المستشار أولاً', 'warning');
    if (bulkBusy) return;

    const itemsToAssign = allItems.filter(i => selectedKeys.includes(getItemKey(i)));
    if (!itemsToAssign.length) return;

    setBulkBusy(true);
    router.post(
      '/admin/distribute/bulk',
      { lawyer_id: bulkLawyer, items: itemsToAssign.map((i) => ({ kind: i.itemKind, id: i.id })) },
      {
        preserveScroll: true,
        onSuccess: () => setSelectedKeys([]),
        onError: (errors) => {
          // ما أُسند منها خرج من القائمة بعد إعادة التحميل — ويبقى المرفوض مختاراً لإعادة المحاولة
          toast(String(errors.message ?? Object.values(errors)[0] ?? 'تعذّر الإسناد الجماعي'), 'error');
        },
        onFinish: () => setBulkBusy(false),
      }
    );
  };

  const toggleSelect = (key: string) =>
    setSelectedKeys((p) => (p.includes(key) ? p.filter((x) => x !== key) : [...p, key]));

  const toggleAll = () => {
    const currentFilteredKeys = filtered.map(getItemKey);
    setSelectedKeys(selectedKeys.length === currentFilteredKeys.length ? [] : currentFilteredKeys);
  };

  /* ─────────────────── RENDER ─────────────────── */
  return (
    <>
      {/* ── 1. بانر هيدر المنظومة الشاملة ── */}
      <div className="hero" style={{ marginBottom: 20 }}>
        <div className="hero-cta" style={{ marginTop: 0, marginBottom: 12 }}>
          <span style={{ fontSize: 11, fontWeight: 700, opacity: 0.75, letterSpacing: '.5px', textTransform: 'uppercase' }}>
            ● منظومة الفرز والإسناد الشامل للأعمال · الإدارة العليا
          </span>
        </div>
        <h2 style={{ fontSize: 26, marginBottom: 6 }}>مركز التوزيع والإسناد الشامل للأعمال القانونية</h2>
        <p>
          إسناد وتوزيع التذاكر والطلبات، القضايا القضائية، ملفات التنفيذ، وجلسات الاستشارة إلى المستشارين القانونيين يدوياً أو آلياً وفق السعة والتخصص ومعدل الحمل الفعلي.
        </p>
        <div className="hero-cta">
          <button
            className={`hero-b${unassignedTicketsCount ? '' : ' ghost'}`}
            disabled={autoBusy || !unassignedTicketsCount}
            onClick={runAuto}
            type="button"
            title="توزيع تذاكر وطلبات العملاء آلياً"
          >
            <Icon name="scale" />
            {autoBusy ? 'جارٍ التوزيع…' : `توزيع ذكي للتذاكر (${unassignedTicketsCount})`}
          </button>
          <button
            className="hero-b ghost"
            type="button"
            onClick={() => router.reload()}
          >
            <Icon name="cal" /> تحديث البيانات
          </button>
        </div>
      </div>

      {/* ── 2. شبكة مؤشرات KPI الشاملة ── */}
      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 200px), 1fr))', marginBottom: 20 }}>
        <div
          className={`stat t-amber${statusTab === 'unassigned' && kindFilter === 'all' ? ' sel' : ''}`}
          style={{ cursor: 'pointer', outline: statusTab === 'unassigned' && kindFilter === 'all' ? '2px solid var(--amber)' : 'none' }}
          onClick={() => { setKindFilter('all'); setStatusTab('unassigned'); }}
        >
          <div className="si"><Icon name="reply" /></div>
          <div className="num">{grandUnassigned}</div>
          <div className="lbl">إجمالي غير المُسند (كل القطاعات)</div>
          <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 4 }}>
            {unassignedTicketsCount} تذكرة · {unassignedCasesCount} قضية · {unassignedExecsCount} تنفيذ · {unassignedConsultsCount} استشارة
          </div>
        </div>

        <div
          className={`stat t-amber${kindFilter === 'case' ? ' sel' : ''}`}
          style={{ cursor: 'pointer' }}
          onClick={() => setKindFilter('case')}
        >
          <div className="si"><Icon name="scale" /></div>
          <div className="num">{cases.length}</div>
          <div className="lbl">القضايا القضائية النشطة</div>
          <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 4 }}>
            {unassignedCasesCount > 0 ? `${unassignedCasesCount} بحاجة لإسناد محامٍ` : 'جميع القضايا مسندة'}
          </div>
        </div>

        <div
          className={`stat t-purple${kindFilter === 'execution' ? ' sel' : ''}`}
          style={{ cursor: 'pointer' }}
          onClick={() => setKindFilter('execution')}
        >
          <div className="si"><Icon name="exec" /></div>
          <div className="num">{executions.length}</div>
          <div className="lbl">ملفات وسندات التنفيذ</div>
          <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 4 }}>
            {unassignedExecsCount > 0 ? `${unassignedExecsCount} بحاجة لإسناد` : 'كل الملفات مسندة'}
          </div>
        </div>

        <div className="stat t-green">
          <div className="si"><Icon name="user" /></div>
          <div className="num">
            {lawyers.filter((l) => l.capacityStatus === 'available').length}
            <span style={{ fontSize: 16, fontWeight: 600, color: 'var(--muted)' }}>/{lawyers.length}</span>
          </div>
          <div className="lbl">مستشار قانوني متاح</div>
          <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 4 }}>
            وفق مصفوفة الحمل الشامل (تذاكر + قضايا + تنفيذ + استشارات)
          </div>
        </div>
      </div>

      {/* ── 3. مصفوفة سعة المستشارين الشاملة ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="user" />
            <h3>سعة ومعدل عبء العمل الشامل لفريق المستشارين</h3>
          </div>
          {lawyerFilter !== null && (
            <button
              type="button"
              className="btn soft sm"
              onClick={() => setLawyerFilter(null)}
            >
              عرض كل المستشارين ✕
            </button>
          )}
        </div>
        <div className="card-b">
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(210px,1fr))', gap: 12 }}>
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
                      {l.initials || l.name.slice(0, 2)}
                    </div>
                    <div style={{ overflow: 'hidden' }}>
                      <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--ink)', whiteSpace: 'nowrap', textOverflow: 'ellipsis', overflow: 'hidden' }}>{l.name}</div>
                      <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 1 }}>{l.department}</div>
                    </div>
                  </div>

                  {/* إحصاءات الحمل التفصيلية للأقسام الأربعة */}
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 160px), 1fr))', gap: '4px 8px', fontSize: 11.5, color: 'var(--muted)', marginBottom: 8 }}>
                    <span>تذاكر: <strong style={{ color: 'var(--ink)' }}>{l.activeTicketsCount}</strong></span>
                    <span>قضايا: <strong style={{ color: 'var(--ink)' }}>{l.activeCasesCount}</strong></span>
                    <span>تنفيذ: <strong style={{ color: 'var(--ink)' }}>{l.activeExecutionsCount ?? 0}</strong></span>
                    <span>استشارة: <strong style={{ color: 'var(--ink)' }}>{l.activeConsultsCount ?? 0}</strong></span>
                  </div>

                  {/* شريط حمل العمل الكلي */}
                  <div style={{ background: 'var(--line)', borderRadius: 4, height: 4, marginBottom: 8, overflow: 'hidden' }}>
                    <div
                      style={{
                        height: '100%',
                        borderRadius: 4,
                        width: `${Math.min((l.totalLoad / 20) * 100, 100)}%`,
                        background:
                          l.capacityStatus === 'available'
                            ? 'var(--success)'
                            : l.capacityStatus === 'moderate'
                            ? 'var(--amber)'
                            : 'var(--red)',
                        transition: 'width .4s',
                      }}
                    />
                  </div>

                  {/* حالة السعة + وضع التوزيع */}
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <span className={`badge-s ${cap.tone}`} style={{ fontSize: 11, padding: '3px 9px' }}>
                      <span className="d" /> {cap.text} ({l.totalLoad} وحدة عبء)
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

      {/* ── 4. شريط التبويبات الفئوية والبحث والفلترة ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          {/* تبويبات فئات الأعمال الأساسية (تذاكر · قضايا · تنفيذ · استشارات) */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, borderBottom: '1px solid var(--line-soft)', paddingBottom: 12, marginBottom: 14, overflowX: 'auto' }}>
            <button
              type="button"
              className={`chip sel-toggle${kindFilter === 'all' ? ' on' : ' muted'}`}
              onClick={() => setKindFilter('all')}
              style={{ fontSize: 13, padding: '7px 14px' }}
            >
              <Icon name="folder" /> كل الأعمال ({grandTotal})
            </button>
            <button
              type="button"
              className={`chip sel-toggle${kindFilter === 'ticket' ? ' on' : ' muted'}`}
              onClick={() => setKindFilter('ticket')}
              style={{ fontSize: 13, padding: '7px 14px' }}
            >
              <Icon name="reply" /> التذاكر والطلبات ({tickets.length})
              {unassignedTicketsCount > 0 && <span className="badge-s b-amber" style={{ marginRight: 6, fontSize: 10 }}>{unassignedTicketsCount} معلق</span>}
            </button>
            <button
              type="button"
              className={`chip sel-toggle${kindFilter === 'case' ? ' on' : ' muted'}`}
              onClick={() => setKindFilter('case')}
              style={{ fontSize: 13, padding: '7px 14px' }}
            >
              <Icon name="scale" /> القضايا القضائية ({cases.length})
              {unassignedCasesCount > 0 && <span className="badge-s b-amber" style={{ marginRight: 6, fontSize: 10 }}>{unassignedCasesCount} معلق</span>}
            </button>
            <button
              type="button"
              className={`chip sel-toggle${kindFilter === 'execution' ? ' on' : ' muted'}`}
              onClick={() => setKindFilter('execution')}
              style={{ fontSize: 13, padding: '7px 14px' }}
            >
              <Icon name="exec" /> ملفات التنفيذ ({executions.length})
              {unassignedExecsCount > 0 && <span className="badge-s b-purple" style={{ marginRight: 6, fontSize: 10 }}>{unassignedExecsCount} معلق</span>}
            </button>
            <button
              type="button"
              className={`chip sel-toggle${kindFilter === 'consult' ? ' on' : ' muted'}`}
              onClick={() => setKindFilter('consult')}
              style={{ fontSize: 13, padding: '7px 14px' }}
            >
              <Icon name="video" /> جلسات الاستشارة ({consults.length})
              {unassignedConsultsCount > 0 && <span className="badge-s b-teal" style={{ marginRight: 6, fontSize: 10 }}>{unassignedConsultsCount} معلق</span>}
            </button>
          </div>

          {/* بحث */}
          <div className="search" style={{ width: '100%', marginBottom: 14 }}>
            <Icon name="search" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="ابحث برقم المعاملة، الموضوع، اسم العميل، القسم، المحكمة، أو المرجع…"
            />
            {search && (
              <button type="button" style={{ color: 'var(--faint)', fontWeight: 700 }} onClick={() => setSearch('')}>✕</button>
            )}
          </div>

          {/* تبويبات حالة الإسناد + الترتيب */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <div className="tabs" style={{ margin: 0 }}>
              <button className={`tab${statusTab === 'unassigned' ? ' on' : ''}`} type="button" onClick={() => setStatusTab('unassigned')}>
                غير مسندة ({currentKindUnassigned})
              </button>
              <button className={`tab${statusTab === 'assigned' ? ' on' : ''}`} type="button" onClick={() => setStatusTab('assigned')}>
                المسندة للمستشارين ({itemsByKind.length - currentKindUnassigned})
              </button>
              <button className={`tab${statusTab === 'all' ? ' on' : ''}`} type="button" onClick={() => setStatusTab('all')}>
                الكل في هذا القسم ({itemsByKind.length})
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
                جميع الأقسام ({allItems.length})
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

      {/* ── 5. شريط الإجراءات الجماعية ── */}
      {selectedKeys.length > 0 && (
        <div
          style={{
            position: 'sticky',
            top: 12,
            zIndex: 40,
            background: 'var(--deep)',
            color: '#fff',
            borderRadius: 'var(--r-sm)',
            padding: '12px 18px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
            marginBottom: 12,
            boxShadow: 'var(--shadow-lg)',
            flexWrap: 'wrap',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span
              style={{
                background: 'var(--primary)',
                borderRadius: 8,
                padding: '2px 10px',
                fontWeight: 800,
                fontSize: 15,
              }}
            >
              {selectedKeys.length}
            </span>
            <span style={{ fontWeight: 600, fontSize: 13 }}>معاملة محددة — الإسناد الجماعي المباشر</span>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <select
              value={bulkLawyer}
              onChange={(e) => setBulkLawyer(Number(e.target.value))}
              style={{
                background: 'rgba(255,255,255,.12)',
                color: '#fff',
                border: '1px solid rgba(255,255,255,.25)',
                borderRadius: 10,
                padding: '7px 32px 7px 12px',
                fontSize: 12,
              }}
            >
              {lawyers.map((l) => (
                <option key={l.id} value={l.id} style={{ background: 'var(--deep)' }}>
                  {l.name} · عبء: {l.totalLoad} وحدة
                </option>
              ))}
            </select>
            <button
              type="button"
              className="hero-b"
              disabled={bulkBusy}
              onClick={bulkAssign}
              style={{ padding: '8px 16px', fontSize: 13 }}
            >
              <Icon name="reply" /> {bulkBusy ? 'جارٍ الإسناد…' : 'إسناد للمختار ⚡'}
            </button>
            <button
              type="button"
              className="hero-b ghost"
              onClick={() => setSelectedKeys([])}
              style={{ padding: '8px 14px', fontSize: 12 }}
            >
              إلغاء التحديد
            </button>
          </div>
        </div>
      )}

      {/* ── 6. جدول الأعمال الشامل ── */}
      <div className="card">
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <input
              type="checkbox"
              checked={filtered.length > 0 && selectedKeys.length === filtered.length}
              onChange={toggleAll}
              style={{ width: 16, height: 16, cursor: 'pointer' }}
            />
            <h3>قائمة الأعمال القانونية ({filtered.length})</h3>
          </div>
          <span className="sub" style={{ color: currentKindUnassigned > 0 ? 'var(--amber)' : 'var(--faint)' }}>
            {currentKindUnassigned > 0 ? `• ${currentKindUnassigned} عمل بانتظار تعيين مستشار` : 'جميع الأعمال المعروضة مسندة'}
          </span>
        </div>
        <div className="card-b t-wrap" style={{ padding: 0 }}>
          {filtered.length > 0 ? (
            <table className="tbl">
              <thead>
                <tr>
                  <th style={{ width: 40 }}></th>
                  <th>النوع والرقم</th>
                  <th>الموضوع والعميل</th>
                  <th>القسم / المحكمة</th>
                  <th>الأولوية / القيمة</th>
                  <th>المستشار الحالي</th>
                  <th>الإسناد إلى</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((item) => {
                  const key = getItemKey(item);
                  const isUnassigned = !item.lawyerId || item.lawyer === '—';
                  const isAssigning = assigningKey === key;
                  const isChecked = selectedKeys.includes(key);
                  const chosenLawyerId = selLawyer[key] ?? item.suggestedLawyerId ?? item.lawyerId ?? lawyers[0]?.id ?? 0;

                  return (
                    <tr
                      key={key}
                      className="click"
                      style={{ background: isChecked ? 'rgba(14,92,156,.03)' : undefined }}
                    >
                      {/* checkbox */}
                      <td style={{ paddingRight: 14 }}>
                        <input
                          type="checkbox"
                          checked={isChecked}
                          onChange={() => toggleSelect(key)}
                          onClick={(e) => e.stopPropagation()}
                          style={{ width: 15, height: 15, cursor: 'pointer' }}
                        />
                      </td>

                      {/* النوع + رقم المعاملة */}
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 3 }}>
                          <span className={`badge-s ${item.badgeTone}`} style={{ fontSize: 10, padding: '2px 6px' }}>
                            {item.itemKindLabel}
                          </span>
                        </div>
                        <span className="mono" style={{ fontWeight: 700 }}>{item.no}</span>
                        {item.caseRef && item.caseRef !== item.no && (
                          <div style={{ fontSize: 11, color: 'var(--primary)', marginTop: 2 }}>
                            📁 مرجع: {item.caseRef}
                          </div>
                        )}
                      </td>

                      {/* الموضوع + العميل */}
                      <td>
                        <div
                          style={{ fontWeight: 600, fontSize: 13.5, color: 'var(--ink)', cursor: 'pointer' }}
                          onClick={() => setPreview(item)}
                        >
                          {item.subject}
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--faint)', marginTop: 3 }}>
                          <span className="avatar" style={{ width: 18, height: 18, fontSize: 9 }}>
                            {item.userAvatar}
                          </span>
                          <span>{item.client}</span>
                          <span>·</span>
                          <span>{item.date}</span>
                        </div>
                      </td>

                      {/* القسم / المحكمة */}
                      <td>
                        <div style={{ fontWeight: 600, fontSize: 13 }}>{item.dept}</div>
                        {item.courtName && item.courtName !== item.dept && (
                          <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 2 }}>
                            🏛️ {item.courtName}
                          </div>
                        )}
                      </td>

                      {/* الأولوية / القيمة المالية */}
                      <td>
                        {item.priority && <Badge text={item.priority} tone={priorityTone(item.priority)} />}
                        {item.claimAmount && (
                          <div style={{ fontSize: 11, color: 'var(--primary)', fontWeight: 700, marginTop: 4 }}>
                            {item.claimAmount}
                          </div>
                        )}
                      </td>

                      {/* المستشار الحالي */}
                      <td>
                        {isUnassigned ? (
                          <span style={{ color: 'var(--faint)', fontSize: 13 }}>— غير مسند —</span>
                        ) : (
                          <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)' }}>{item.lawyer}</span>
                        )}
                      </td>

                      {/* الإسناد السريع */}
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          {/* مقترح التعيين إن وُجد */}
                          {isUnassigned && item.suggestedLawyerId && item.suggestedLawyerName && (
                            <button
                              type="button"
                              className="btn soft sm"
                              disabled={isAssigning}
                              onClick={() => assign(item, item.suggestedLawyerId!)}
                              style={{
                                fontSize: 11.5,
                                padding: '5px 9px',
                                // المقترح غير المختصّ لا يُلوَّن كالاختيار الطبيعيّ
                                color: item.suggestion?.specialist === false ? 'var(--amber, #d97706)' : 'var(--success)',
                                borderColor: item.suggestion?.specialist === false ? 'var(--amber, #d97706)' : 'var(--success)',
                                whiteSpace: 'nowrap',
                              }}
                              title={item.suggestion?.label ?? 'إسناد المقترح فوراً'}
                            >
                              ⚡ {item.suggestedLawyerName}
                            </button>
                          )}
                          {isUnassigned && <LawyerSuggestionHint suggestion={item.suggestion} compact />}
                          <select
                            value={chosenLawyerId}
                            onChange={(e) => {
                              const val = Number(e.target.value);
                              setSelLawyer((p) => ({ ...p, [key]: val }));
                            }}
                            style={{ padding: '6px 28px 6px 10px', fontSize: 12, minWidth: 140 }}
                            onClick={(e) => e.stopPropagation()}
                          >
                            {lawyers.map((l) => (
                              <option key={l.id} value={l.id}>
                                {l.name} ({l.totalLoad} عبء)
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
                            onClick={() => assign(item)}
                          >
                            <Icon name="reply" />
                            {isAssigning ? '…' : isUnassigned ? 'إسناد' : 'تغيير'}
                          </button>
                          <button
                            type="button"
                            className="btn soft sm"
                            onClick={() => setPreview(item)}
                            title="معاينة التفاصيل الكاملة"
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
              <b>لا توجد أعمال مطابقة للفلاتر المحددة</b>
              <button
                type="button"
                className="btn ghost sm"
                style={{ margin: '12px auto 0' }}
                onClick={() => {
                  setSearch('');
                  setDeptFilter('all');
                  setStatusTab('all');
                  setKindFilter('all');
                  setLawyerFilter(null);
                }}
              >
                إعادة ضبط الفلاتر
              </button>
            </div>
          )}
        </div>
      </div>

      {/* ── 7. نافذة معاينة العمل القانوني وإسناده ── */}
      {preview && (
        <Modal
          title={`${preview.itemKindLabel}: ${preview.no}`}
          open={Boolean(preview)}
          onClose={() => setPreview(null)}
        >
          <div>
            {/* بطاقات البيانات الأساسية */}
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 110px), 1fr))',
                gap: 10,
                background: 'var(--paper-2)',
                borderRadius: 'var(--r-sm)',
                padding: '12px 14px',
                marginBottom: 14,
              }}
            >
              <div>
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>نوع العمل</div>
                <div style={{ marginTop: 3 }}>
                  <span className={`badge-s ${preview.badgeTone}`}>{preview.itemKindLabel}</span>
                </div>
              </div>
              {preview.priority && (
                <div>
                  <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>الأولوية</div>
                  <div style={{ marginTop: 3 }}>
                    <Badge text={preview.priority} tone={priorityTone(preview.priority)} />
                  </div>
                </div>
              )}
              <div>
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>الحالة الحالية</div>
                <div style={{ fontSize: 13, fontWeight: 700, marginTop: 3 }}>{preview.status}</div>
              </div>
            </div>

            {/* تفاصيل الموضوع والأطراف */}
            <div className="kv">
              <span className="k">الموضوع</span>
              <span className="v" style={{ maxWidth: '70%', textAlign: 'end', fontWeight: 600 }}>{preview.subject}</span>
            </div>
            <div className="kv">
              <span className="k">العميل</span>
              <span className="v">{preview.client}</span>
            </div>
            <div className="kv">
              <span className="k">القسم / التخصص</span>
              <span className="v">{preview.dept}</span>
            </div>
            {preview.courtName && (
              <div className="kv">
                <span className="k">المحكمة / الجهة</span>
                <span className="v">{preview.courtName}</span>
              </div>
            )}
            {preview.caseRef && (
              <div className="kv">
                <span className="k">الرقم المرجعي</span>
                <span className="v">{preview.caseRef}</span>
              </div>
            )}
            {preview.claimAmount && (
              <div className="kv">
                <span className="k">المطالبة / الأتعاب</span>
                <span className="v" style={{ color: 'var(--primary)', fontWeight: 700 }}>{preview.claimAmount}</span>
              </div>
            )}
            <div className="kv">
              <span className="k">تاريخ الورود</span>
              <span className="v">{preview.createdAt || preview.date}</span>
            </div>
            <div className="kv">
              <span className="k">المستشار الحالي</span>
              <span className="v" style={{ fontWeight: 700 }}>{preview.lawyer}</span>
            </div>

            {/* إسناد مباشر من داخل النافذة */}
            <div style={{ marginTop: 16, borderTop: '1px solid var(--line-soft)', paddingTop: 14 }}>
              <div className="field" style={{ marginBottom: 12 }}>
                <label style={{ fontWeight: 700, fontSize: 12.5, marginBottom: 6, display: 'block' }}>إسناد إلى مستشار قانوني:</label>
                <select
                  value={selLawyer[getItemKey(preview)] ?? preview.lawyerId ?? lawyers[0]?.id ?? 0}
                  onChange={(e) => {
                    const val = Number(e.target.value);
                    setSelLawyer((p) => ({ ...p, [getItemKey(preview)]: val }));
                  }}
                  style={{ width: '100%', padding: '9px 12px' }}
                >
                  {lawyers.map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name} — {l.department} (إجمالي العبء: {l.totalLoad} وحدة)
                    </option>
                  ))}
                </select>
              </div>
              <button
                type="button"
                className="btn block"
                onClick={() => {
                  assign(preview);
                  setPreview(null);
                }}
              >
                <Icon name="reply" /> تأكيد الإسناد المباشر
              </button>
            </div>
          </div>
        </Modal>
      )}
    </>
  );
};

export default AdminDistribute;
