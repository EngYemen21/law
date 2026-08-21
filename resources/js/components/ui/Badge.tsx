// ⚠️ غير مستعمل — صفر مستورد في resources/js (تدقيق 2026-08-21). مُحتفَظ به بقرار «لا حذف».
// قبل أي استعمال: تحقّق من عدم وجود نظير حيّ (components/babylon/*) كي لا يتكرّر المكوّن.
import React from 'react';
import clsx from 'clsx';

interface BadgeProps {
  children: React.ReactNode;
  variant?: 'default' | 'success' | 'warning' | 'danger' | 'info';
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

const Badge: React.FC<BadgeProps> = ({
  children,
  variant = 'default',
  size = 'md',
  className,
}) => {
  const variantStyles = {
    default: {
      backgroundColor: '#F6F8FA',
      color: '#0E5C9C',
      borderColor: '#E1E8EE',
    },
    success: {
      backgroundColor: '#E7F6EF',
      color: '#1E9D6B',
      borderColor: '#C8E6D7',
    },
    warning: {
      backgroundColor: '#FBF1E0',
      color: '#C0832B',
      borderColor: '#F5D9B8',
    },
    danger: {
      backgroundColor: '#FBEAE8',
      color: '#C0392B',
      borderColor: '#F5C4C0',
    },
    info: {
      backgroundColor: '#E0F4F9',
      color: '#11A0C8',
      borderColor: '#B3E5FC',
    },
  };

  const sizeClasses = {
    sm: 'px-2 py-1 text-xs font-medium',
    md: 'px-3 py-1.5 text-sm font-medium',
    lg: 'px-4 py-2 text-base font-medium',
  };

  const style = variantStyles[variant];

  return (
    <span
      className={clsx(
        'inline-flex items-center rounded-full whitespace-nowrap border',
        sizeClasses[size],
        className
      )}
      style={style}
    >
      {children}
    </span>
  );
};

export default Badge;
