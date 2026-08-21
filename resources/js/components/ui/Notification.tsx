// ⚠️ غير مستعمل — صفر مستورد في resources/js (تدقيق 2026-08-21). مُحتفَظ به بقرار «لا حذف».
// قبل أي استعمال: تحقّق من عدم وجود نظير حيّ (components/babylon/*) كي لا يتكرّر المكوّن.
import React, { useEffect } from 'react';
import { X, AlertCircle, CheckCircle, Info } from 'lucide-react';
import clsx from 'clsx';

interface NotificationProps {
  type?: 'success' | 'error' | 'warning' | 'info';
  title?: string;
  message: string;
  duration?: number;
  onClose: () => void;
}

const Notification: React.FC<NotificationProps> = ({
  type = 'info',
  title,
  message,
  duration = 5000,
  onClose,
}) => {
  useEffect(() => {
    const timer = setTimeout(onClose, duration);
    return () => clearTimeout(timer);
  }, [duration, onClose]);

  const typeClasses = {
    success: {
      container: 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800',
      icon: 'text-green-600 dark:text-green-400',
      title: 'text-green-900 dark:text-green-300',
      message: 'text-green-800 dark:text-green-200',
    },
    error: {
      container: 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800',
      icon: 'text-red-600 dark:text-red-400',
      title: 'text-red-900 dark:text-red-300',
      message: 'text-red-800 dark:text-red-200',
    },
    warning: {
      container: 'bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800',
      icon: 'text-yellow-600 dark:text-yellow-400',
      title: 'text-yellow-900 dark:text-yellow-300',
      message: 'text-yellow-800 dark:text-yellow-200',
    },
    info: {
      container: 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800',
      icon: 'text-blue-600 dark:text-blue-400',
      title: 'text-blue-900 dark:text-blue-300',
      message: 'text-blue-800 dark:text-blue-200',
    },
  };

  const classes = typeClasses[type];

  const icons = {
    success: <CheckCircle className="w-5 h-5" />,
    error: <AlertCircle className="w-5 h-5" />,
    warning: <AlertCircle className="w-5 h-5" />,
    info: <Info className="w-5 h-5" />,
  };

  return (
    <div
      className={clsx(
        'flex items-start gap-3 p-4 rounded-lg shadow-lg',
        'max-w-sm animate-in fade-in slide-in-from-top-2 duration-300',
        classes.container
      )}
    >
      <div className={clsx('flex-shrink-0', classes.icon)}>
        {icons[type]}
      </div>
      <div className="flex-1 min-w-0">
        {title && (
          <h3 className={clsx('font-semibold text-sm', classes.title)}>
            {title}
          </h3>
        )}
        <p className={clsx('text-sm', classes.message)}>
          {message}
        </p>
      </div>
      <button
        onClick={onClose}
        className={clsx(
          'flex-shrink-0 inline-flex rounded-md',
          'hover:opacity-70 transition-opacity'
        )}
      >
        <X className="w-5 h-5" />
      </button>
    </div>
  );
};

export default Notification;
