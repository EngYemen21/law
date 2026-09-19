import React, { createContext, useCallback, useContext, useRef, useState } from 'react';
import Icon from '@/lib/icons';

// نظام التنبيهات المنبثقة (Toast) في منصة بابل — يدعم رسائل النجاح، التحذير، والرفض الإداري/الأخطاء
export type ToastTone = 'success' | 'error' | 'warning' | 'info';

interface ToastItem {
  id: number;
  msg: string;
  tone?: ToastTone;
  leaving?: boolean;
}

const ToastCtx = createContext<(msg: string, tone?: ToastTone) => void>(() => {});

export const useToast = () => useContext(ToastCtx);

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [items, setItems] = useState<ToastItem[]>([]);
  const seq = useRef(0);

  const toast = useCallback((msg: string, tone?: ToastTone) => {
    const id = ++seq.current;
    setItems((prev) => [...prev, { id, msg, tone }]);
    // يختفي بعد 3000ms مع تلاشٍ 300ms لتمكين المستخدم من قراءة التنبيه كاملاً
    setTimeout(() => {
      setItems((prev) => prev.map((t) => (t.id === id ? { ...t, leaving: true } : t)));
      setTimeout(() => {
        setItems((prev) => prev.filter((t) => t.id !== id));
      }, 300);
    }, 3000);
  }, []);

  return (
    <ToastCtx.Provider value={toast}>
      {children}
      <div className="toast-wrap" id="toasts">
        {items.map((t) => {
          const isError =
            t.tone === 'error' ||
            (!t.tone && /خطأ|تعذّر|فشل|لا يمكن|مرفوض|مغلق|مؤرشف|مجمّد|منتهٍ|أُلغيت|معطل|غير متاح|غير مسموح|ممنوع/.test(t.msg));
          const isWarning = t.tone === 'warning';
          const iconName = isError || isWarning ? 'alert' : 'check';
          const iconStroke = isError
            ? '#FF6B6B'
            : isWarning
            ? '#F59E0B'
            : 'var(--cyan-bright, #46C0E4)';
          const borderColor = isError
            ? 'rgba(239, 68, 68, 0.45)'
            : isWarning
            ? 'rgba(245, 158, 11, 0.45)'
            : 'rgba(70, 192, 228, 0.2)';

          return (
            <div
              key={t.id}
              className={`toast ${isError ? 'toast-error' : isWarning ? 'toast-warning' : 'toast-success'}`}
              style={{
                opacity: t.leaving ? 0 : 1,
                transition: 'opacity .3s',
                border: `1px solid ${borderColor}`,
              }}
            >
              <Icon name={iconName} style={{ stroke: iconStroke }} />
              <span>{t.msg}</span>
            </div>
          );
        })}
      </div>
    </ToastCtx.Provider>
  );
};

export default ToastProvider;

