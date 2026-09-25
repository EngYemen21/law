import React, { useEffect, useLayoutEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/lib/icons';

// يطابق هيكل #modal (modal-bg/modal/modal-head/modal-body) + openModal/closeModal
// يُرسم عبر Portal على document.body حتى لا يُحبس داخل حاويات transform/overflow (مثل أنيميشن .view)

interface ModalProps {
  title: string;
  subtitle?: string;
  badge?: React.ReactNode;
  open: boolean;
  onClose: () => void;
  maxWidth?: number | string;
  children: React.ReactNode;
}

// عدّاد مشترك: مودالات وأدراج عدة قد تتراكب، والقفل يُرفع فقط عند إغلاق آخرها.
// «القيمة السابقة» غير آمنة هنا — درج يُغلق بعد مودال كان يعيد 'hidden' فيجمّد الصفحة.
let openOverlays = 0;
let originalPaddingRight = '';

const useIsomorphicLayoutEffect = typeof window !== 'undefined' ? useLayoutEffect : useEffect;

/** قفل تمرير الصفحة أثناء تراكب (مودال/درج) — يشارك العدّاد مع Modal فلا تسابق بين الطبقات ويمنع اهتزاز الشاشة */
export function useBodyScrollLock(active: boolean): void {
  useIsomorphicLayoutEffect(() => {
    if (!active) {
      return;
    }

    if (openOverlays === 0) {
      const hasScrollbar = window.innerWidth > document.documentElement.clientWidth;
      const supportsGutter = typeof CSS !== 'undefined' && CSS.supports && CSS.supports('scrollbar-gutter', 'stable');

      if (hasScrollbar && !supportsGutter) {
        const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
        originalPaddingRight = document.body.style.paddingInlineEnd || '';
        document.body.style.paddingInlineEnd = `${scrollbarWidth}px`;
      }
      document.body.style.overflow = 'hidden';
    }

    openOverlays += 1;

    return () => {
      openOverlays -= 1;

      if (openOverlays <= 0) {
        openOverlays = 0;
        document.body.style.overflow = '';
        if (originalPaddingRight !== '') {
          document.body.style.paddingInlineEnd = originalPaddingRight;
          originalPaddingRight = '';
        } else {
          document.body.style.paddingInlineEnd = '';
        }
      }
    };
  }, [active]);
}

/*
 * **مفتاحُ الهروب يُغلق طبقةً واحدة — العليا.**
 *
 * كان كلُّ درجٍ وكلُّ نافذة يسجّل مستمعَه الخاصّ على `document`، فضغطةٌ واحدة تصل
 * الجميع: نافذةُ تأكيدٍ فوق درج الاستشارة تُغلَق **ويُغلَق الدرجُ تحتها معها**، فيفقد
 * المستخدم موضعه ويعيد فتح الملفّ من الجدول. عولج مرّةً في درجٍ واحد بفحص
 * `.modal-bg.show`، وبقيت الأدراج الأربعة الأخرى على العطل.
 *
 * فالمكدّس هنا واحدٌ لكلّ الطبقات: ما يُفتح يُدفع، وما يُغلق يُسحب، والمستمعُ الوحيد
 * يستدعي أعلاها فقط. وترتيبُ الفتح هو ترتيبُ الظهور — النافذة تُفتح فوق الدرج بعده.
 */
const escapeLayers: Array<{ close: () => void }> = [];

function onEscapeKey(e: KeyboardEvent): void {
  if (e.key !== 'Escape') {
    return;
  }

  const top = escapeLayers[escapeLayers.length - 1];

  if (top) {
    e.preventDefault();
    top.close();
  }
}

/** يسجّل طبقةً تُغلَق بمفتاح الهروب ما دامت `active` — الطبقةُ العليا وحدها تستجيب. */
export function useEscapeLayer(active: boolean, onClose: () => void): void {
  // أحدثُ دالّةِ إغلاق في مرجع: تتغيّر كلَّ تصيير، ولا يجوز أن يُعاد ترتيب الطبقة لأجلها
  const latest = useRef(onClose);

  useIsomorphicLayoutEffect(() => {
    latest.current = onClose;
  });

  useEffect(() => {
    if (!active) {
      return;
    }

    const layer = { close: () => latest.current() };
    escapeLayers.push(layer);

    if (escapeLayers.length === 1) {
      document.addEventListener('keydown', onEscapeKey);
    }

    return () => {
      const at = escapeLayers.lastIndexOf(layer);

      if (at !== -1) {
        escapeLayers.splice(at, 1);
      }

      if (escapeLayers.length === 0) {
        document.removeEventListener('keydown', onEscapeKey);
      }
    };
  }, [active]);
}

const Modal: React.FC<ModalProps> = ({ title, subtitle, badge, open, onClose, maxWidth, children }) => {
  useBodyScrollLock(open);
  useEscapeLayer(open, onClose);

  return createPortal(
    <div className={`modal-bg${open ? ' show' : ''}`} onClick={onClose}>
      <div
        className="modal"
        role="dialog"
        aria-modal="true"
        style={maxWidth ? { maxWidth: typeof maxWidth === 'number' ? `${maxWidth}px` : maxWidth } : undefined}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="modal-head">
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <h3>{title}</h3>
              {badge}
            </div>
            {subtitle && <p style={{ margin: '3px 0 0', fontSize: 12, color: 'var(--muted)' }}>{subtitle}</p>}
          </div>
          <button className="x" onClick={onClose} type="button" aria-label="إغلاق">
            <Icon name="close" />
          </button>
        </div>
        <div className="modal-body">{open ? children : null}</div>
      </div>
    </div>,
    document.body,
  );
};

export default Modal;
