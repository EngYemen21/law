// ⚠️ غير مستعمل — صفر مستورد في resources/js (تدقيق 2026-08-21). مُحتفَظ به بقرار «لا حذف».
// قبل أي استعمال: تحقّق من عدم وجود نظير حيّ (components/babylon/*) كي لا يتكرّر المكوّن.
import React from 'react';
import clsx from 'clsx';

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'soft';
  size?: 'sm' | 'md' | 'lg';
  isLoading?: boolean;
  children: React.ReactNode;
  icon?: React.ReactNode;
  iconPosition?: 'left' | 'right';
}

const Button: React.FC<ButtonProps> = ({
  variant = 'primary',
  size = 'md',
  isLoading = false,
  children,
  icon,
  iconPosition = 'right',
  className,
  disabled,
  ...props
}) => {
  const variantStyles = {
    primary: {
      backgroundColor: '#0E5C9C',
      color: '#FFFFFF',
      hoverBg: '#0A487C',
      border: 'none',
    },
    secondary: {
      backgroundColor: '#F6F8FA',
      color: '#13314F',
      hoverBg: '#EDF2F6',
      border: '1px solid #E1E8EE',
    },
    danger: {
      backgroundColor: '#C0392B',
      color: '#FFFFFF',
      hoverBg: '#A02D23',
      border: 'none',
    },
    soft: {
      backgroundColor: 'transparent',
      color: '#13314F',
      hoverBg: '#F6F8FA',
      border: '1px solid #E1E8EE',
    },
  };

  const sizeClasses = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2 text-base',
    lg: 'px-6 py-3 text-lg',
  };

  const style = variantStyles[variant];

  return (
    <button
      className={clsx(
        'inline-flex items-center justify-center gap-2',
        'font-medium rounded-lg transition-all duration-200',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        'hover:-translate-y-0.5 active:translate-y-0',
        sizeClasses[size],
        className
      )}
      style={{
        backgroundColor: disabled ? style.backgroundColor : style.backgroundColor,
        color: style.color,
        border: style.border,
        boxShadow: variant === 'primary' && !disabled
          ? '0 1px 2px rgba(10,42,85,.05)'
          : 'none',
      }}
      onMouseEnter={(e) => {
        if (!disabled) {
          e.currentTarget.style.backgroundColor = style.hoverBg;
        }
      }}
      onMouseLeave={(e) => {
        e.currentTarget.style.backgroundColor = style.backgroundColor;
      }}
      disabled={disabled || isLoading}
      {...props}
    >
      {isLoading ? (
        <span className="inline-flex animate-spin">⏳</span>
      ) : (
        <>
          {icon && iconPosition === 'left' && <span className="inline-flex">{icon}</span>}
          {children}
          {icon && iconPosition === 'right' && <span className="inline-flex">{icon}</span>}
        </>
      )}
    </button>
  );
};

export default Button;
