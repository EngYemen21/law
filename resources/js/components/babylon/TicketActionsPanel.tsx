import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

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
  ticketNo,
  status,
  caseRef,
  role,
  onRequestDocs,
  onSchedule,
  onTransfer,
}) => {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  // الخادم يرفض التحويل قبل الاكتمال — كان الزر يُعرض دائماً ورسالة الرفض تُبتلع
  const canConvert = status === 'مكتملة';

  const convertToCase = () => {
    setBusy(true);
    const endpoint = `/${role}/tickets/${encodeURIComponent(ticketNo)}/convert`;

    router.post(endpoint, {}, {
      onSuccess: () => toast('✅ تم تحويل التذكرة إلى قضية بنجاح'),
      // أخطاء Inertia كائن مفاتيحه أسماء الحقول (ticket) — err.message لا وجود له
      onError: (errors) => toast(`⚠️ ${Object.values(errors)[0] ?? 'تعذّر تحويل التذكرة لقضية (تأكد من اعتماد النتيجة)'}`),
      onFinish: () => setBusy(false),
    });
  };

  // تحويل التذكرة إلى طلب استشارة (يطابق convertToConsult المرجعي) — لطاقم المكتب لا للمستشار.
  // المسار خادميّ تحت لوحة الموظف؛ الإدارة تمرّ عبره (حارس الدور والفرع يستثنيانها).
  const staffOps = role !== 'lawyer';
  const convertToConsult = () => {
    setBusy(true);
    axios.post(`/employee/tickets/${encodeURIComponent(ticketNo)}/convert-consult`, {})
      .then(() => toast('✅ حُوّلت التذكرة إلى طلب استشارة وأُرسلت للتسعير'))
      .catch((err) => {
        const errors = err.response?.data?.errors;
        const msg = (errors && (Object.values(errors)[0] as string[])[0])
          || err.response?.data?.message || 'تعذّر تحويل التذكرة إلى استشارة';
        toast(`⚠️ ${msg}`);
      })
      .finally(() => setBusy(false));
  };

  // لا تُعرض بطاقة فارغة حين تُخفى كل الإجراءات (حالة التذكرة أو صلاحيات المستخدم)
  const hasAny = caseRef || canConvert || staffOps || onSchedule || onRequestDocs || onTransfer;
  if (!hasAny) return null;

  return (
    <div className="card">
      <div className="card-h">
        <h3>خيارات التذكرة</h3>
      </div>
      <div className="card-b" style={{ padding: '14px 16px' }}>
        <div className="action-hint">
          <Icon name="info" />
          <span>حوّل التذكرة إلى قضية أو اطلب مستندات من العميل.</span>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {/* 1. تحويل إلى قضية — بعد التحويل يصير رابطاً لملف القضية */}
          {caseRef ? (
            <Link
              href={`/${role}/cases`}
              className="btn soft block"
              style={{ justifyContent: 'center' }}
            >
              <Icon name="scale" /> عرض ملف القضية ({caseRef})
            </Link>
          ) : canConvert && (
            <button
              className="btn block"
              type="button"
              onClick={convertToCase}
              disabled={busy}
              style={{ justifyContent: 'center' }}
            >
              <Icon name="scale" /> تحويل إلى قضية رسمية
            </button>
          )}

          {/* 2. تحويل إلى طلب استشارة — يُنشئ طلب تسعير نيابةً عن العميل */}
          {staffOps && (
            <button
              className="btn soft block"
              type="button"
              onClick={convertToConsult}
              disabled={busy}
              style={{ justifyContent: 'center' }}
            >
              <Icon name="calplus" /> تحويل التذكرة إلى استشارة
            </button>
          )}

          {/* 3. تحويل إلى لائحة — عنصر عرض مرجعي (بلا حدث، بطلب صاحب المنتج) */}
          {staffOps && (
            <button
              className="btn soft block"
              type="button"
              style={{ justifyContent: 'center' }}
            >
              <Icon name="doc" /> تحويل إلى لائحة
            </button>
          )}

          {/* 4. جدولة موعد استشارة */}
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

          {/* 5. طلب نواقص المستندات */}
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

          {/* 6. تحويل لموظف / فرع آخر */}
          {onTransfer && (
            <button
              className="btn soft block"
              type="button"
              onClick={onTransfer}
              style={{ justifyContent: 'center' }}
            >
              <Icon name="reply" /> تحويل التذكرة لمستشار/فرع
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

export default TicketActionsPanel;
