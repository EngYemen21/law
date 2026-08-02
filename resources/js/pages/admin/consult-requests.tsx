import React from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { useToast } from '@/components/babylon/Toast';
import { maskClient } from '@/lib/admin-data';
import { PricingAction, type ConsultCard } from '@/lib/consult-ui';
import { crChannelIcon, crChannelTone } from '@/lib/employee-data';
import Icon from '@/lib/icons';

// يطابق bkAdminView في index (21).html — طلبات الاستشارات وتسعيرها (الإدارة العليا فقط):
// دورة الحجز قبل الجلسة (بانتظار التسعير → بانتظار السداد → بانتظار تحديد الموعد) + تحديد السعر.

const STATUS_TONE: Record<string, string> = {
  'بانتظار التسعير': 'b-amber',
  'بانتظار السداد': 'b-blue',
  'بانتظار تحديد الموعد': 'b-cyan',
};

const AdminConsultRequests: React.FC<{ consults: ConsultCard[] }> = ({ consults }) => {
  const toast = useToast();
  const by = (s: string) => consults.filter((c) => c.status === s).length;

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
          <span className="sub">{consults.length} طلب</span>
        </div>
        <div className="card-b">
          {consults.length ? consults.map((c) => (
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
