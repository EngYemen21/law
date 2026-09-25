import { router } from '@inertiajs/react';
import React, { useRef, useState } from 'react';
import RescheduleDialog from '@/components/babylon/RescheduleDialog';
import { useToast } from '@/components/babylon/Toast';

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
            onError: (errors) => toast(String(Object.values(errors)[0] ?? 'تعذّرت إعادة الجدولة'), 'error'),
            onFinish: () => resolve(),
          });
        })
      }
    />
  ) : null;

  return { open, dialog };
}
