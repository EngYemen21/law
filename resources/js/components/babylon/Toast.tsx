import React, { createContext, useCallback, useContext, useRef, useState } from 'react';
import Icon from '@/lib/icons';

// يطابق toast() في index (82).html

interface ToastItem { id: number; msg: string; leaving?: boolean; }

const ToastCtx = createContext<(msg: string) => void>(() => {});

export const useToast = () => useContext(ToastCtx);

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [items, setItems] = useState<ToastItem[]>([]);
  const seq = React.useRef(0);

  const toast = useCallback((msg: string) => {
    const id = ++seq.current;
    setItems((prev) => [...prev, { id, msg }]);
    // يختفي بعد 2600ms مع تلاشٍ 300ms (مطابق للأصل)
    setTimeout(() => {
      setItems((prev) => prev.map((t) => (t.id === id ? { ...t, leaving: true } : t)));
      setTimeout(() => {
        setItems((prev) => prev.filter((t) => t.id !== id));
      }, 300);
    }, 2600);
  }, []);

  return (
    <ToastCtx.Provider value={toast}>
      {children}
      <div className="toast-wrap" id="toasts">
        {items.map((t) => (
          <div
            key={t.id}
            className="toast"
            style={{ opacity: t.leaving ? 0 : 1, transition: 'opacity .3s' }}
          >
            <Icon name="check" />
            <span>{t.msg}</span>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
};

export default ToastProvider;
