import React from 'react';
import clsx from 'clsx';

interface CardProps {
  children: React.ReactNode;
  className?: string;
  hoverable?: boolean;
  padding?: 'sm' | 'md' | 'lg';
}

const Card: React.FC<CardProps> = ({
  children,
  className,
  hoverable = false,
  padding = 'md',
}) => {
  const paddingClasses = {
    sm: 'p-4',
    md: 'p-6',
    lg: 'p-8',
  };

  return (
    <div
      className={clsx(
        'rounded-lg border transition-all duration-200',
        hoverable && 'cursor-pointer hover:border-cyan hover:shadow-lg hover:-translate-y-0.5',
        paddingClasses[padding],
        className
      )}
      style={{
        backgroundColor: '#FFFFFF',
        borderColor: '#E1E8EE',
        boxShadow: hoverable
          ? '0 1px 2px rgba(10,42,85,.05),0 8px 28px -14px rgba(10,42,85,.16)'
          : 'none',
      }}
    >
      {children}
    </div>
  );
};

export default Card;
