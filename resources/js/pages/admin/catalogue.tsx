import { router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { foldSearch } from '@/lib/employee-data';
import Icon from '@/lib/icons';

// «الأقسام والخدمات» — كتالوج الأقسام القانونيّة وخدماتها، والأقسام الإداريّة للموظّفين.
// كلّ ما هنا يكتبه الخادم عبر LegalCatalogueEditor: إعادة التسمية تظهر في التذاكر والقضايا القديمة
// ويُحفظ الاسم القديم اسماً بديلاً؛ ولا حذف — الإيقاف يُخفي من الاختيار ويُبقي السجلّات.

interface ServiceRow { id: number; name: string; active: boolean }

/** بندٌ في قائمة مستندات القسم (قرار المالك 2026-09-26) — «إلزاميّ» أو «اختياريّ». */
interface DocumentRow { id: number; name: string; required: boolean }

interface DepartmentRow {
  id: number;
  name: string;
  active: boolean;
  usage: { tickets: number; cases: number; consults: number; lawyers: number };
  impact: { lawyersOnlyHere: number; openTickets: number };
  aliases: string[];
  services: ServiceRow[];
  documents: DocumentRow[];
}

interface StaffDepartmentRow { id: number; name: string; active: boolean; employees: number }

interface Props {
  departments: DepartmentRow[];
  staffDepartments: StaffDepartmentRow[];
  /** القائمة العامّة — ما يُطلب في قسمٍ بلا قائمة محرَّرة (LegalCatalogue::DEFAULT_DOCUMENTS) */
  defaultDocuments: { name: string; required: boolean }[];
}

type Errors = Record<string, string>;

const muted: React.CSSProperties = { color: 'var(--muted)', fontSize: 12.5 };
const errText: React.CSSProperties = { color: 'var(--red)', fontSize: 12, marginTop: 4 };
const rowStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, padding: '8px 0', borderTop: '1px solid var(--line)', flexWrap: 'wrap' };

/** ينقل عنصراً خطوةً للأعلى أو للأسفل ويعيد المعرّفات بالترتيب الجديد. */
const moved = (ids: number[], index: number, step: -1 | 1): number[] => {
  const target = index + step;

  if (target < 0 || target >= ids.length) {
    return ids;
  }

  const next = [...ids];
  [next[index], next[target]] = [next[target], next[index]];

  return next;
};

/** حقل اسمٍ مع زرّ حفظ — يُستعمل للإضافة وإعادة التسمية في كلّ القوائم. */
const NameForm: React.FC<{
  id: string;
  initial?: string;
  placeholder: string;
  submitLabel: string;
  busy: boolean;
  error?: string;
  onSubmit: (name: string, reset: () => void) => void;
}> = ({ id, initial = '', placeholder, submitLabel, busy, error, onSubmit }) => {
  const [value, setValue] = useState(initial);
  const unchanged = value.trim() === '' || value.trim() === initial;

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();

        if (!unchanged) {
          onSubmit(value.trim(), () => setValue(''));
        }
      }}
      style={{ display: 'grid', gap: 4 }}
    >
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <input id={id} className="input" value={value} onChange={(e) => setValue(e.target.value)} placeholder={placeholder} style={{ flex: 1, minWidth: 180 }} />
        <button className="btn sm" type="submit" disabled={busy || unchanged}><Icon name="check" /> {submitLabel}</button>
      </div>
      {error && <div style={errText}>{error}</div>}
    </form>
  );
};

const AdminCatalogue: React.FC<Props> = ({ departments, staffDepartments, defaultDocuments }) => {
  const toast = useToast();
  const ask = useConfirm();
  const [tab, setTab] = useState<'legal' | 'staff'>('legal');
  const [search, setSearch] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(departments[0]?.id ?? null);
  const [busy, setBusy] = useState(false);
  // أخطاء الخادم **منسوبةً إلى النموذج الذي أرسلها** — كانت حالةً واحدة بمفتاح `name` يقرؤها
  // حقلُ اسم القسم وحده، فخطأ «مستندٌ بهذا الاسم» يظهر تحت اسم القسم لا تحت حقل المستند.
  const [errors, setErrors] = useState<{ form: string; errs: Errors } | null>(null);
  const errorOf = (form: string, key = 'name'): string | undefined => (errors?.form === form ? errors.errs[key] : undefined);
  const [confirming, setConfirming] = useState<DepartmentRow | null>(null);
  // البند الجديد إلزاميّ افتراضاً — قاعدة المالك: الإلزام ما لم يكن مكمّلاً بوضوح
  const [newDocRequired, setNewDocRequired] = useState(true);

  const selected = departments.find((d) => d.id === selectedId) ?? null;
  const visible = useMemo(() => {
    const q = foldSearch(search);

    return q ? departments.filter((d) => foldSearch(d.name).includes(q) || d.services.some((s) => foldSearch(s.name).includes(q))) : departments;
  }, [departments, search]);

  /**
   * إرسالٌ موحّد: يشغل الشاشة، ويعرض أخطاء الخادم تحت حقول النموذج `form` الذي أرسلها.
   * رسالة النجاح من الخادم (`flash`) يعرضها التخطيط مرّةً — كان هنا توستٌ ثانٍ لكلّ حفظ.
   */
  const send = (method: 'post' | 'put' | 'delete', url: string, data: Record<string, unknown>, form: string, after?: () => void) => {
    setBusy(true);
    const options = {
      preserveScroll: true,
      onSuccess: () => {
        setErrors(null);
        after?.();
      },
      onError: (errs: Errors) => {
        setErrors({ form, errs });
        toast('⚠️ ' + (Object.values(errs)[0] ?? 'تعذّر الحفظ'), 'error');
      },
      onFinish: () => setBusy(false),
    };

    if (method === 'delete') {
      router.delete(url, options);
    } else {
      router[method](url, data as never, options);
    }
  };

  const toggleDepartment = (d: DepartmentRow, confirm = false) => {
    const hasImpact = d.impact.lawyersOnlyHere > 0 || d.impact.openTickets > 0;

    if (d.active && hasImpact && !confirm) {
      setConfirming(d);

      return;
    }

    send('post', `/admin/catalogue/departments/${d.id}/toggle`, { confirm }, 'toggle-department', () => setConfirming(null));
  };

  const reorderDepartments = (index: number, step: -1 | 1) =>
    send('post', '/admin/catalogue/departments/reorder', { order: moved(departments.map((d) => d.id), index, step) }, 'actions');

  const reorderServices = (d: DepartmentRow, index: number, step: -1 | 1) =>
    send('post', `/admin/catalogue/departments/${d.id}/services/reorder`, { order: moved(d.services.map((s) => s.id), index, step) }, 'actions');

  const reorderDocuments = (d: DepartmentRow, index: number, step: -1 | 1) =>
    send('post', `/admin/catalogue/departments/${d.id}/documents/reorder`, { order: moved(d.documents.map((x) => x.id), index, step) }, 'actions');

  // الحذف بالحوار المشترك (خطر، Escape = إلغاء) — لا `window.confirm` (قرار المالك)
  const deleteDocument = async (doc: DocumentRow) => {
    const ok = await ask({
      title: `حذف «${doc.name}» من قائمة القسم؟`,
      message: 'لن يُطلب هذا المستند بعد الآن من عملاء هذا القسم، ولا يُتراجع عن الحذف إلّا بإضافته من جديد. تبقى مطابقات المرفقات السابقة باسمه.',
      confirmLabel: 'حذف المستند',
      tone: 'danger',
    });

    if (ok) {
      send('delete', `/admin/catalogue/documents/${doc.id}`, {}, 'actions');
    }
  };

  return (
    <div style={{ paddingBottom: 60, width: '100%' }}>
      <div className="greet">
        <h1>الأقسام والخدمات</h1>
        <p>
          الأقسام القانونيّة التي يختارها العميل عند فتح التذكرة ويُسنَد بها المحامي المختصّ، وخدمات كلّ قسم،
          والأقسام الإداريّة للموظّفين. تغيير الاسم يظهر في التذاكر والقضايا القديمة، والإيقاف يُخفي من الاختيار دون حذف.
        </p>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 12 }} role="tablist">
        <button type="button" role="tab" aria-selected={tab === 'legal'} className={`btn sm${tab === 'legal' ? '' : ' soft'}`} onClick={() => setTab('legal')}>
          <Icon name="scale" /> الأقسام القانونيّة ({departments.length})
        </button>
        <button type="button" role="tab" aria-selected={tab === 'staff'} className={`btn sm${tab === 'staff' ? '' : ' soft'}`} onClick={() => setTab('staff')}>
          <Icon name="user" /> الأقسام الإداريّة ({staffDepartments.length})
        </button>
      </div>

      {tab === 'legal' ? (
        <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start', flexWrap: 'wrap' }}>
          {/* ── قائمة الأقسام ── */}
          <div className="card" style={{ flex: '1 1 300px', maxWidth: 420 }}>
            <div className="card-h"><h3>الأقسام</h3></div>
            <div className="card-b" style={{ padding: '12px 14px', display: 'grid', gap: 10 }}>
              <input id="catalogue-search" className="input" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث في الأقسام والخدمات…" />
              <NameForm
                id="catalogue-new-department"
                placeholder="اسم قسمٍ جديد"
                submitLabel="إضافة"
                busy={busy}
                error={errorOf('catalogue-new-department')}
                onSubmit={(name, reset) => send('post', '/admin/catalogue/departments', { name }, 'catalogue-new-department', reset)}
              />
              <div>
                {visible.map((d) => {
                  const index = departments.findIndex((x) => x.id === d.id);

                  return (
                    <div key={d.id} style={{ ...rowStyle, background: d.id === selectedId ? 'rgba(14,92,156,.06)' : undefined, paddingInline: 6, borderRadius: 8 }}>
                      <button type="button" onClick={() => setSelectedId(d.id)} style={{ flex: 1, textAlign: 'start', fontWeight: 700, fontSize: 13.5, color: 'var(--deep)' }}>
                        {d.name}
                        <span style={{ ...muted, fontWeight: 400, marginInlineStart: 6 }}>{d.services.length} خدمة</span>
                      </button>
                      {!d.active && <Badge text="موقوف" tone="b-grey" />}
                      {!search && (
                        <>
                          <button type="button" className="btn soft sm" aria-label={`رفع ${d.name}`} disabled={busy || index === 0} onClick={() => reorderDepartments(index, -1)}>▲</button>
                          <button type="button" className="btn soft sm" aria-label={`خفض ${d.name}`} disabled={busy || index === departments.length - 1} onClick={() => reorderDepartments(index, 1)}>▼</button>
                        </>
                      )}
                    </div>
                  );
                })}
                {visible.length === 0 && <div style={muted}>لا نتائج مطابقة.</div>}
              </div>
            </div>
          </div>

          {/* ── تفاصيل القسم المختار ── */}
          {selected && (
            <div className="card" style={{ flex: '2 1 420px' }}>
              <div className="card-h" style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <h3 style={{ margin: 0 }}>{selected.name}</h3>
                <Badge text={selected.active ? 'فعّال' : 'موقوف'} tone={selected.active ? 'b-green' : 'b-grey'} />
              </div>
              <div className="card-b" style={{ padding: '14px 16px', display: 'grid', gap: 14 }}>
                <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', ...muted }}>
                  <span>التذاكر: <b>{selected.usage.tickets}</b></span>
                  <span>القضايا: <b>{selected.usage.cases}</b></span>
                  <span>الاستشارات: <b>{selected.usage.consults}</b></span>
                  <span>محامون متخصّصون: <b>{selected.usage.lawyers}</b></span>
                </div>

                <div className="field" style={{ margin: 0 }}>
                  <label htmlFor={`dept-name-${selected.id}`}>اسم القسم</label>
                  <NameForm
                    key={`dept-${selected.id}-${selected.name}`}
                    id={`dept-name-${selected.id}`}
                    initial={selected.name}
                    placeholder="اسم القسم"
                    submitLabel="حفظ الاسم"
                    busy={busy}
                    error={errorOf(`dept-name-${selected.id}`)}
                    onSubmit={(name) => send('put', `/admin/catalogue/departments/${selected.id}`, { name }, `dept-name-${selected.id}`)}
                  />
                </div>

                <div>
                  <button type="button" className={`btn sm${selected.active ? ' soft' : ''}`} disabled={busy} onClick={() => toggleDepartment(selected)}>
                    <Icon name={selected.active ? 'close' : 'check'} /> {selected.active ? 'إيقاف القسم' : 'تفعيل القسم'}
                  </button>
                </div>

                {selected.aliases.length > 0 && (
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 6 }}>أسماء قديمة تُطابَق مع هذا القسم</div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                      {selected.aliases.map((a) => <span key={a} className="chip muted">{a}</span>)}
                    </div>
                  </div>
                )}

                <div>
                  <div style={{ fontWeight: 800, fontSize: 14, marginBottom: 6 }}>الخدمات ({selected.services.length})</div>
                  <NameForm
                    key={`new-service-${selected.id}`}
                    id={`new-service-${selected.id}`}
                    placeholder="اسم خدمةٍ جديدة في هذا القسم"
                    submitLabel="إضافة خدمة"
                    busy={busy}
                    error={errorOf(`new-service-${selected.id}`)}
                    onSubmit={(name, reset) => send('post', `/admin/catalogue/departments/${selected.id}/services`, { name }, `new-service-${selected.id}`, reset)}
                  />
                  <div style={{ marginTop: 8 }}>
                    {selected.services.map((s, index) => (
                      <div key={s.id} style={rowStyle}>
                        <div style={{ flex: '1 1 260px' }}>
                          <NameForm
                            key={`svc-${s.id}-${s.name}`}
                            id={`service-name-${s.id}`}
                            initial={s.name}
                            placeholder="اسم الخدمة"
                            submitLabel="حفظ"
                            busy={busy}
                            error={errorOf(`service-name-${s.id}`)}
                            onSubmit={(name) => send('put', `/admin/catalogue/services/${s.id}`, { name }, `service-name-${s.id}`)}
                          />
                        </div>
                        {!s.active && <Badge text="موقوفة" tone="b-grey" />}
                        <button type="button" className="btn soft sm" aria-label={`رفع ${s.name}`} disabled={busy || index === 0} onClick={() => reorderServices(selected, index, -1)}>▲</button>
                        <button type="button" className="btn soft sm" aria-label={`خفض ${s.name}`} disabled={busy || index === selected.services.length - 1} onClick={() => reorderServices(selected, index, 1)}>▼</button>
                        <button type="button" className="btn soft sm" disabled={busy} onClick={() => send('post', `/admin/catalogue/services/${s.id}/toggle`, {}, 'actions')}>
                          {s.active ? 'إيقاف' : 'تفعيل'}
                        </button>
                      </div>
                    ))}
                    {selected.services.length === 0 && <div style={muted}>لا خدمات في هذا القسم بعد.</div>}
                  </div>
                </div>

                {/* ── قائمة المستندات المطلوبة للقسم — منها تُحسب «النواقص» في كلّ تذكرة ── */}
                <div>
                  <div style={{ fontWeight: 800, fontSize: 14, marginBottom: 4 }}>المستندات المطلوبة ({selected.documents.length})</div>
                  <p style={{ ...muted, margin: '0 0 8px' }}>
                    تُطلب من العميل في تذاكر هذا القسم، ويُسقط منها ما ثبت إرفاقه. الإلزاميّ يُطلب أوّلاً، والاختياريّ موسوماً.
                  </p>
                  {selected.documents.length === 0 && (
                    <div style={{ ...muted, marginBottom: 8 }}>
                      لا قائمة لهذا القسم بعد — تُطلب القائمة العامّة: {defaultDocuments.map((d) => d.name).join('، ')}.
                      {' '}إضافة أوّل مستندٍ تنسخ بنود القائمة العامّة إلى هذا القسم أوّلاً، فتبقى مطلوبةً وتستطيع تعديلها أو حذفها.
                    </div>
                  )}
                  <NameForm
                    key={`new-document-${selected.id}`}
                    id={`new-document-${selected.id}`}
                    placeholder="اسم مستندٍ مطلوب في هذا القسم"
                    submitLabel="إضافة مستند"
                    busy={busy}
                    error={errorOf(`new-document-${selected.id}`)}
                    onSubmit={(name, reset) => send('post', `/admin/catalogue/departments/${selected.id}/documents`, { name, required: newDocRequired }, `new-document-${selected.id}`, reset)}
                  />
                  <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12.5, marginTop: 6 }}>
                    <input type="checkbox" checked={newDocRequired} onChange={(e) => setNewDocRequired(e.target.checked)} />
                    إلزاميّ (أزل العلامة لمستندٍ اختياريّ)
                  </label>
                  <div style={{ marginTop: 8 }}>
                    {selected.documents.map((doc, index) => (
                      <div key={doc.id} style={rowStyle}>
                        <div style={{ flex: '1 1 240px' }}>
                          <NameForm
                            key={`doc-${doc.id}-${doc.name}`}
                            id={`document-name-${doc.id}`}
                            initial={doc.name}
                            placeholder="اسم المستند"
                            submitLabel="حفظ"
                            busy={busy}
                            error={errorOf(`document-name-${doc.id}`)}
                            onSubmit={(name) => send('put', `/admin/catalogue/documents/${doc.id}`, { name }, `document-name-${doc.id}`)}
                          />
                        </div>
                        <button
                          type="button"
                          className="btn soft sm"
                          disabled={busy}
                          title="بدّل بين إلزاميّ واختياريّ"
                          onClick={() => send('put', `/admin/catalogue/documents/${doc.id}`, { required: !doc.required }, 'actions')}
                        >
                          <Badge text={doc.required ? 'إلزاميّ' : 'اختياريّ'} tone={doc.required ? 'b-blue' : 'b-grey'} />
                        </button>
                        <button type="button" className="btn soft sm" aria-label={`رفع ${doc.name}`} disabled={busy || index === 0} onClick={() => reorderDocuments(selected, index, -1)}>▲</button>
                        <button type="button" className="btn soft sm" aria-label={`خفض ${doc.name}`} disabled={busy || index === selected.documents.length - 1} onClick={() => reorderDocuments(selected, index, 1)}>▼</button>
                        <button type="button" className="btn soft sm" disabled={busy} onClick={() => deleteDocument(doc)}>حذف</button>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      ) : (
        /* ── الأقسام الإداريّة ── */
        <div className="card">
          <div className="card-h"><h3>الأقسام الإداريّة للموظّفين</h3></div>
          <div className="card-b" style={{ padding: '14px 16px', display: 'grid', gap: 10 }}>
            <p style={{ ...muted, margin: 0 }}>تُختار للموظّف والإدارة في «تسجيل الموظفين»، ولا تدخل في الإسناد القانونيّ.</p>
            <NameForm
              id="catalogue-new-staff-department"
              placeholder="اسم قسمٍ إداريٍّ جديد"
              submitLabel="إضافة"
              busy={busy}
              error={errorOf('catalogue-new-staff-department')}
              onSubmit={(name, reset) => send('post', '/admin/catalogue/staff-departments', { name }, 'catalogue-new-staff-department', reset)}
            />
            {staffDepartments.map((d) => (
              <div key={d.id} style={rowStyle}>
                <div style={{ flex: '1 1 260px' }}>
                  <NameForm
                    key={`staff-${d.id}-${d.name}`}
                    id={`staff-department-name-${d.id}`}
                    initial={d.name}
                    placeholder="اسم القسم"
                    submitLabel="حفظ"
                    busy={busy}
                    error={errorOf(`staff-department-name-${d.id}`)}
                    onSubmit={(name) => send('put', `/admin/catalogue/staff-departments/${d.id}`, { name }, `staff-department-name-${d.id}`)}
                  />
                </div>
                <span style={muted}>{d.employees} موظّف</span>
                {!d.active && <Badge text="موقوف" tone="b-grey" />}
                <button type="button" className="btn soft sm" disabled={busy} onClick={() => send('post', `/admin/catalogue/staff-departments/${d.id}/toggle`, {}, 'actions')}>
                  {d.active ? 'إيقاف' : 'تفعيل'}
                </button>
              </div>
            ))}
            {staffDepartments.length === 0 && <div style={muted}>لا أقسام إداريّة بعد.</div>}
          </div>
        </div>
      )}

      {/* ── تأكيد إيقاف قسمٍ ذي أثر ── */}
      <Modal title="تأكيد إيقاف القسم" subtitle={confirming?.name} open={confirming !== null} onClose={() => setConfirming(null)} maxWidth={460}>
        {confirming && (
          <div style={{ display: 'grid', gap: 12 }}>
            <p style={{ margin: 0, lineHeight: 1.9 }}>
              {confirming.impact.lawyersOnlyHere > 0 && <>هذا القسم التخصّص الوحيد لـ<b>{confirming.impact.lawyersOnlyHere}</b> محامٍ فعّال، فلن تُسند إليهم تذاكره الجديدة تلقائيّاً. </>}
              {confirming.impact.openTickets > 0 && <>فيه <b>{confirming.impact.openTickets}</b> تذكرة مفتوحة تبقى كما هي. </>}
              لن يظهر القسم في الاختيار بعد الإيقاف، ويمكن تفعيله لاحقاً.
            </p>
            {errorOf('toggle-department', 'confirm') && <div style={errText}>{errorOf('toggle-department', 'confirm')}</div>}
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="button" className="btn sm" disabled={busy} onClick={() => toggleDepartment(confirming, true)}>
                <Icon name="check" /> تأكيد الإيقاف
              </button>
              <button type="button" className="btn soft sm" onClick={() => setConfirming(null)}>إلغاء</button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
};

export default AdminCatalogue;
