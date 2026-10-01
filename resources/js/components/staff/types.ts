import type { Staff } from '@/lib/employee-data';

/** أنواع الأجر من الخادم (`App\Enums\PayType`). */
export type PayType = 'salary' | 'pct' | 'both' | 'session';

/** صفّ الموظّف كما يصل من `User::staffCard()`. */
export type StaffRow = Staff & {
  id: number;
  roleKey?: string;
  payType?: PayType | null;
  pct?: number | null;
  sessionFee?: number | null;
  specialtyIds?: number[]; // تخصّصات المحامي في كتالوج الأقسام
  coversAll?: boolean; // محامٍ عامّ يغطّي كلّ الأقسام
  active?: boolean; // علَم الحالة من الخادم — `status` تسميتها للعرض
};

export interface LegalDepartmentOption { id: number; name: string }

export interface PayTypeOption { id: PayType; label: string; lawyerOnly: boolean }

/** فلاتر جدول الكادر — حالتها في الصفحة فتبقى عند التنقّل بين التبويبين. */
export interface StaffFilters {
  search: string;
  role: string;
  dept: string;
  status: string;
}
