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


/**
 * مدّةٌ بالدقائق مصوغةً بوحدةٍ يقرؤها الإنسان.
 *
 * **لماذا دالّة مشتركة؟** كانت بطاقة «متوسط انتظار الطلبات المفتوحة» تعرض «14325 دقيقة» —
 * عشرة أيّامٍ معروضةً بالدقائق، رقمٌ لا يقرؤه أحد (رُصد 2026-09-25). وإصلاح البطاقة وحدها
 * يترك الباب مفتوحاً لكلّ مؤشّرٍ قادم، فالصياغة في موضعٍ واحد تستعمله الشاشات كلّها.
 *
 * تُختار أكبر وحدةٍ ذات معنى، وتُذكر التي تليها إن كانت لها بقيّة — «٩ أيام و٩ ساعات» أوضح
 * من «٩ أيام» وأدقّ من «14325 دقيقة».
 *
 * @param minutes الدقائق — `null` أو سالبٌ يعطي `null` كي يعرض المنادي «—»
 */
export function humanDuration(minutes?: number | null): string | null {
  if (minutes == null || !Number.isFinite(minutes) || minutes < 0) return null;

  const mins = Math.round(minutes);
  if (mins < 60) return `${mins} دقيقة`;

  const hours = Math.floor(mins / 60);
  const restMins = mins % 60;
  if (hours < 24) {
    return restMins ? `${hours} ساعة و${restMins} دقيقة` : `${hours} ساعة`;
  }

  const days = Math.floor(hours / 24);
  const restHours = hours % 24;
  return restHours ? `${days} يوم و${restHours} ساعة` : `${days} يوم`;
}
