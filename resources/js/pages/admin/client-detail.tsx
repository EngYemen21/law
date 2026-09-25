import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
// import StatRow from '@/components/babylon/StatRow'; // غير مستخدم — البطاقات تُرسم محليًا بنمط الصفحة
import type { StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { EXEC_FLOW } from '@/lib/exec-flow';
import Icon from '@/lib/icons';

interface ClientData {
  id: number;
  name: string;
  email: string;
  phone: string;
  national_id: string;
  status: 'active' | 'suspended';
  statusLabel: string;
  avatar: string;
  phoneVerifiedAt: string | null;
  emailVerifiedAt: string | null;
  createdAt: string;
}

interface StatsData {
  totalTickets: number;
  openTickets: number;
  totalCases: number;
  activeCases: number;
  totalConsults: number;
  totalExecutions: number;
  totalInvoiced: number;
  totalPaid: number;
  unpaidBalance: number;
}

interface TicketItem {
  id: number;
  no: string;
  type: string;
  subject: string;
  dept: string;
  status: string;
  tone: string;
  lawyer: string;
  date: string;
}

interface CaseItem {
  id: number;
  no: string;
  type: string;
  court: string;
  lawyer: string;
  status: string;
  tone: string;
  fee: string;
  feeStatus: string;
  hearingsCount: number;
  date: string;
}

interface ConsultItem {
  id: number;
  ref: string;
  subject: string;
  channel: string;
  specialty: string;
  status: string;
  session: string;
  total: string;
  isPaid: boolean;
  lawyer: string;
  when: string;
  date: string;
}

interface ExecutionItem {
  id: number;
  no: string;
  sanad: string;
  subject: string;
  defendant: string;
  stage: number;
  status: string;
  tone: string;
  amount: string;
  fee: string;
  paid: boolean;
  date: string;
}

interface InvoiceItem {
  id: number;
  no: string;
  desc: string;
  amount: string;
  rawAmount: number;
  paid: boolean;
  status: string;
  tone: string;
  dueAt: string;
  date: string;
}

interface DocumentItem {
  id: string;
  name: string;
  meta: string;
  type: string;
  hasFile: boolean;
  downloadUrl: string | null;
  date: string;
}

interface Props {
  client: ClientData;
  stats: StatsData;
  tickets: TicketItem[];
  cases: CaseItem[];
  consults: ConsultItem[];
  executions: ExecutionItem[];
  invoices: InvoiceItem[];
  documents: DocumentItem[];
}

type TabType = 'tickets' | 'cases' | 'consults' | 'executions' | 'invoices' | 'documents' | 'edit';

const AdminClientDetail: React.FC<Props> = ({
  client,
  stats,
  tickets,
  cases,
  consults,
  executions,
  invoices,
  documents,
}) => {
  const ask = useConfirm();
  const toast = useToast();

  // نموذج التعديل
  const [name, setName] = useState(client.name);
  const [email, setEmail] = useState(client.email);
  const [phone, setPhone] = useState(client.phone);
  const [nationalId, setNationalId] = useState(client.national_id);
  const [status, setStatus] = useState<'active' | 'suspended'>(client.status);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  // التبويب النشط
  const [activeTab, setActiveTab] = useState<TabType>('tickets');

  // حفظ التعديلات
  const handleUpdate = (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});

    router.put(
      `/admin/clients/${client.id}`,
      {
        name,
        email,
        phone,
        national_id: nationalId,
        status,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast('تم تحديث بيانات العميل بنجاح');
          setBusy(false);
        },
        onError: (errs) => {
          setErrors(errs);
          const firstErr = Object.values(errs)[0];
          toast(firstErr ? `⚠️ ${firstErr}` : 'تعذّر حفظ التعديلات');
          setBusy(false);
        },
      }
    );
  };

  // تبديل حالة الحساب سريعاً
  const handleToggleStatus = async () => {
    const action = status === 'active' ? 'إيقاف' : 'تفعيل';

    if (
      !(await ask({
        title: `${action} حساب العميل`,
        message:
          action === 'إيقاف'
            ? 'يُمنع العميل من الدخول إلى حسابه فور التأكيد.'
            : 'يستعيد العميل القدرة على الدخول إلى حسابه فور التأكيد.',
        confirmLabel: action,
        tone: action === 'إيقاف' ? 'danger' : 'default',
      }))
    ) {
      return;
    }

    router.post(
      `/admin/clients/${client.id}/toggle`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => {
          setStatus(status === 'active' ? 'suspended' : 'active');
          toast(`تم ${action} الحساب بنجاح`);
        },
      }
    );
  };

  // بطاقات الإحصائيات المتوافقة مع هوية Babylon
  const statItems: StatItem[] = [
    ['t-blue', 'ticket', `${stats.openTickets} / ${stats.totalTickets}`, 'تذاكر (مفتوحة / إجمالي)'],
    ['t-amber', 'scale', `${stats.activeCases} / ${stats.totalCases}`, 'قضايا جارية'],
    ['t-cyan', 'video', stats.totalConsults, 'الاستشارات'],
    ['t-purple', 'exec', stats.totalExecutions, 'طلبات التنفيذ'],
    ['t-green', 'card', `${stats.totalPaid.toLocaleString()} ر.س`, 'المبالغ المحصّلة'],
    [stats.unpaidBalance > 0 ? 't-red' : 't-grey', 'card', `${stats.unpaidBalance.toLocaleString()} ر.س`, 'المستحقات المعلقة'],
  ];

  // نسبة سداد الفواتير
  const paidPercent = stats.totalInvoiced > 0
    ? Math.min(100, Math.round((stats.totalPaid / stats.totalInvoiced) * 100))
    : 100;

  return (
    <>
      {/* شريط الإجراءات العلوي */}
      <div style={{ marginBottom: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
        <Link href="/admin/clients" className="btn sm soft">
          <Icon name="reply" /> العودة لدليل العملاء
        </Link>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <span className="mono" style={{ background: '#fff', padding: '5px 12px', borderRadius: 8, fontSize: 13, border: '1px solid var(--line)', fontWeight: 700 }}>
            CL-{String(client.id).padStart(5, '0')}
          </span>
          <button
            type="button"
            onClick={handleToggleStatus}
            className={`btn sm ${status === 'active' ? 'ghost' : ''}`}
            style={{
              borderColor: status === 'active' ? 'var(--line)' : 'var(--success)',
              color: status === 'active' ? 'var(--red)' : '#fff',
              background: status === 'active' ? '#fff' : 'var(--success)',
            }}
          >
            {status === 'active' ? 'إيقاف الحساب' : 'تفعيل الحساب'}
          </button>
        </div>
      </div>

      {/* البانر الرئيسي لهوية ملف العميل */}
      <div className="hero">
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
          <div
            style={{
              width: 60,
              height: 60,
              borderRadius: 16,
              background: 'rgba(255, 255, 255, 0.2)',
              border: '1.5px solid rgba(255, 255, 255, 0.35)',
              display: 'grid',
              placeItems: 'center',
              fontSize: 24,
              fontWeight: 800,
              color: '#fff',
              flexShrink: 0,
            }}
          >
            {client.avatar || client.name.replace(/^أ\.?\s*/, '').slice(0, 1) || 'ع'}
          </div>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
              <h2 style={{ margin: 0, fontSize: 22, color: '#fff' }}>{client.name}</h2>
              <span
                style={{
                  background: status === 'active' ? 'rgba(34, 197, 94, 0.25)' : 'rgba(239, 68, 68, 0.3)',
                  border: `1px solid ${status === 'active' ? '#86efac' : '#fca5a5'}`,
                  color: '#fff',
                  borderRadius: 6,
                  padding: '2px 8px',
                  fontSize: 11.5,
                  fontWeight: 700,
                }}
              >
                {status === 'active' ? 'حساب نشط' : 'حساب موقوف'}
              </span>
            </div>
            <p style={{ margin: '6px 0 0', opacity: 0.9, fontSize: 13 }}>
              الهوية: <span className="mono">{client.national_id || '—'}</span> • الجوال: <span className="mono" style={{ direction: 'ltr', display: 'inline-block' }}>{client.phone || '—'}</span> • البريد: <span className="mono">{client.email}</span>
            </p>
          </div>
        </div>

        <div className="hero-cta" style={{ flexWrap: 'wrap', gap: 8 }}>
          <button
            className={`hero-b ${activeTab === 'tickets' ? '' : 'ghost'}`}
            onClick={() => setActiveTab('tickets')}
            type="button"
          >
            <Icon name="ticket" /> التذاكر ({tickets.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'cases' ? '' : 'ghost'}`}
            onClick={() => setActiveTab('cases')}
            type="button"
          >
            <Icon name="scale" /> القضايا ({cases.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'consults' ? '' : 'ghost'}`}
            onClick={() => setActiveTab('consults')}
            type="button"
          >
            <Icon name="video" /> الاستشارات ({consults.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'executions' ? '' : 'ghost'}`}
            onClick={() => setActiveTab('executions')}
            type="button"
          >
            <Icon name="exec" /> التنفيذ ({executions.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'invoices' ? '' : 'ghost'}`}
            onClick={() => setActiveTab('invoices')}
            type="button"
          >
            <Icon name="card" /> الفواتير ({invoices.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'documents' ? '' : 'ghost'}`}
            onClick={() => setActiveTab('documents')}
            type="button"
          >
            <Icon name="doc" /> المستندات ({documents.length})
          </button>
          <button
            className={`hero-b ${activeTab === 'edit' ? '' : 'ghost'}`}
            onClick={() => setActiveTab('edit')}
            type="button"
            style={{ marginInlineStart: 'auto', background: activeTab === 'edit' ? '#fff' : 'rgba(255, 255, 255, 0.22)' }}
          >
            <Icon name="user" /> تعديل بيانات العميل
          </button>
        </div>
      </div>

      {/* صف الإحصائيات السريعة — شبكة متجاوبة سلسة تمنع أي تمرير زائد */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
          gap: 12,
          marginBottom: 20,
        }}
      >
        {statItems.map(([tone, ic, num, lbl]) => (
          <div key={lbl} className={`stat ${tone}`} style={{ cursor: 'default', padding: 14 }}>
            <div className="si" style={{ width: 36, height: 36, marginBottom: 10 }}>
              <Icon name={ic} />
            </div>
            <div className="num" style={{ fontSize: 20 }}>{num}</div>
            <div className="lbl" style={{ fontSize: 12 }}>{lbl}</div>
          </div>
        ))}
      </div>

      {/* التخطيط الرئيسي: سجل النشاطات + الشريط الجانبي */}
      <div className="tflow" style={{ marginBottom: 20 }}>
        <div className="tf-grid">
          {/* العمود الرئيسي: التبويبات النشطة وجداول البيانات */}
          <div>
            <div className="card">
              <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'tickets' ? '' : 'soft'}`}
                    onClick={() => setActiveTab('tickets')}
                  >
                    <Icon name="ticket" /> التذاكر ({tickets.length})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'cases' ? '' : 'soft'}`}
                    onClick={() => setActiveTab('cases')}
                  >
                    <Icon name="scale" /> القضايا ({cases.length})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'consults' ? '' : 'soft'}`}
                    onClick={() => setActiveTab('consults')}
                  >
                    <Icon name="video" /> الاستشارات ({consults.length})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'executions' ? '' : 'soft'}`}
                    onClick={() => setActiveTab('executions')}
                  >
                    <Icon name="exec" /> التنفيذ ({executions.length})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'invoices' ? '' : 'soft'}`}
                    onClick={() => setActiveTab('invoices')}
                  >
                    <Icon name="card" /> الفواتير ({invoices.length})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'documents' ? '' : 'soft'}`}
                    onClick={() => setActiveTab('documents')}
                  >
                    <Icon name="doc" /> المستندات ({documents.length})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'edit' ? '' : 'soft'}`}
                    onClick={() => setActiveTab('edit')}
                  >
                    <Icon name="user" /> تعديل الحساب
                  </button>
                </div>
              </div>

              <div className="card-b t-wrap">
                {/* 1. التذاكر */}
                {activeTab === 'tickets' && (
                  tickets.length === 0 ? (
                    <div className="empty"><Icon name="ticket" /><b>لا توجد تذاكر مسجلة لهذا العميل</b></div>
                  ) : (
                    <div className="t-wrap">
                      <table className="tbl" style={{ minWidth: 720 }}>
                        <thead>
                          <tr>
                            <th>رقم التذكرة</th>
                            <th>النوع</th>
                            <th>الموضوع</th>
                            <th>القسم</th>
                            <th>المحامي</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                            <th style={{ textAlign: 'center' }}>الإجراء</th>
                          </tr>
                        </thead>
                        <tbody>
                          {tickets.map((t) => (
                            <tr key={t.id}>
                              <td className="mono"><b>{t.no}</b></td>
                              <td>{t.type}</td>
                              <td>{t.subject}</td>
                              <td>
                                <span className="badge-s b-blue" style={{ fontSize: 11.5, padding: '3px 8px' }}>
                                  <Icon name="folder" cls="ic sm" /> {t.dept || 'القسم العام'}
                                </span>
                              </td>
                              <td className="muted">{t.lawyer}</td>
                              <td><Badge text={t.status} tone={t.tone} /></td>
                              <td className="muted mono" style={{ fontSize: 12 }}>{t.date}</td>
                              <td style={{ textAlign: 'center' }}>
                                <Link href={`/admin/tickets/${t.no || t.id}`} className="btn sm soft">
                                  فتح التذكرة
                                </Link>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )
                )}

                {/* 2. القضايا */}
                {activeTab === 'cases' && (
                  cases.length === 0 ? (
                    <div className="empty"><Icon name="scale" /><b>لا توجد قضايا مسجلة لهذا العميل</b></div>
                  ) : (
                    <div className="t-wrap">
                      <table className="tbl" style={{ minWidth: 720 }}>
                        <thead>
                          <tr>
                            <th>رقم القضية</th>
                            <th>النوع</th>
                            <th>المحكمة</th>
                            <th>المحامي</th>
                            <th>الجلسات</th>
                            <th>الأتعاب</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                          </tr>
                        </thead>
                        <tbody>
                          {cases.map((c) => (
                            <tr key={c.id}>
                              <td className="mono"><b>{c.no}</b></td>
                              <td>{c.type}</td>
                              <td className="muted">{c.court}</td>
                              <td className="muted">{c.lawyer}</td>
                              <td><span className="chip">{c.hearingsCount} جلسات</span></td>
                              <td className="mono">{c.fee}</td>
                              <td><Badge text={c.status} tone={c.tone} /></td>
                              <td className="muted mono" style={{ fontSize: 12 }}>{c.date}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )
                )}

                {/* 3. الاستشارات */}
                {activeTab === 'consults' && (
                  consults.length === 0 ? (
                    <div className="empty"><Icon name="video" /><b>لا توجد استشارات مسجلة لهذا العميل</b></div>
                  ) : (
                    <div className="t-wrap">
                      <table className="tbl" style={{ minWidth: 720 }}>
                        <thead>
                          <tr>
                            <th>المرجع</th>
                            <th>الموضوع</th>
                            <th>القناة</th>
                            <th>المستشار</th>
                            <th>الموعد</th>
                            <th>المبلغ</th>
                            <th>السداد</th>
                          </tr>
                        </thead>
                        <tbody>
                          {consults.map((cn) => (
                            <tr key={cn.id}>
                              <td className="mono"><b>{cn.ref}</b></td>
                              <td>{cn.subject}</td>
                              <td><Badge text={cn.channel} tone="b-blue" /></td>
                              <td className="muted">{cn.lawyer}</td>
                              <td className="muted mono" style={{ fontSize: 12 }}>{cn.when}</td>
                              <td className="mono">{cn.total}</td>
                              <td>
                                <Badge text={cn.isPaid ? 'مدفوعة' : 'غير مسددة'} tone={cn.isPaid ? 'b-green' : 'b-amber'} />
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )
                )}

                {/* 4. التنفيذ */}
                {activeTab === 'executions' && (
                  executions.length === 0 ? (
                    <div className="empty"><Icon name="exec" /><b>لا توجد طلبات تنفيذ مسجلة لهذا العميل</b></div>
                  ) : (
                    <div className="t-wrap">
                      <table className="tbl" style={{ minWidth: 720 }}>
                        <thead>
                          <tr>
                            <th>رقم الطلب</th>
                            <th>نوع السند</th>
                            <th>الموضوع</th>
                            <th>المنفذ ضده</th>
                            <th>المبلغ</th>
                            <th>المرحلة</th>
                            <th>الحالة</th>
                          </tr>
                        </thead>
                        <tbody>
                          {executions.map((ex) => (
                            <tr key={ex.id}>
                              <td className="mono"><b>{ex.no}</b></td>
                              <td><span className="chip">{ex.sanad}</span></td>
                              <td>{ex.subject}</td>
                              <td className="muted">{ex.defendant}</td>
                              <td className="mono">{ex.amount}</td>
                              {/* اسم المرحلة كشاشة التنفيذ — «مرحلة 8/10» هنا مقابل «قيد التنفيذ» هناك: رقمان لملفٍّ واحد */}
                              <td><span className="chip">{EXEC_FLOW[ex.stage] ?? '—'}</span></td>
                              <td><Badge text={ex.status} tone={ex.tone} /></td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )
                )}

                {/* 5. الفواتير */}
                {activeTab === 'invoices' && (
                  invoices.length === 0 ? (
                    <div className="empty"><Icon name="card" /><b>لا توجد فواتير مسجلة لهذا العميل</b></div>
                  ) : (
                    <div className="t-wrap">
                      <table className="tbl" style={{ minWidth: 720 }}>
                        <thead>
                          <tr>
                            <th>رقم الفاتورة</th>
                            <th>الوصف</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th>الاستحقاق</th>
                            <th>الإصدار</th>
                            <th style={{ textAlign: 'center' }}>الإجراء</th>
                          </tr>
                        </thead>
                        <tbody>
                          {invoices.map((inv) => (
                            <tr key={inv.id}>
                              <td className="mono"><b>{inv.no}</b></td>
                              <td>{inv.desc}</td>
                              <td className="mono"><b>{inv.amount}</b></td>
                              <td><Badge text={inv.status} tone={inv.tone} /></td>
                              <td className="muted mono" style={{ fontSize: 12 }}>{inv.dueAt}</td>
                              <td className="muted mono" style={{ fontSize: 12 }}>{inv.date}</td>
                              <td style={{ textAlign: 'center' }}>
                                <a
                                  href={`/admin/invoices/${inv.no}/pdf`}
                                  target="_blank"
                                  rel="noreferrer"
                                  className="btn sm soft"
                                >
                                  <Icon name="download" /> PDF
                                </a>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )
                )}

                {/* 6. المستندات */}
                {activeTab === 'documents' && (
                  documents.length === 0 ? (
                    <div className="empty"><Icon name="doc" /><b>لا توجد مستندات مسجلة لهذا العميل</b></div>
                  ) : (
                    <div className="t-wrap">
                      <table className="tbl" style={{ minWidth: 600 }}>
                        <thead>
                          <tr>
                            <th>اسم المستند</th>
                            <th>التصنيف</th>
                            <th>تاريخ الرفع</th>
                            <th style={{ textAlign: 'center' }}>الإجراء</th>
                          </tr>
                        </thead>
                        <tbody>
                          {documents.map((d) => (
                            <tr key={d.id}>
                              <td>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                  <Icon name="doc" />
                                  <b>{d.name}</b>
                                </div>
                              </td>
                              <td><span className="chip">{d.meta}</span></td>
                              <td className="muted mono" style={{ fontSize: 12 }}>{d.date}</td>
                              <td style={{ textAlign: 'center' }}>
                                {d.downloadUrl ? (
                                  <a
                                    href={d.downloadUrl}
                                    className="btn sm soft"
                                    target="_blank"
                                    rel="noreferrer"
                                  >
                                    <Icon name="download" /> تنزيل
                                  </a>
                                ) : (
                                  <span className="muted">—</span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )
                )}

                {/* 7. تعديل بيانات العميل */}
                {activeTab === 'edit' && (
                  <div style={{ padding: 18 }}>
                    <form onSubmit={handleUpdate}>
                      <div className="field">
                        <label>الاسم الكامل <span style={{ color: 'var(--red)' }}>*</span></label>
                        <input
                          type="text"
                          className="input"
                          value={name}
                          onChange={(e) => setName(e.target.value)}
                          placeholder="اسم العميل"
                          required
                        />
                        {errors.name && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{errors.name}</div>}
                      </div>

                      <div className="picker-grid">
                        <div className="field">
                          <label>رقم الهوية الوطنية / الإقامة <span style={{ color: 'var(--red)' }}>*</span></label>
                          <input
                            type="text"
                            className="input mono"
                            value={nationalId}
                            onChange={(e) => setNationalId(e.target.value)}
                            placeholder="10 أرقام"
                            maxLength={10}
                            required
                          />
                          {errors.national_id && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{errors.national_id}</div>}
                        </div>

                        <div className="field">
                          <label>رقم الجوال <span style={{ color: 'var(--red)' }}>*</span></label>
                          <input
                            type="text"
                            className="input mono"
                            value={phone}
                            onChange={(e) => setPhone(e.target.value)}
                            placeholder="05xxxxxxxx"
                            required
                          />
                          {errors.phone && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{errors.phone}</div>}
                        </div>
                      </div>

                      <div className="picker-grid">
                        <div className="field">
                          <label>البريد الإلكتروني <span style={{ color: 'var(--red)' }}>*</span></label>
                          <input
                            type="email"
                            className="input"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder="client@domain.com"
                            required
                          />
                          {errors.email && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{errors.email}</div>}
                        </div>

                        <div className="field">
                          <label>حالة الحساب</label>
                          <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value as 'active' | 'suspended')}
                          >
                            <option value="active">نشط — مسموح له بالدخول</option>
                            <option value="suspended">موقوف — محظور من الدخول</option>
                          </select>
                          {errors.status && <div style={{ color: 'var(--red)', fontSize: 12, marginTop: 4 }}>{errors.status}</div>}
                        </div>
                      </div>

                      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14, gap: 8 }}>
                        <button type="button" className="btn soft" onClick={() => setActiveTab('tickets')}>
                          إلغاء
                        </button>
                        <button type="submit" disabled={busy} className="btn" style={{ minWidth: 140 }}>
                          <Icon name="check" /> {busy ? 'جارٍ الحفظ...' : 'حفظ التعديلات'}
                        </button>
                      </div>
                    </form>
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* الشريط الجانبي المطابق لتصميم Babylon */}
          <aside className="tf-aside">
            {/* بطاقة هوية العميل */}
            <div className="card" style={{ marginBottom: 14 }}>
              <div className="tc-top">
                <div className="lbl">معرّف العميل</div>
                <div className="num">CL-{String(client.id).padStart(5, '0')}</div>
              </div>
              <div className="tc-body">
                <div className="tc-row">
                  <span className="k">الهوية</span>
                  <span className="v mono">{client.national_id || '—'}</span>
                </div>
                <div className="tc-row">
                  <span className="k">الجوال</span>
                  <span className="v mono" style={{ direction: 'ltr' }}>{client.phone || '—'}</span>
                </div>
                <div className="tc-row">
                  <span className="k">البريد</span>
                  <span className="v mono" style={{ fontSize: 12 }}>{client.email}</span>
                </div>
                <div className="tc-row">
                  <span className="k">الحالة</span>
                  <span className="v">
                    <Badge text={status === 'active' ? 'نشط' : 'موقوف'} tone={status === 'active' ? 'b-green' : 'b-grey'} />
                  </span>
                </div>
                <div className="tc-row">
                  <span className="k">الانضمام</span>
                  <span className="v mono">{client.createdAt}</span>
                </div>
              </div>
            </div>

            {/* بطاقة الملخص المالي */}
            <div className="card">
              <div className="card-h">
                <h3 style={{ fontSize: 13 }}>الملخص المالي</h3>
              </div>
              <div className="card-b" style={{ padding: '14px 16px' }}>
                <div className="kv" style={{ padding: '6px 0' }}>
                  <span className="k">إجمالي الفواتير</span>
                  <span className="v mono"><b>{stats.totalInvoiced.toLocaleString()} ر.س</b></span>
                </div>
                <div className="kv" style={{ padding: '6px 0' }}>
                  <span className="k">المبالغ المسددة</span>
                  <span className="v mono" style={{ color: 'var(--success)', fontWeight: 700 }}>
                    {stats.totalPaid.toLocaleString()} ر.س
                  </span>
                </div>
                <div className="kv" style={{ padding: '6px 0' }}>
                  <span className="k">المستحقات المعلقة</span>
                  <span className="v mono" style={{ color: stats.unpaidBalance > 0 ? 'var(--red)' : 'inherit', fontWeight: 700 }}>
                    {stats.unpaidBalance.toLocaleString()} ر.س
                  </span>
                </div>

                <div style={{ marginTop: 12 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11.5, color: 'var(--muted)', marginBottom: 4 }}>
                    <span>نسبة التحصيل</span>
                    <span className="mono">{paidPercent}%</span>
                  </div>
                  <div style={{ height: 6, background: 'var(--line-soft)', borderRadius: 999, overflow: 'hidden' }}>
                    <div
                      style={{
                        height: '100%',
                        width: `${paidPercent}%`,
                        background: paidPercent === 100 ? 'var(--success)' : 'var(--primary)',
                        borderRadius: 999,
                        transition: 'width .3s ease',
                      }}
                    />
                  </div>
                </div>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </>
  );
};

export default AdminClientDetail;
