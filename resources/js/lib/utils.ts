import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

// ⚠️ غير مستعملة — صفر مناد في resources/js (تدقيق 2026-08-21). مُحتفَظ بها بقرار «لا حذف»؛
// المشروع لا يستعمل tailwind-merge في أي مكان آخر.
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

/**
 * اقتطاع النص بعدد محدد من الكلمات مع الحفاظ على الكلمات كاملة دون بتر للأحرف.
 * إذا كان عدد الكلمات أكبر من maxWords، يتم قص الكلمات وإضافة '...'
 *
 * @param text النص المدخل
 * @param maxWords الحد الأقصى لعدد الكلمات
 * @param suffix اللاحقة، افتراضياً '...'
 */
export function truncateWords(text?: string | null, maxWords: number = 8, suffix: string = '...'): string {
  if (!text) return '';
  const trimmed = text.trim();
  if (!trimmed) return '';
  const words = trimmed.split(/\s+/);
  if (words.length <= maxWords) {
    return trimmed;
  }
  return words.slice(0, maxWords).join(' ') + suffix;
}

