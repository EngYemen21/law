import React, { useState, useMemo } from 'react';
import Icon from '@/lib/icons';
import { todayISO } from '@/lib/local-date';

export interface TimeSlotItem {
  time: string;
  taken?: boolean;
  /** لا يُتجاوز ولو سُمح بالمحجوز (`allowTaken`): وقتٌ مضى أو جلسة محكمة — من الخادم. */
  hard?: boolean;
  label?: string;
}

interface Props {
  value: string;
  onChange: (time: string) => void;
  date?: string;
  slots?: Array<string | TimeSlotItem>;
  label?: string;
  required?: boolean;
  disabled?: boolean;
  showPeriodFilter?: boolean;
  minTime?: string;
  allowCustom?: boolean;
  helperText?: string;
  /**
   * نصّ القائمة الفارغة. بلا هذه الخاصّيّة تُعرض الشبكة العامّة حين لا تصل شرائح (الجلسات والاجتماعات)؛
   * ومعها القائمةُ الفارغة فارغةٌ فعلاً — يومُ عطلةٍ في حجز الاستشارة لا تُعرض فيه ساعاتٌ يرفضها الخادم.
   */
  emptyText?: string;
  /** المحجوز قابلٌ للاختيار (خيار «السماح بالحجز المتداخل») — يبقى معلَّماً، وما عليه `hard` يبقى مقفلاً. */
  allowTaken?: boolean;
}

const DEFAULT_HOURS_SLOTS: string[] = [
  '08:00', '08:30', '09:00', '09:30', '10:00', '10:30',
  '11:00', '11:30', '12:00', '12:30', '13:00', '13:30',
  '14:00', '14:30', '15:00', '15:30', '16:00', '16:30',
  '17:00', '17:30', '18:00', '18:30', '19:00', '19:30',
  '20:00', '20:30', '21:00', '21:30', '22:00'
];

export const formatSlotDisplay = (timeStr: string): string => {
  if (!timeStr) return '';
  const parts = timeStr.split(':');
  if (parts.length < 2) return timeStr;
  const h = parseInt(parts[0], 10);
  const m = parts[1];
  if (isNaN(h)) return timeStr;
  const period = h >= 12 ? 'م' : 'ص';
  const displayH = h % 12 === 0 ? 12 : h % 12;
  return `${displayH}:${m} ${period}`;
};

const isPastSlot = (dateStr?: string, timeStr?: string): boolean => {
  if (!dateStr || !timeStr) return false;
  const today = todayISO();
  if (dateStr < today) return true;
  if (dateStr > today) return false;
  const [h, m] = timeStr.split(':').map((v) => parseInt(v, 10));
  const now = new Date();
  const nowH = now.getHours();
  const nowM = now.getMinutes();
  return h < nowH || (h === nowH && m <= nowM);
};

const TimeSlotPicker: React.FC<Props> = ({
  value,
  onChange,
  date,
  slots,
  label = 'الوقت المتاح',
  required = false,
  disabled = false,
  showPeriodFilter = true,
  minTime,
  // **الافتراضيّ `false`.** كان `true`، فيظهر حقل وقتٍ حرّ في كلّ منتقٍ
  // لم يُمرّر له شيء — ومنه تدخل أوقاتٌ خارج شبكة الشرائح إلى مسارات
  // تحسب التوفّر بالساعة. ومن أراده فليُعلنه صراحةً عند نقطة الاستدعاء.
  allowCustom = false,
  helperText,
  emptyText,
  allowTaken = false,
}) => {
  const [period, setPeriod] = useState<'all' | 'am' | 'pm'>('all');
  const [showCustomInput, setShowCustomInput] = useState(false);

  const normalizedSlots: TimeSlotItem[] = useMemo(() => {
    const rawList = slots && slots.length > 0 ? slots : emptyText !== undefined ? [] : DEFAULT_HOURS_SLOTS;
    return rawList.map((s) => {
      if (typeof s === 'string') {
        return { time: s, taken: false };
      }
      return s;
    });
  }, [slots, emptyText]);

  const filteredSlots = useMemo(() => {
    return normalizedSlots.filter((slot) => {
      const parts = slot.time.split(':');
      const hour = parseInt(parts[0], 10);
      if (isNaN(hour)) return true;

      if (minTime && slot.time < minTime) return false;

      if (period === 'am') return hour < 12;
      if (period === 'pm') return hour >= 12;
      return true;
    });
  }, [normalizedSlots, period, minTime]);

  return (
    <div className="field" style={{ marginBottom: 14 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8, flexWrap: 'wrap', gap: 6 }}>
        <label style={{ margin: 0, fontWeight: 700, fontSize: 13, color: 'var(--ink)' }}>
          {label} {required && <span style={{ color: 'var(--red)' }}>*</span>}
          {value && (
            <span
              style={{
                marginInlineStart: 8,
                background: 'rgba(14, 92, 156, 0.1)',
                color: 'var(--primary)',
                padding: '2px 8px',
                borderRadius: 6,
                fontSize: 12,
                fontWeight: 700,
              }}
            >
              {formatSlotDisplay(value)} ({value})
            </span>
          )}
        </label>

        {showPeriodFilter && (
          <div style={{ display: 'flex', gap: 3, background: 'var(--paper-2, #f1f5f9)', padding: 3, borderRadius: 8, border: '1px solid var(--line-soft, #e2e8f0)' }}>
            <button
              type="button"
              onClick={() => setPeriod('all')}
              style={{
                border: 'none',
                background: period === 'all' ? '#fff' : 'transparent',
                color: period === 'all' ? 'var(--deep)' : 'var(--muted)',
                fontWeight: period === 'all' ? 700 : 500,
                padding: '3px 8px',
                borderRadius: 6,
                fontSize: 11.5,
                cursor: 'pointer',
                boxShadow: period === 'all' ? '0 1px 3px rgba(0,0,0,0.08)' : 'none',
              }}
            >
              الكل ({normalizedSlots.length})
            </button>
            <button
              type="button"
              onClick={() => setPeriod('am')}
              style={{
                border: 'none',
                background: period === 'am' ? '#fff' : 'transparent',
                color: period === 'am' ? 'var(--deep)' : 'var(--muted)',
                fontWeight: period === 'am' ? 700 : 500,
                padding: '3px 8px',
                borderRadius: 6,
                fontSize: 11.5,
                cursor: 'pointer',
                boxShadow: period === 'am' ? '0 1px 3px rgba(0,0,0,0.08)' : 'none',
              }}
            >
              ☀️ صباحاً
            </button>
            <button
              type="button"
              onClick={() => setPeriod('pm')}
              style={{
                border: 'none',
                background: period === 'pm' ? '#fff' : 'transparent',
                color: period === 'pm' ? 'var(--deep)' : 'var(--muted)',
                fontWeight: period === 'pm' ? 700 : 500,
                padding: '3px 8px',
                borderRadius: 6,
                fontSize: 11.5,
                cursor: 'pointer',
                boxShadow: period === 'pm' ? '0 1px 3px rgba(0,0,0,0.08)' : 'none',
              }}
            >
              🌙 مساءً
            </button>
          </div>
        )}
      </div>

      {helperText && (
        <p style={{ fontSize: 12, color: 'var(--muted)', margin: '0 0 8px 0' }}>{helperText}</p>
      )}

      {emptyText !== undefined && normalizedSlots.length === 0 && (
        <div style={{ color: 'var(--muted)', fontSize: 12.5, padding: '8px 2px' }}>{emptyText}</div>
      )}

      {/* شبكة مربعات الأوقات المتاحة */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fill, minmax(82px, 1fr))',
          gap: 6,
          maxHeight: 180,
          overflowY: 'auto',
          padding: '4px 2px',
          borderRadius: 10,
        }}
      >
        {filteredSlots.map((slot) => {
          const past = isPastSlot(date, slot.time);
          const isBlocked = past || (slot.taken === true && !(allowTaken && !slot.hard));
          const isSelected = value === slot.time;
          // محجوزٌ أُتيح بخيار الحجز المتداخل: يُختار ويبقى معلَّماً «مشغول» بلونٍ تحذيريّ
          const isOverlap = slot.taken === true && !isBlocked;

          return (
            <button
              key={slot.time}
              type="button"
              disabled={disabled || isBlocked}
              onClick={() => {
                onChange(slot.time);
                setShowCustomInput(false);
              }}
              title={past ? 'انقضى هذا الوقت' : isOverlap ? 'للمحامي ارتباطٌ آخر — يُقبل بتنبيه' : slot.taken ? 'محجوز مسبقاً' : `اختيار ${slot.time}`}
              style={{
                padding: '7px 4px',
                border: isSelected
                  ? '2px solid var(--primary, #0E5C9C)'
                  : isBlocked
                  ? '1px dashed #cbd5e1'
                  : isOverlap
                  ? '1px dashed var(--amber, #d97706)'
                  : '1px solid var(--line, #e2e8f0)',
                borderRadius: 8,
                background: isSelected
                  ? 'linear-gradient(135deg, var(--primary, #0E5C9C), var(--cyan, #11A0C8))'
                  : isBlocked
                  ? '#f8fafc'
                  : '#fff',
                color: isSelected
                  ? '#fff'
                  : isBlocked
                  ? '#94a3b8'
                  : 'var(--ink, #1e293b)',
                fontWeight: isSelected ? 800 : 600,
                fontSize: 12,
                cursor: isBlocked || disabled ? 'not-allowed' : 'pointer',
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 2,
                transition: 'all .12s ease',
                textDecoration: isBlocked ? 'line-through' : 'none',
                opacity: isBlocked ? 0.5 : 1,
                boxShadow: isSelected ? '0 4px 10px -2px rgba(14, 92, 156, 0.35)' : 'none',
              }}
            >
              <span>{formatSlotDisplay(slot.time)}</span>
              <span style={{ fontSize: 10, opacity: isSelected ? 0.9 : 0.6, fontWeight: 500 }}>
                {isOverlap ? 'مشغول' : slot.time}
              </span>
            </button>
          );
        })}
      </div>

      {/* خيار إدخال وقت مخصص بدقة الدقيقة */}
      {allowCustom && (
        <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
          <button
            type="button"
            onClick={() => setShowCustomInput(!showCustomInput)}
            style={{
              background: 'none',
              border: 'none',
              color: 'var(--primary)',
              fontSize: 11.5,
              fontWeight: 600,
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: 4,
              padding: 0,
            }}
          >
            <Icon name="clock" cls="ic sm" /> {showCustomInput ? 'إخفاء الإدخال المخصص' : 'تحديد دقيقة مخصصة'}
          </button>

          {showCustomInput && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <span style={{ fontSize: 11.5, color: 'var(--muted)' }}>وقت مخصص:</span>
              <input
                type="time"
                className="input mono"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                style={{ padding: '4px 8px', fontSize: 12, width: 110, height: 30 }}
              />
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default TimeSlotPicker;
