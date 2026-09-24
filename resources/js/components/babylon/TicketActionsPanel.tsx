import React from 'react';
import Icon from '@/lib/icons';

interface Props {
  ticketNo: string;
  status: string;
  caseRef?: string | null;
  role: 'employee' | 'lawyer' | 'admin';
  onRequestDocs?: () => void;
  onSchedule?: () => void;
  onTransfer?: () => void;
}

const TicketActionsPanel: React.FC<Props> = ({
  onRequestDocs,
  onSchedule,
  onTransfer,
}) => {
  // لا تُعرض بطاقة فارغة حين تُخفى كل الإجراءات (حالة التذكرة أو صلاحيات المستخدم).
  // ملاحظة معمارية (ADR-009 / ADR-010): تقرير مآل التذكرة (طلب استشارة، قضية، تنفيذ، إغلاق مسبب)
  // يمر حصراً وبشكل قطعي عبر بطاقة حوكمة المسارات الأربعة (TicketTrackDecisionCard) ومسار ApproveOutcomeTrack
  // لمنع الازدواجية والالتفاف على قرارات واعتماد الإدارة والتسبيب النظامي.
  const hasAny = onSchedule || onRequestDocs || onTransfer;

  if (!hasAny) {
    return null;
  }

  return (
    <div className="card">
      <div className="card-h">
        <h3>خيارات التذكرة التشغيلية</h3>
      </div>
      <div className="card-b" style={{ padding: '14px 16px' }}>
        <div className="action-hint">
          <Icon name="info" />
          <span>جدول موعد استشارة، اطلب نواقص مستندات، أو حوّل التذكرة لمستشار.</span>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {/* جدولة موعد استشارة للمستشار/الموظف */}
          {onSchedule && (
            <button
              className="btn soft block"
              type="button"
              onClick={onSchedule}
              style={{ justifyContent: 'center' }}
            >
              <Icon name="cal" /> حجز وجدولة استشارة
            </button>
          )}

          {/* طلب نواقص المستندات */}
          {onRequestDocs && (
            <button
              className="btn soft block"
              type="button"
              onClick={onRequestDocs}
              style={{ justifyContent: 'center' }}
            >
              <Icon name="upload" /> طلب نواقص ومستندات
            </button>
          )}

          {/* تحويل لمستشار آخر */}
          {onTransfer && (
            <button
              className="btn soft block"
              type="button"
              onClick={onTransfer}
              style={{ justifyContent: 'center' }}
            >
              <Icon name="reply" /> تحويل التذكرة لمستشار
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

export default TicketActionsPanel;
