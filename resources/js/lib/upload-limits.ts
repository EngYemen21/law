/**
 * **حدود حجم الرفع في الواجهة** — نظير `App\Support\UploadLimits` حرفاً (يحرس التطابق `UploadLimitsTest`).
 * الخادم هو الحَكَم؛ هنا فحصٌ مبكّر ونصٌّ يعلن الحدّ نفسه.
 */

/** مرفقات المحادثة والقضيّة والتذكرة (ميجابايت). */
export const ATTACHMENT_MB = 10;

/** المستند المطلوب وإثبات السداد (ميجابايت). */
export const DOCUMENT_MB = 2;

export const mbToBytes = (mb: number): number => mb * 1024 * 1024;
