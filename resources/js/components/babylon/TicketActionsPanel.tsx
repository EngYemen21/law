import axios from 'axios';
import React, { useState } from 'react';
import CloseTicketModal from '@/components/babylon/CloseTicketModal';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';

interface Props {
  ticketNo: string;
  status: string;
  caseRef?: string | null;
  role: 'employee' | 'lawyer' | 'admin';
  onRequestDocs?: () => void;
  onSchedule?: () => void;
  onTransfer?: () => void;
  onCloseJustified?: () => void;
}

const TicketActionsPanel: React.FC<Props> = ({
  ticketNo,
  status,
  caseRef,
  role,
  onRequestDocs,
  onSchedule,
  onTransfer,
  onCloseJustified,
}) => {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [showCloseModal, setShowCloseModal] = useState(false);
  const mayCloseJustified = (role === 'lawyer' || role === 'admin') && (status === 'مكتملة' || status === 'بانتظار قرار المآل') && !caseRef;

  // تحويل التذكرة إلى طلب استشارة (يطابق convertToConsult المرجعي) — لطاقم المكتب لا للمستشار.
  // كل لوحة تنادي مسارها: للإدارة مسار admin خاص (لم يعد الأدمن يمرّ عبر بوابة الموظف — قرار 2026-08-28)
  const staffOps = role !== 'lawyer';
  // يطابق `TicketJourney::consultRequestBlocker`: بعد نشر الرأي القانونيّ المبدئيّ — كان ظاهراً في كلّ حالة
  const mayRequestConsult = staffOps && ['الرأي القانوني', 'بانتظار حجز الاستشارة'].includes(status);
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

  // لا تُعرض بطاقة فارغة حين تُخفى كل الإجراءات (حالة التذكرة أو صلاحيات المستخدم).
  // `staffOps` لم يعد شرطاً بذاته: كان زرّ «تحويل إلى لائحة» يظهر لكلّ موظّفٍ دائماً فيُبقي
  // البطاقة قائمة، وقد حُذف — فصار الشرط على الإجراءات الفعليّة وحدها.
  const hasAny = mayCloseJustified || mayRequestConsult || onSchedule || onRequestDocs || onTransfer;

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
          <span>أغلق الملف بتسبيب، أو اطلب مستندات من العميل.</span>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {/* زرّان أُزيلا من هنا (2026-09-20، تشخيصٌ من استعمال المالك):
              • «عرض ملف القضية» كان مكرّراً حرفاً — النصّ نفسه والوجهة نفسها في
                TicketTrackDecisionCard المعروض فوق هذه البطاقة مباشرةً، فيظهر زرّان متلاصقان.
                أُبقي في البطاقة لأنّها تعرض نظيره للتنفيذ أيضاً، فتبقى المعالجتان في مكانٍ واحد.
              • «تحويل إلى قضية رسمية» كان يحوّل مباشرةً بلا تسبيبٍ ولا اعتماد إدارة، فيلتفّ على
                حوكمة المسارات الأربعة (ApproveOutcomeTrack) التي تشترط تسبيباً واعتماداً.
                ⚠️ أُخفي من الواجهة بقرار المالك، **ومساره في الخادم باقٍ عامداً** إلى أن يُحذف
                في خطوةٍ مستقلّة — فالاستعمال اليوميّ توقّف والباب لم يُغلق بعد. */}
          {mayCloseJustified && (
            <button
              className="btn soft block"
              type="button"
              onClick={() => (onCloseJustified ? onCloseJustified() : setShowCloseModal(true))}
              disabled={busy}
              style={{ justifyContent: 'center' }}
            >
              <Icon name="check" /> إغلاق مسبب للملف
            </button>
          )}

          {/* 2. تحويل إلى طلب استشارة — يُنشئ طلب تسعير نيابةً عن العميل */}
          {mayRequestConsult && (
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

          {/* «تحويل إلى لائحة» حُذف (2026-09-20، قرار المالك): كان معطَّلاً بلا معالج، ولا مسار
              له في الخادم ولا متحكّم ولا انتقال — عنصرُ عرضٍ مرجعيّ لا يفتح وظيفة. ولائحة
              الدعوى وظيفةٌ قائمة مستقلّة تُدار من شاشة القضية (CasePleading)، لا من هنا. */}

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
      {showCloseModal && (
        <CloseTicketModal
          open={showCloseModal}
          ticketNo={ticketNo}
          role={role}
          onClose={() => setShowCloseModal(false)}
        />
      )}
    </div>
  );
};

export default TicketActionsPanel;
