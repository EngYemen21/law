// ثوابت وأنواع المخاطبات الرسميّة (تطابق CorrFlow في الخادم).

export const CORR_FLOW = [
  'إنشاء المخاطبة', 'المراجعة القانونية', 'اعتماد الإدارة',
  'الإرسال للجهة', 'بانتظار الرد', 'ورود الرد', 'الإغلاق والأرشفة',
];

export const CLIENT_CORR_FLOW = [
  'إعداد المخاطبة في المكتب', 'الإرسال للجهة', 'بانتظار رد الجهة', 'ورود الرد', 'إفادتك بالنتيجة',
];

export interface CorrAudit { a: string; by: string; t: string }

// بطاقة المكتب (محامي/إدارة) — تطابق Correspondence::toCard
export interface CorrCard {
  id: string; dir: string; entity: string; subject: string; channel: string; ref: string;
  client: string; lawyer: string; stage: number; status: string; tone: string;
  date: string; due: string; body: string;
  extRef: string; extStatus: string; extSync: string; reply: string;
  briefed: boolean; briefNote: string; briefReq: boolean;
  execRef: string | null; channelName: string; audit: CorrAudit[];
}

// بطاقة العميل — تطابق Correspondence::toClientCard
export interface ClientCorrCard {
  id: string; dir: string; entity: string; subject: string; date: string;
  clientStage: number; briefed: boolean; briefNote: string; briefReq: boolean;
  reply: string; channelName: string;
}

// لون حدّ البطاقة حسب المرحلة (تطابق corrTone)
export function corrColor(stage: number): string {
  return stage >= 6 ? '#607689' : stage >= 4 ? '#C0832B' : stage >= 2 ? '#0E5C9C' : '#8895a7';
}

export function corrTone(stage: number): string {
  return stage >= 6 ? 'b-green' : stage >= 4 ? 'b-amber' : stage >= 2 ? 'b-blue' : 'b-grey';
}
