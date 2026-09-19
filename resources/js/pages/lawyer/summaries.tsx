import { Link, router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import FlowLine from '@/components/babylon/FlowLine';
import { SUM_FLOW, type SummaryData, sumStage } from '@/lib/lawyer-data';

// قائمة ملخصات الملفات الواردة من الفريق القانوني — بيانات حقيقية من الخادم

interface Props { summaries: (SummaryData & { type?: string; client?: string })[]; }

type Tab = 'pending' | 'admin' | 'approved';

const openSummary = (ref: string) =>
  router.visit(`/lawyer/summary/${encodeURIComponent(ref)}`);

const EMPTY: Record<Tab, string> = {
  pending: 'لا ملخصات بانتظار اعتمادك',
  admin: 'لا ملخصات مرفوعة للإدارة',
  approved: 'لا ملخصات معتمدة بعد',
};

const LawyerSummaries: React.FC<Props> = ({ summaries }) => {
  const [tab, setTab] = useState<Tab>('pending');
  // الاعتماد مرحلتان (قرار المالك 2026-09-14): ما اعتمده المحامي ورفعه للإدارة ليس «بانتظار اعتمادي»
  const pend = summaries.filter((s) => !s.approved && !s.lawyerApproved);
  const atAdmin = summaries.filter((s) => !s.approved && s.lawyerApproved);
  const done = summaries.filter((s) => s.approved);
  const list = tab === 'pending' ? pend : tab === 'admin' ? atAdmin : done;

  return (
    <div className="card">
      <div className="card-h">
        <h3>ملخصات الملفات</h3>
        <span className="sub">{list.length} ملخص</span>
      </div>
      <div className="card-b">
        <div className="mtabs">
          <button className={`mtab ${tab === 'pending' ? 'on' : ''}`} onClick={() => setTab('pending')} type="button">
            بانتظار اعتمادي ({pend.length})
          </button>
          <button className={`mtab ${tab === 'admin' ? 'on' : ''}`} onClick={() => setTab('admin')} type="button">
            بانتظار اعتماد الإدارة ({atAdmin.length})
          </button>
          <button className={`mtab ${tab === 'approved' ? 'on' : ''}`} onClick={() => setTab('approved')} type="button">
            المعتمدة ({done.length})
          </button>
        </div>
        {list.length ? list.map((s) => (
          <div key={s.ref} className="item">
            <div className="iico"><Icon name="doc" /></div>
            <div className="imeta">
              <b>ملخص ملف — {s.ref}{s.type ? ` · ${s.type}` : ''}</b>
              <span><FlowLine steps={SUM_FLOW} cur={sumStage(s)} /></span>
            </div>
            <div className="iact" style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <Link
                href={`/lawyer/editor/create?importType=ticket_summary&id=${s.id}`}
                className="btn soft sm"
                style={{
                  height: 30,
                  fontSize: 12,
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 5,
                  textDecoration: 'none',
                  color: '#0e5c9c',
                  background: 'rgba(14, 92, 156, 0.08)',
                  borderColor: 'rgba(14, 92, 156, 0.25)',
                }}
                title="تنسيق وصياغة في المحرر القانوني ⚖️"
              >
                <Icon name="edit" /> تنسيق
              </Link>
              <button className="btn sm" onClick={() => openSummary(s.ref!)} type="button">
                <Icon name="doc" /> فتح الملخص
              </button>
            </div>
          </div>
        )) : (
          <div className="empty"><Icon name="check" /><b>{EMPTY[tab]}</b></div>
        )}
      </div>
    </div>
  );
};

export default LawyerSummaries;
