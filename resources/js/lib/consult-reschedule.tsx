import { router } from '@inertiajs/react';
import React, { useRef, useState } from 'react';
import { usePrompt } from '@/components/babylon/ConfirmDialog';
import RescheduleDialog from '@/components/babylon/RescheduleDialog';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { firstError } from '@/lib/server-message';

/**
 * أقلّ ما يلزم لإعادة الجدولة — تفي به بطاقة الاستشارة كما هي، وبطاقة الموعد في لوحة المواعيد
 * بحقول استشارتها المرافقة.
 */
export interface RescheduleTarget {
  id: number;
  ref: string;
  channel?: string;
  rescheduleCount?: number;
}

/**
 * **إعادة جدولة استشارة — مسارٌ واحد لكلّ الشاشات.**
 *
 * كانت أربعُ شاشات (درج المحامي · استقبال الإدارة · المكوّن المشترك · جدول الموظّف) تبني
 * طلبها ونصّها بنفسها، فتباينت: اثنتان بلا تأكيد، وثلاثٌ تقول للمستخدم «يُطلب من العميل
 * اختيار موعد» والعميل لا يختاره. هنا يُبنى الطلب مرّةً، والنصّ مرّةً، والنافذة مرّةً.
 *
 *     const reschedule = useConsultReschedule('/lawyer');
 *     <button onClick={() => reschedule.open(c, closeDrawer)}>إعادة الجدولة</button>
 *     {reschedule.dialog}
 *
 * @param base بادئة الدور: `/lawyer` · `/employee` · `/admin`
 */
export function useConsultReschedule(base: string): { open: (consult: RescheduleTarget, onDone?: () => void) => void; dialog: React.ReactNode } {
  const toast = useToast();
  const [target, setTarget] = useState<RescheduleTarget | null>(null);
  const done = useRef<(() => void) | undefined>(undefined);

  const open = (consult: RescheduleTarget, onDone?: () => void) => {
    done.current = onDone;
    setTarget(consult);
  };

  const dialog = target ? (
    <RescheduleDialog
      open
      domain="consult"
      title={`إعادة جدولة الاستشارة ${target.ref}`}
      count={target.rescheduleCount ?? 0}
      consequence={
        <>
          يُلغى الموعد الحاليّ{target.channel === 'مرئية' ? ' واجتماع Zoom' : ''}، وتعود الاستشارة إلى «بانتظار تحديد الموعد».
          ويحجز المكتب موعداً جديداً، ويُبلَّغ العميل بإشعارٍ وبريد — <b>العميل لا يختار الموعد بنفسه</b>، وسداده محفوظ.
        </>
      }
      onClose={() => setTarget(null)}
      onSubmit={(choice) =>
        new Promise<void>((resolve) => {
          router.post(`${base}/consults/${target.id}/reschedule`, { ...choice }, {
            preserveScroll: true,
            onSuccess: () => {
              toast('أُلغي الموعد — نُبِّه طاقم الحجز لتحديد موعدٍ جديد، وأُبلغ العميل', 'success');
              setTarget(null);
              done.current?.();
            },
            onError: (errors) => toast(firstError(errors, 'تعذّرت إعادة الجدولة'), 'error'),
            onFinish: () => resolve(),
          });
        })
      }
    />
  ) : null;

  return { open, dialog };
}

/**
 * **طلبُ العميل تغيير موعده — كما يراه الطاقم، بفعلَيه.**
 *
 * كان الطلب إشعاراً عابراً للإدارة، لا أثرَ له على الاستشارة: من فاته الإشعار لا يعلم أنّ
 * عميلاً ينتظر ردّاً. صار حالةً معلّقة تظهر على الاستشارة حتى تُقضى بأحد فعلَين: إعادة
 * الجدولة (فتمحوه)، أو الرفض بسببٍ يصل العميل نصّاً.
 */
export const RescheduleRequestNotice: React.FC<{
  consult: RescheduleTarget & { clientRescheduleRequest?: { at: string; note: string | null } | null; canReschedule?: boolean };
  base: string;
  onReschedule: () => void;
}> = ({ consult, base, onReschedule }) => {
  const toast = useToast();
  const askFor = usePrompt();
  const request = consult.clientRescheduleRequest;

  if (!request) {
    return null;
  }

  const decline = async () => {
    const reason = await askFor({
      title: 'رفض طلب تغيير الموعد',
      message: 'يصل العميلَ سبب الرفض نصّاً، ويبقى موعده الحاليّ قائماً.',
      label: 'سبب الرفض',
      placeholder: 'مثلاً: لا يتوفّر موعدٌ آخر قبل انتهاء المهلة النظاميّة',
      confirmLabel: 'رفض الطلب',
    });

    if (!reason) {
      return;
    }

    router.post(`${base}/consults/${consult.id}/reschedule-request/dismiss`, { reason }, {
      preserveScroll: true,
      onSuccess: () => toast('رُفض الطلب وأُبلغ العميل بسببه', 'success'),
      onError: (errors) => toast(firstError(errors, 'تعذّر رفض الطلب'), 'error'),
    });
  };

  return (
    // عرضٌ كامل: تُرسم غالباً داخل صفّ أزرارٍ ملتفّ، فلا تنضغط بجوارها
    <div className="action-hint" style={{ display: 'block', flexBasis: '100%', width: '100%', borderColor: 'var(--amber, #F59E0B)', marginBottom: 10 }}>
      <div style={{ fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
        <Icon name="cal" /> العميل طلب تغيير الموعد
      </div>
      {request.note && <div style={{ marginBottom: 8 }}>«{request.note}»</div>}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {consult.canReschedule !== false && (
          <button type="button" className="btn sm" onClick={onReschedule}>
            <Icon name="cal" /> إعادة الجدولة
          </button>
        )}
        <button type="button" className="btn soft sm" onClick={decline}>
          رفض الطلب
        </button>
      </div>
    </div>
  );
};
