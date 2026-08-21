import React from 'react';
import { type ConsultCard, ConsultsListPage } from '@/lib/consult-ui';

// جلسات استشارات المحامي (المسندة إليه) — نظير employee/consults وadmin/consults.
// كان Staff\ConsultController::index يصيّر «lawyer/consults» بلا مسار ولا ملف صفحة،
// وزرّ «رجوع للاستشارات» في رحلة الاستشارة يقود إلى 404.

const LawyerConsults: React.FC<{ consults: ConsultCard[] }> = ({ consults }) => (
  <ConsultsListPage consults={consults} base="/lawyer" />
);

export default LawyerConsults;
