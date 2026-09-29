import { Link, router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import CaseClosureModal from '@/components/babylon/CaseClosureModal';
import type { ClosureReasonOption } from '@/components/babylon/CaseClosureModal';
import Modal from '@/components/babylon/Modal';
import { CONFIRM_ARCHIVE_CASE } from '@/lib/case-ui';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import { useServerAction } from '@/lib/use-server-action';
import { truncateWords } from '@/lib/utils';

/* ─────────────────────────────────────────────────────────────
   منظومة إشراف ومتابعة كل القضايا — الإدارة العليا
   تستخدم نظام CSS المخصص لمنصة سلاسل بابل حصراً
───────────────────────────────────────────────────────────── */

export interface CaseRow {
  id: number;
  no: string;
  client: string;
  realClientName?: string;
  type: string;
  dept?: string;
  lawyer: string;
  lawyerId?: number | null;
  status: string;
  tone: string;
  courtName?: string;
  claimAmount?: string | null;
  opponent?: string | null;
  fee?: number | null;
  feeStatus?: string | null;
  updateText?: string | null;
  ruling?: string | null;
  hearingsCount?: number;
  nextHearingDate?: string | null;
  nextHearingNotes?: string | null;
  date?: string;
  canClose: boolean;
  canArchive: boolean;
  canExecute: boolean;
}

export interface TypeFilter {
  name: string;
  count: number;
}

export interface CaseKPIs {
  total: number;
  active: number;
  judged: number;
  closed: number;
  pendingFee: number;
}

interface Props {
  cases: CaseRow[];
  types?: TypeFilter[];
  kpis?: CaseKPIs;
  /** مجموعات الحالات من `CaseJourney::adminTabs` — لا قوائم باليد في الشاشة */
  tabs?: { pendingFee: string[]; active: string[]; judged: string[]; closed: string[] };
  /** أسباب الإغلاق من الكتالوج (`ClosureCaseReasonCode::options`) — النافذة نفسها في صفحة التفاصيل */
  closureReasons?: ClosureReasonOption[];
}

const NO_TABS = { pendingFee: [] as string[], active: [] as string[], judged: [] as string[], closed: [] as string[] };

export const AdminCases: React.FC<Props> = ({ cases = [], types = [], kpis, tabs = NO_TABS, closureReasons = [] }) => {

  // State
  const [search, setSearch] = useState('');
  const [statusTab, setStatusTab] = useState<'all' | 'active' | 'judged' | 'closed' | 'pendingFee'>('all');
  const [selectedType, setSelectedType] = useState<string>('all');
  const [sortBy, setSortBy] = useState<'newest' | 'oldest' | 'hearings'>('newest');
  const [previewCase, setPreviewCase] = useState<CaseRow | null>(null);
  // القضية المفتوحة نافذةُ إغلاقها — الإغلاق من القائمة يمرّ بالسبب كصفحة التفاصيل (كان يرسل `{}`)
  const [closingNo, setClosingNo] = useState<string | null>(null);

  // Actions
  // قفلٌ موحّد (`useServerAction`): الإغلاق والأرشفة والتحويل للتنفيذ لا تُرسل مرّتين، و`busyNo` مفتاح
  // القضيّة الجارية. ورسالة الرفض من الخادم (حارس الانتقال ٤٢٢ · تحقّق الحقول) عبر `firstError`.
  const action = useServerAction();
  const busyNo = action.busyKey as string | null;
  const clearPreview = (no: string) => {
    if (previewCase?.no === no) {
      setPreviewCase(null);
    }
  };

  const close = (no: string, reason: string, notes: string) => {
    setClosingNo(null);
    void action.run(`/admin/cases/${encodeURIComponent(no)}/close`, {
      data: { closure_reason: reason, closure_notes: notes },
      key: no,
      success: 'تم إغلاق القضية بنجاح ⚖️',
      fallback: 'تعذّر إغلاق القضية',
      onSuccess: () => clearPreview(no),
    });
  };

  const archive = (no: string) =>
    action.run(`/admin/cases/${encodeURIComponent(no)}/archive`, {
      key: no,
      confirm: CONFIRM_ARCHIVE_CASE,
      success: 'تمت أرشفة ملف القضية في السجلات القانونية 📁',
      fallback: 'تعذّرت الأرشفة',
      onSuccess: () => clearPreview(no),
    });

  const execute = (no: string) =>
    action.run(`/admin/cases/${encodeURIComponent(no)}/execute`, {
      key: no,
      success: 'تم فتح طلب تنفيذ رسمي للقضية ⚡',
      fallback: 'تعذّر تحويل القضية للتنفيذ',
      onSuccess: () => clearPreview(no),
    });

  // KPIs
  const totalCases = cases.length;
  const activeCases = useMemo(
    () => cases.filter((c) => tabs.active.includes(c.status)).length,
    [cases, tabs]
  );
  const judgedCases = useMemo(() => cases.filter((c) => tabs.judged.includes(c.status)).length, [cases, tabs]);
  const closedCases = useMemo(() => cases.filter((c) => tabs.closed.includes(c.status)).length, [cases, tabs]);

  // Filtered Cases
  const filtered = useMemo(() => {
    const q = foldSearch(search);

    return cases
      .filter((c) => {
        if (q) {
          const hit =
            foldSearch(c.no).includes(q) ||
            foldSearch(c.client).includes(q) ||
            (c.realClientName || '').toLowerCase().includes(q) ||
            foldSearch(c.type).includes(q) ||
            foldSearch(c.lawyer).includes(q) ||
            (c.courtName || '').toLowerCase().includes(q) ||
            (c.opponent || '').toLowerCase().includes(q);

          if (!hit) {
return false;
}
        }

        // Status tab
        if (statusTab === 'active' && !tabs.active.includes(c.status)) {
          return false;
        }

        if (statusTab === 'judged' && !tabs.judged.includes(c.status)) {
          return false;
        }

        if (statusTab === 'closed' && !tabs.closed.includes(c.status)) {
          return false;
        }

        if (statusTab === 'pendingFee' && !tabs.pendingFee.includes(c.status)) {
          return false;
        }

        // Type filter
        if (selectedType !== 'all' && c.type !== selectedType) {
          return false;
        }

        return true;
      })
      .sort((a, b) => {
        if (sortBy === 'hearings') {
          return (b.hearingsCount || 0) - (a.hearingsCount || 0);
        }

        if (sortBy === 'oldest') {
          return (a.id || 0) - (b.id || 0);
        }

        return (b.id || 0) - (a.id || 0);
      });
  }, [tabs, cases, search, statusTab, selectedType, sortBy]);

  return (
    <>
      {/* ── 1. بانر رأس الصفحة التنفيذي ── */}
      <div className="hero" style={{ marginBottom: 20 }}>
        <div className="hero-cta" style={{ marginTop: 0, marginBottom: 12 }}>
          <span style={{ fontSize: 11, fontWeight: 700, opacity: 0.75, letterSpacing: '.5px', textTransform: 'uppercase' }}>
            ● المنظومة القضائية الموحدة · الإدارة العليا
          </span>
        </div>
        <h2 style={{ fontSize: 26, marginBottom: 6 }}>سجل وإدارة كل القضايا</h2>
        <p>إشراف مركزي شامل 360° على كافة القضايا والمرافعات، متابعة الجلسات، واعتماد الإغلاق والتنفيذ والأرشفة.</p>
        <div className="hero-cta">
          <button
            className="hero-b"
            type="button"
            onClick={() => router.visit('/admin/casefees')}
          >
            <Icon name="card" /> إدارة أتعاب القضايا
          </button>
          <button
            className="hero-b ghost"
            type="button"
            // `reload` لا `visit(pathname)`: `reload` تحفظ الحالة افتراضاً، والثانية زيارةٌ جديدة
            // فيُعاد تركيب المكوّن ويضيع البحث والتبويب والفرز، وتُحذف معها معطيات الرابط.
            onClick={() => router.reload()}
          >
            <Icon name="cal" /> تحديث السجل
          </button>
        </div>
      </div>

      {/* ── 2. مؤشرات الأداء الحية للقضايا (KPIs) ── */}
      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 200px), 1fr))', marginBottom: 20 }}>
        <div
          className={`stat t-blue${statusTab === 'all' ? ' sel' : ''}`}
          style={{ cursor: 'pointer', outline: statusTab === 'all' ? '2px solid var(--primary)' : 'none' }}
          onClick={() => setStatusTab('all')}
        >
          <div className="si"><Icon name="folder" /></div>
          <div className="num">{totalCases}</div>
          <div className="lbl">إجمالي القضايا بالمكتب</div>
        </div>

        <div
          className={`stat t-cyan${statusTab === 'active' ? ' sel' : ''}`}
          style={{ cursor: 'pointer', outline: statusTab === 'active' ? '2px solid var(--cyan)' : 'none' }}
          onClick={() => setStatusTab('active')}
        >
          <div className="si"><Icon name="scale" /></div>
          <div className="num">{activeCases}</div>
          <div className="lbl">قضايا قيد العمل (تحضير ونظر)</div>
        </div>

        <div
          className={`stat t-amber${statusTab === 'judged' ? ' sel' : ''}`}
          style={{ cursor: 'pointer', outline: statusTab === 'judged' ? '2px solid var(--amber)' : 'none' }}
          onClick={() => setStatusTab('judged')}
        >
          <div className="si"><Icon name="exec" /></div>
          <div className="num">{judgedCases}</div>
          <div className="lbl">أحكام صادرة (جاهزة للإجراء)</div>
        </div>

        <div
          className={`stat t-green${statusTab === 'closed' ? ' sel' : ''}`}
          style={{ cursor: 'pointer', outline: statusTab === 'closed' ? '2px solid var(--success)' : 'none' }}
          onClick={() => setStatusTab('closed')}
        >
          <div className="si"><Icon name="check" /></div>
          <div className="num">{closedCases}</div>
          <div className="lbl">قضايا مغلقة ومؤرشفة</div>
        </div>
      </div>

      {/* ── 3. شريط الفلترة والبحث المتقدم ── */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          {/* حقل البحث */}
          <div className="search" style={{ width: '100%', marginBottom: 14 }}>
            <Icon name="search" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="ابحث برقم القضية، اسم العميل، المحامي المسند، المحكمة، أو الخصم…"
            />
            {search && (
              <button type="button" style={{ color: 'var(--faint)', fontWeight: 700 }} onClick={() => setSearch('')}>
                ✕
              </button>
            )}
          </div>

          {/* تبويبات المراحل القضائية */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <div className="tabs" style={{ margin: 0 }}>
              <button
                className={`tab${statusTab === 'all' ? ' on' : ''}`}
                type="button"
                onClick={() => setStatusTab('all')}
              >
                الكل ({cases.length})
              </button>
              <button
                className={`tab${statusTab === 'active' ? ' on' : ''}`}
                type="button"
                onClick={() => setStatusTab('active')}
              >
                قيد العمل ({activeCases})
              </button>
              <button
                className={`tab${statusTab === 'judged' ? ' on' : ''}`}
                type="button"
                onClick={() => setStatusTab('judged')}
              >
                صدر الحكم ({judgedCases})
              </button>
              <button
                className={`tab${statusTab === 'closed' ? ' on' : ''}`}
                type="button"
                onClick={() => setStatusTab('closed')}
              >
                مغلقة ومؤرشفة ({closedCases})
              </button>
              <button
                className={`tab${statusTab === 'pendingFee' ? ' on' : ''}`}
                type="button"
                onClick={() => setStatusTab('pendingFee')}
              >
                بانتظار الأتعاب ({kpis?.pendingFee ?? cases.filter((c) => tabs.pendingFee.includes(c.status)).length})
              </button>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>ترتيب:</span>
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as any)}
                style={{ width: 'auto', padding: '7px 32px 7px 12px', fontSize: 13 }}
              >
                <option value="newest">الأحدث تسجيلاً</option>
                <option value="oldest">الأقدم تسجيلاً</option>
                <option value="hearings">الأكثر جلسات</option>
              </select>
            </div>
          </div>

          {/* تصفية أنواع القضايا */}
          {types.length > 0 && (
            <div className="chips" style={{ marginTop: 12 }}>
              <button
                type="button"
                className={`chip${selectedType === 'all' ? '' : ' muted'} sel-toggle${selectedType === 'all' ? ' on' : ''}`}
                onClick={() => setSelectedType('all')}
              >
                جميع الاختصاصات ({cases.length})
              </button>
              {types.map((t) => (
                <button
                  key={t.name}
                  type="button"
                  className={`chip sel-toggle${selectedType === t.name ? ' on' : ' muted'}`}
                  onClick={() => setSelectedType(selectedType === t.name ? 'all' : t.name)}
                >
                  {t.name} ({t.count})
                </button>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* ── 4. جدول القضايا الرئيسي ── */}
      <div className="card">
        <div className="card-h">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Icon name="scale" />
            <h3>جدول القضايا القضائية ({filtered.length})</h3>
          </div>
          <span className="sub">
            {filtered.length === cases.length ? `إجمالي ${cases.length} قضية` : `عرض ${filtered.length} من أصل ${cases.length}`}
          </span>
        </div>

        <div className="card-b t-wrap" style={{ padding: 0 }}>
          {filtered.length ? (
            <table className="tbl" style={{ minWidth: 780 }}>
              <thead>
                <tr>
                  <th style={{ width: 130 }}>رقم القضية</th>
                  <th style={{ minWidth: 160, maxWidth: 240 }}>العميل / الخصم</th>
                  <th style={{ minWidth: 160, maxWidth: 260 }}>النوع والمحكمة</th>
                  <th>المحامي المسند</th>
                  <th>الحالة</th>
                  <th>الجلسات</th>
                  <th style={{ textAlign: 'center', width: 130 }}>الإجراءات الإدارية</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((c) => {
                  const isBusy = busyNo === c.no;

                  return (
                    <tr key={c.no} className="click">
                      {/* رقم القضية */}
                      <td className="nowrap">
                        <span
                          className="mono"
                          style={{ cursor: 'pointer', color: 'var(--primary)', fontWeight: 800 }}
                          onClick={() => setPreviewCase(c)}
                        >
                          {c.no}
                        </span>
                        {c.date && <div style={{ fontSize: 11, color: 'var(--faint)', marginTop: 2 }}>{c.date}</div>}
                      </td>

                      {/* العميل والخصم */}
                      <td style={{ minWidth: 160, maxWidth: 240 }}>
                        <div style={{ fontWeight: 700, fontSize: 13.5, color: 'var(--ink)' }} title={c.client}>
                          {truncateWords(c.client, 4)}
                        </div>
                        {c.opponent ? (
                          <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 2 }} title={c.opponent}>
                            ضد: {truncateWords(c.opponent, 4)}
                          </div>
                        ) : (
                          <div style={{ fontSize: 11.5, color: 'var(--faint)', marginTop: 2 }}>دعوى قضائية</div>
                        )}
                      </td>

                      {/* النوع والمحكمة */}
                      <td style={{ minWidth: 160, maxWidth: 260 }}>
                        <div style={{ fontWeight: 600, fontSize: 13 }}>{c.type}</div>
                        <div style={{ fontSize: 11.5, color: 'var(--faint)', marginTop: 2 }} title={c.courtName || 'المحكمة المختصة'}>
                          {truncateWords(c.courtName || 'المحكمة المختصة', 6)}
                        </div>
                      </td>

                      {/* المحامي المسند */}
                      <td className="nowrap">
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <div className="avatar" style={{ width: 28, height: 28, fontSize: 11, flex: '0 0 28px' }}>
                            {c.lawyer.slice(0, 2)}
                          </div>
                          <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink)' }} title={c.lawyer}>
                            {truncateWords(c.lawyer, 4)}
                          </span>
                        </div>
                      </td>

                      {/* الحالة */}
                      <td className="nowrap">
                        <Badge text={c.status} tone={c.tone} />
                        {c.ruling && (
                          <div style={{ fontSize: 11, color: 'var(--success)', fontWeight: 700, marginTop: 4 }}>
                            ⚖️ حُكم مكتمل
                          </div>
                        )}
                      </td>

                      {/* الجلسات القادمة */}
                      <td>
                        <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--ink)' }}>
                          {c.hearingsCount || 0} جلسة
                        </div>
                        {c.nextHearingDate && (
                          <div style={{ fontSize: 11, color: 'var(--cyan)', marginTop: 2 }}>
                            قادمة: {c.nextHearingDate}
                          </div>
                        )}
                      </td>

                      {/* الإجراءات */}
                      <td>
                        <div style={{ display: 'flex', gap: 6, justifyContent: 'center', flexWrap: 'wrap' }}>
                          {c.canClose && (
                            <button
                              className="btn sm"
                              type="button"
                              disabled={isBusy}
                              onClick={() => setClosingNo(c.no)}
                              title="إغلاق القضية بعد اكتمال الحكم"
                            >
                              <Icon name="check" /> {isBusy ? '…' : 'إغلاق'}
                            </button>
                          )}
                          {c.canExecute && (
                            <button
                              className="btn sm soft"
                              type="button"
                              disabled={isBusy}
                              onClick={() => execute(c.no)}
                              title="فتح طلب تنفيذ قضائي للحكم الصادر"
                            >
                              <Icon name="exec" /> {isBusy ? '…' : 'تحويل لتنفيذ'}
                            </button>
                          )}
                          {c.canArchive && (
                            <button
                              className="btn sm soft"
                              type="button"
                              disabled={isBusy}
                              onClick={() => archive(c.no)}
                              title="أرشفة القضية نهائياً"
                            >
                              <Icon name="folder" /> {isBusy ? '…' : 'أرشفة'}
                            </button>
                          )}
                          <Link
                            className="btn sm ghost"
                            href={`/admin/cases/${encodeURIComponent(c.no)}`}
                            title="فتح ملف القضية كاملاً: المحادثة والجلسات والمستندات"
                          >
                            <Icon name="folder" /> الملف
                          </Link>
                          <button
                            className="btn sm ghost"
                            type="button"
                            onClick={() => setPreviewCase(c)}
                            title="معاينة تفاصيل القضية"
                          >
                            <Icon name="search" /> معاينة
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
              <Icon name="scale" />
              <b>{cases.length === 0 ? 'لا توجد قضايا بعد' : 'لا توجد قضايا مطابقة لخيارات الفلترة المحددة'}</b>
              <button
                type="button"
                className="btn ghost sm"
                style={{ margin: '12px auto 0' }}
                onClick={() => {
                  setSearch('');
                  setStatusTab('all');
                  setSelectedType('all');
                }}
              >
                إعادة ضبط الفلاتر
              </button>
            </div>
          )}
        </div>
      </div>

      {/* ── 5. نافذة معاينة تفاصيل القضية (Case Detail Modal) ── */}
      {previewCase && (
        <Modal
          title={`ملف القضية: ${previewCase.no}`}
          open={Boolean(previewCase)}
          onClose={() => setPreviewCase(null)}
        >
          <div>
            {/* بطاقة الرأس */}
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
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>نوع القضية</div>
                <div style={{ fontSize: 13, fontWeight: 700, marginTop: 2 }}>{previewCase.type}</div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>حالة القضية</div>
                <div style={{ marginTop: 3 }}>
                  <Badge text={previewCase.status} tone={previewCase.tone} />
                </div>
              </div>
              <div>
                <div style={{ fontSize: 11, color: 'var(--faint)', fontWeight: 700 }}>المحامي المسؤول</div>
                <div style={{ fontSize: 13, fontWeight: 700, marginTop: 2 }}>{previewCase.lawyer}</div>
              </div>
            </div>

            {/* تفاصيل القضية */}
            <div className="kv">
              <span className="k">رقم القضية</span>
              <span className="v mono">{previewCase.no}</span>
            </div>
            <div className="kv">
              <span className="k">الموكل / العميل</span>
              <span className="v">{previewCase.client}</span>
            </div>
            {previewCase.opponent && (
              <div className="kv">
                <span className="k">الطرف الخصم</span>
                <span className="v">{previewCase.opponent}</span>
              </div>
            )}
            <div className="kv">
              <span className="k">المحكمة المختصة</span>
              <span className="v">{previewCase.courtName || 'المحكمة العامة'}</span>
            </div>
            {previewCase.claimAmount && (
              <div className="kv">
                <span className="k">قيمة المطالبة</span>
                <span className="v" style={{ color: 'var(--deep)' }}>{previewCase.claimAmount}</span>
              </div>
            )}
            {/* `fee && …` كان يطبع «0» لقضيّةٍ بلا أتعاب — الصفر قيمةٌ معتمدة تُعرض صراحةً */}
            {previewCase.fee != null && (
              <div className="kv">
                <span className="k">أتعاب القضية المعتمدة</span>
                <span className="v" style={{ color: 'var(--primary)' }}>{previewCase.fee === 0 ? 'بلا أتعاب' : `${previewCase.fee.toLocaleString('en-US')} ر.س`}</span>
              </div>
            )}
            <div className="kv">
              <span className="k">عدد الجلسات المسجلة</span>
              <span className="v">{previewCase.hearingsCount || 0} جلسة</span>
            </div>
            {previewCase.nextHearingDate && (
              <div className="kv">
                <span className="k">الجلسة القادمة</span>
                <span className="v" style={{ color: 'var(--cyan)' }}>{previewCase.nextHearingDate}</span>
              </div>
            )}
            {previewCase.ruling && (
              <div style={{ marginTop: 12, padding: '10px 12px', background: 'var(--success-bg)', borderRadius: 'var(--r-sm)', border: '1px solid rgba(30,157,107,.2)' }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--success)', marginBottom: 4 }}>⚖️ منطوق الحكم الصادر:</div>
                <div style={{ fontSize: 13, color: 'var(--ink)', lineHeight: 1.6 }}>{previewCase.ruling}</div>
              </div>
            )}

            {/* أزرار الإجراءات داخل النافذة */}
            <div style={{ marginTop: 18, borderTop: '1px solid var(--line-soft)', paddingTop: 14, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {previewCase.canClose && (
                <button
                  type="button"
                  className="btn"
                  style={{ flex: 1 }}
                  disabled={busyNo === previewCase.no}
                  onClick={() => setClosingNo(previewCase.no)}
                >
                  <Icon name="check" /> إغلاق القضية رسمياً
                </button>
              )}
              {previewCase.canExecute && (
                <button
                  type="button"
                  className="btn soft"
                  style={{ flex: 1 }}
                  disabled={busyNo === previewCase.no}
                  onClick={() => execute(previewCase.no)}
                >
                  <Icon name="exec" /> فتح ملف تنفيذ للحكم
                </button>
              )}
              {previewCase.canArchive && (
                <button
                  type="button"
                  className="btn soft"
                  style={{ flex: 1 }}
                  disabled={busyNo === previewCase.no}
                  onClick={() => archive(previewCase.no)}
                >
                  <Icon name="folder" /> إيداع بالأرشيف القانوني
                </button>
              )}
              <button
                type="button"
                className="btn ghost"
                onClick={() => setPreviewCase(null)}
              >
                إغلاق النافذة
              </button>
            </div>
          </div>
        </Modal>
      )}

      <CaseClosureModal
        open={closingNo !== null}
        caseNo={closingNo ?? ''}
        reasons={closureReasons}
        busy={busyNo !== null}
        onClose={() => setClosingNo(null)}
        onSubmit={(reason, notes) => closingNo && close(closingNo, reason, notes)}
      />
    </>
  );
};

export default AdminCases;
