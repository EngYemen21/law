import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useToast } from '@/components/babylon/Toast';

/**
 * **ما يقوله الخادم يصل الشاشة — من موضعٍ واحد لكلّ الصفحات.**
 *
 * الخادم يقول بثلاث طرق (`App\Support\ErrorResponse`):
 * - `flash.error` / `flash.success` مع تحويل — صفحةٌ رُفضت أو إجراءٌ نجح.
 * - `errors.message` مع العودة — فعلٌ رُفض. كان يُعرض فقط حيث كتب الزرّ `onError`، وأفعالٌ كثيرة
 *   بلا معالج فيُبتلع الرفض: لا تنفيذ ولا سبب.
 * - ردٌّ ليس صفحة Inertia (JSON أو HTML) — كانت Inertia تعرضه نافذةً تحمل صفحة الخطأ الخام.
 *
 * وكان إشعار `flash` في تخطيط اللوحة وحده، فصفحة الدخول لا تقول «الحساب موقوف» وقد حُوّل إليها
 * بالسبب. هذا المكوّن خارج `<App>` (كـ`RoomDock`) فيعمل مع كلّ صفحةٍ وتخطيط.
 *
 * التكرار: معالج `onError` في الزرّ يعرض النصّ نفسه — والإشعار يُسقط المكرّر ما دام ظاهراً (`Toast.tsx`).
 */
type Flash = { error?: string | null; success?: string | null; id?: string | null };
type Props = { flash?: Flash; errors?: Record<string, string> };

/** رسالة ردٍّ ليس صفحة: `message` بالعربيّة من الخادم، أو ما تحمله صفحة الخطأ الساكنة. */
function messageOf(data: unknown, status: number): string {
  if (data && typeof data === 'object' && typeof (data as { message?: unknown }).message === 'string') {
    const message = (data as { message: string }).message;

    if (/[؀-ۿ]/.test(message)) {
      return message;
    }
  }

  // الصفحة الساكنة (`resources/views/errors/static.blade.php`) تحمل النصّ في سمةٍ — لا نسخة هنا
  if (typeof data === 'string') {
    const found = /data-error-message="([^"]+)"/.exec(data);

    if (found) {
      const decoder = document.createElement('textarea');
      decoder.innerHTML = found[1];

      return decoder.value;
    }
  }

  return `تعذّر إكمال الطلب (رمز ${status}) — حدّث الصفحة وأعد المحاولة.`;
}

export function ServerFeedback({ initialPage }: { initialPage: { props: unknown } }) {
  const toast = useToast();
  const shownFlash = useRef<string | null>(null);

  useEffect(() => {
    const showFlash = (props: Props) => {
      const flash = props.flash;

      // مرّةً لكلّ ردٍّ حمل الرسالة — الهويّة من الخادم (`HandleInertiaRequests`)
      if (!flash?.id || flash.id === shownFlash.current) {
        return;
      }

      shownFlash.current = flash.id;

      if (flash.error) {
        toast(flash.error, 'error');
      }

      if (flash.success) {
        toast(flash.success, 'success');
      }
    };

    const initial = initialPage.props as Props;
    showFlash(initial);

    // تحويلٌ عاديّ (نشرٌ بلا Inertia) يحمل الرفض في أوّل صفحة
    if (initial.errors?.message) {
      toast(initial.errors.message, 'error');
    }

    const offSuccess = router.on('success', (event) => showFlash(event.detail.page.props as Props));

    const offError = router.on('error', (event) => {
      const message = (event.detail.errors as Record<string, string>).message;

      if (message) {
        toast(message, 'error');
      }
    });

    const offHttp = router.on('httpException', (event) => {
      const response = event.detail.response;

      // صفحة Inertia بالرمز (صفحة الخطأ العربيّة ٤٠٤) تُعرض كما هي
      if (response.headers?.['x-inertia']) {
        return;
      }

      // عطل الخادم في التطوير: نافذة التتبّع هي ما يحتاجه المطوّر
      if (import.meta.env.DEV && response.status >= 500) {
        return;
      }

      event.preventDefault();
      toast(messageOf(response.data, response.status), 'error');
    });

    const offNetwork = router.on('networkError', () => {
      toast('تعذّر الاتصال بالخادم — تحقّق من الاتصال وأعد المحاولة.', 'error');
    });

    return () => {
      offSuccess();
      offError();
      offHttp();
      offNetwork();
    };
  }, [initialPage, toast]);

  return null;
}

export default ServerFeedback;
