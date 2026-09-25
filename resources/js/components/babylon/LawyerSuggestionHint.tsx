import React from 'react';
import Badge from '@/components/babylon/Badge';

/**
 * اقتراح النظام لمحامي التذكرة كما يصوغه الخادم (`LawyerSuggestion::toArray`).
 * اقتراحٌ يؤكّده إنسان لا إسناد (قرار المالك 2026-09-20)، وموسومٌ بالتخصّص (سلسلة 2026-09-25).
 */
export interface LawyerSuggestionData {
  lawyerId: number | null;
  lawyerName: string | null;
  specialist: boolean;
  /** نصّ العرض من الخادم — لا يُعاد اشتقاقه هنا. */
  label: string;
}

interface Props {
  suggestion?: LawyerSuggestionData | null;
  /** شارةٌ قصيرة بتلميح (الجداول) بدل السطر الكامل (المودالات). */
  compact?: boolean;
}

/** وسم الاقتراح: مختصّ · غير مختصّ · لا محامي — نسخةٌ واحدة لشاشات الإسناد كلّها. */
const LawyerSuggestionHint: React.FC<Props> = ({ suggestion, compact = false }) => {
  if (!suggestion) return null;

  const tone = suggestion.lawyerId === null ? 'b-grey' : suggestion.specialist ? 'b-green' : 'b-amber';
  const short = suggestion.lawyerId === null ? 'لا محامٍ متاح' : suggestion.specialist ? 'مختصّ' : 'غير مختصّ';

  if (compact) {
    return (
      <span title={suggestion.label} style={{ whiteSpace: 'nowrap' }}>
        <Badge text={short} tone={tone} />
      </span>
    );
  }

  return (
    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 6, fontSize: 12, lineHeight: 1.5, marginTop: 4 }}>
      <span style={{ whiteSpace: 'nowrap' }}>
        <Badge text={short} tone={tone} />
      </span>
      <span style={{ color: 'var(--muted)' }}>
        {suggestion.lawyerName ? <b style={{ color: 'var(--ink)' }}>{suggestion.lawyerName}</b> : null}
        {suggestion.lawyerName ? ' — ' : ''}
        {suggestion.label}
      </span>
    </div>
  );
};

export default LawyerSuggestionHint;
