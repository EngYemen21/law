import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

// يطابق maskLawyer في الأصل — تشفير اسم المحامي لدور العميل
export function maskLawyer(name?: string): string {
  if (!name || name === '—') return name || '—';
  const core = name.replace(/^أ\.?\s*/, '');
  const parts = core.trim().split(/\s+/);
  const f = parts[0] || '';
  const masked = (f.slice(0, 1) || '') + '••••' + (f.slice(-1) || '');
  return 'أ. ' + masked + (parts[1] ? ' ' + (parts[1].slice(0, 1) + '•••') : '') + ' (مشفّر)';
}
