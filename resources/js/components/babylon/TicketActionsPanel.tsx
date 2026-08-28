import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useState } from 'react';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

interface Props {
  ticketNo: string;
  status: string;
  caseRef?: string | null;
  role: 'employee' | 'lawyer' | 'admin';
  /** شرط إضافي على الدور: الموظف لا يحوّل قبل اعتماد المحامي للنتيجة.
   *  المحامي هو المعتمِد والإدارة العليا هي الاعتماد النهائي، فكلاهما يمرّ بلا شرط. */
  canConvert?: boolean;
  onRequestDocs?: () => void;
  onSchedule?: () => void;
  onTransfer?: () => void;
}

const TicketActionsPanel: React.FC<Props> = ({
  ticketNo,
  status,
  caseRef,
  role,
  canConvert = true,
  onRequestDocs,
  onSchedule,
  onTransfer,
}) => {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  // الخادم يرفض التحويل قبل الاكتمال — كان الزر يُعرض دائماً ورسالة الرفض تُبتلع.
  // وللموظف شرط ثانٍ: اعتماد المحامي للنتيجة (canConvert) — وإلا عُرض زرّ يُرفض بـ422.
  const mayConvert = status === 'مكتملة' && canConvert;

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
  // كل لوحة تنادي مسارها: للإدارة مسار admin خاص (لم يعد الأدمن يمرّ عبر بوابة الموظف — قرار 2026-08-28)
  const staffOps = role !== 'lawyer';
  const convertToConsult = () => {
    setBusy(true);
    axios.post(`/${role === 'admin' ? 'admin' : 'employee'}/tickets/${encodeURIComponent(ticketNo)}/convert-consult`, {})
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
  const hasAny = caseRef || mayConvert || staffOps || onSchedule || onRequestDocs || onTransfer;

  if (!hasAny) {
return null;
}

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
          ) : mayConvert && (
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

          {/* 3. تحويل إلى لائحة — عنصر عرض مرجعي (بلا حدث، بطلب صاحب المنتج)؛
              disabled كي لا يوهم بمظهر زرّ فعّال يُنقر بلا أثر */}
          {staffOps && (
            <button
              className="btn soft block"
              type="button"
              disabled
              title="مرحلة مرجعية ضمن الرحلة — لا إجراء مباشراً لها"
              style={{ justifyContent: 'center', cursor: 'default', opacity: 0.7 }}
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

          {/* 6. تحويل لمستشار آخر */}
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
