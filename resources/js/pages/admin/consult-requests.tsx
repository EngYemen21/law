import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { maskClient } from '@/lib/admin-data';
import { PricingAction, type ConsultCard } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { crChannelIcon, crChannelTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';

// يطابق bkAdminView في index (21).html — طلبات الاستشارات وتسعيرها (الإدارة العليا فقط):
// دورة الحجز قبل الجلسة (بانتظار التسعير → بانتظار السداد → بانتظار تحديد الموعد) + تحديد السعر.

const STATUS_TONE: Record<string, string> = {
  'بانتظار التسعير': 'b-amber',
  'بانتظار السداد': 'b-blue',
  'بانتظار تحديد الموعد': 'b-cyan',
};

const PRE_SESSION = ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'];

const AdminConsultRequests: React.FC<{ consults: ConsultCard[] }> = ({ consults }) => {
  const toast = useToast();
  const [items, setItems] = useState<ConsultCard[]>(consults);

  // تزامن لحظي: دفع العميل/اختياره الموعد يصل فوراً — كان المدير يذكّر عميلاً دفع لتوّه أو جدول فعلاً
  useEffect(() => {
    setItems(consults);
    consults.forEach((c) => {
      echo.private(`consult.${c.id}`).listen('.status', (e: { status?: string; price?: number; vat?: number; total?: number; priced?: boolean; paid?: boolean; invoiceNo?: string | null; when?: string | null }) => {
        setItems((prev) => prev.map((x) => x.id === c.id
          ? { ...x, status: e.status ?? x.status, price: e.price ?? x.price, vat: e.vat ?? x.vat, total: e.total ?? x.total, priced: e.priced ?? x.priced, paid: e.paid ?? x.paid, invoiceNo: e.invoiceNo ?? x.invoiceNo, when: e.when ?? x.when }
          : x));
      });
    });
    return () => { consults.forEach((c) => echo.leave(`consult.${c.id}`)); };
  }, [consults]);

  // ما خرج من دورة ما قبل الجلسة لحظياً (جُدول/أُلغي) يغادر القائمة — مكانه شاشات الرحلة
  const live = items.filter((c) => PRE_SESSION.includes(c.status));
  const by = (s: string) => live.filter((c) => c.status === s).length;

  const stats: StatItem[] = [
    ['t-amber', 'card', by('بانتظار التسعير'), 'بانتظار التسعير'],
    ['t-blue', 'clock', by('بانتظار السداد'), 'بانتظار السداد'],
    ['t-cyan', 'cal', by('بانتظار تحديد الموعد'), 'بانتظار تحديد الموعد'],
  ];

  return (
    <>
      <div className="greet">
        <h2>طلبات الاستشارات وتسعيرها</h2>
        <p>مراجعة طلبات العملاء وتحديد سعر كل استشارة قبل السداد واختيار الموعد.</p>
      </div>

      <StatRow items={stats} />

      <div className="card">
        <div className="card-h">
          <h3>الطلبات</h3>
          <span className="sub">{live.length} طلب</span>
        </div>
        <div className="card-b">
          {live.length ? live.map((c) => (
            <div key={c.ref} className="item">
              <div className="iico"><Icon name={crChannelIcon(c.channel)} /></div>
              <div className="imeta">
                <b>{c.ref} — {maskClient(c.client)}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  <Badge text={`استشارة ${c.channel}`} tone={crChannelTone(c.channel)} />
                  {' · '}{c.subject}
                  {c.total ? ` · ${c.total} ر.س` : ''}
                  {c.invoiceNo ? ` · ${c.invoiceNo}` : ''}
                </span>
              </div>
              <div className="iact">
                <Badge text={c.status} tone={STATUS_TONE[c.status] ?? 'b-grey'} />
                {c.status === 'بانتظار التسعير' && <PricingAction c={c} base="/admin" toast={toast} />}
                {/* مدفوع بلا موعد — كان يعلق للأبد بلا أي إجراء إداري */}
                {c.status === 'بانتظار تحديد الموعد' && (
                  <>
                    {c.paidAgo && <span className="sub">دُفع {c.paidAgo}</span>}
                    <button
                      className="btn soft sm"
                      type="button"
                      onClick={() => router.post(`/admin/consults/${c.id}/remind-schedule`, {}, {
                        preserveScroll: true,
                        onSuccess: () => toast('أُرسل التذكير للعميل'),
                        onError: (e) => toast(`⚠️ ${Object.values(e)[0] ?? 'تعذّر الإرسال'}`),
                      })}
                    >
                      <Icon name="bell" /> تذكير العميل
                    </button>
                  </>
                )}
                <button
                  className="btn soft sm"
                  type="button"
                  onClick={() => {
                    if (window.confirm(`إلغاء الطلب ${c.ref}؟ سيُشعر العميل، والاسترداد المالي يُنسّق يدوياً.`)) {
                      router.post(`/admin/consults/${c.id}/cancel-request`, {}, {
                        preserveScroll: true,
                        onSuccess: () => toast('أُلغي الطلب وأُشعر العميل'),
                        onError: (e) => toast(`⚠️ ${Object.values(e)[0] ?? 'تعذّر الإلغاء'}`),
                      });
                    }
                  }}
                >
                  <Icon name="close" /> إلغاء
                </button>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="calplus" /><b>لا طلبات استشارة</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminConsultRequests;
