import React from 'react';
import Icon from '@/lib/icons';

interface Props {
  /** مجموعات الصلاحيّات المتاحة للدور بعد البحث — من الصفحة (`usePermCatalog`). */
  groups: { g: string; items: string[] }[];
  perms: string[];
  /** القوالب الجاهزة التي تقع كلّ صلاحيّاتها في حدود الدور. */
  presets: string[];
  search: string;
  onSearch: (q: string) => void;
  onToggle: (perm: string) => void;
  onPreset: (key: string) => void;
  onSelectAll: () => void;
  onClear: () => void;
}

/**
 * **مصفوفة الصلاحيّات في نموذج الموظف** — البحث والقوالب الجاهزة وتحديد الصلاحيّات. فُصلت عن
 * `pages/admin/staff.tsx` (المرحلة ٤): عرضٌ خالص، والحالة في الصفحة.
 */
const PermissionMatrix: React.FC<Props> = ({ groups, perms, presets, search, onSearch, onToggle, onPreset, onSelectAll, onClear }) => (
  <>
    <div className="form-sec-h">
      <span className="si"><Icon name="lock" /></span> 4. مصفوفة الصلاحيات الممنوحة
    </div>

    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10, marginBottom: 12 }}>
      <div style={{ position: 'relative', width: 220 }}>
        <input
          type="text"
          className="input"
          placeholder="ابحث في الصلاحيات..."
          value={search}
          onChange={(e) => onSearch(e.target.value)}
          style={{ fontSize: 12, padding: '6px 10px 6px 26px' }}
        />
        {search && (
          <button
            type="button"
            onClick={() => onSearch('')}
            style={{
              position: 'absolute',
              left: 8,
              top: '50%',
              transform: 'translateY(-50%)',
              background: 'none',
              border: 'none',
              cursor: 'pointer',
              color: 'var(--muted)',
              fontSize: 11,
            }}
          >
            ✕
          </button>
        )}
      </div>

      <div className="presets" style={{ margin: 0 }}>
        <span style={{ fontSize: 11.5, color: 'var(--muted)', alignSelf: 'center', fontWeight: 700 }}>قوالب جاهزة:</span>
        {presets.map((k) => (
          <button
            key={k}
            className="preset-btn"
            onClick={() => onPreset(k)}
            type="button"
          >
            {k}
          </button>
        ))}
        <button className="preset-btn" onClick={onSelectAll} type="button" style={{ color: 'var(--success)', borderColor: 'var(--success)' }}>
          ✓ تحديد الكل
        </button>
        <button className="preset-btn" onClick={onClear} type="button" style={{ color: 'var(--red)', borderColor: 'var(--red)' }}>
          ✕ مسح الكل
        </button>
      </div>
    </div>

    <div style={{ background: '#f8fafc', padding: 14, borderRadius: 12, border: '1px solid var(--line)' }}>
      {groups.map((grp) => (
        <div key={grp.g} style={{ marginBottom: 14 }}>
          <div style={{ fontSize: 12, fontWeight: 800, color: 'var(--primary)', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
            <Icon name="check" cls="ic sm" /> {grp.g}
          </div>
          <div className="perm-grid">
            {grp.items.map((p) => {
              const isOn = perms.indexOf(p) >= 0;

              return (
                <div
                  key={p}
                  className={`perm${isOn ? ' on' : ''}`}
                  onClick={() => onToggle(p)}
                  style={{ background: isOn ? 'rgba(14,92,156,.09)' : '#fff' }}
                >
                  <span className="pk">
                    <Icon name="check" />
                  </span>
                  <span style={{ fontSize: 12 }}>{p}</span>
                </div>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  </>
);

export default PermissionMatrix;
