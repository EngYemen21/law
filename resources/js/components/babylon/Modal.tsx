import React from 'react';
import Icon from '@/lib/icons';

// يطابق هيكل #modal (modal-bg/modal/modal-head/modal-body) + openModal/closeModal

interface ModalProps {
  title: string;
  subtitle?: string;
  badge?: React.ReactNode;
  open: boolean;
  onClose: () => void;
  maxWidth?: number | string;
  children: React.ReactNode;
}

const Modal: React.FC<ModalProps> = ({ title, subtitle, badge, open, onClose, maxWidth, children }) => (
  <div className={`modal-bg${open ? ' show' : ''}`} onClick={onClose}>
    <div
      className="modal"
      style={maxWidth ? { maxWidth: typeof maxWidth === 'number' ? `${maxWidth}px` : maxWidth } : undefined}
      onClick={(e) => e.stopPropagation()}
    >
      <div className="modal-head">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <h3>{title}</h3>
            {badge}
          </div>
          {subtitle && <p style={{ margin: '3px 0 0', fontSize: 12, color: 'var(--muted)' }}>{subtitle}</p>}
        </div>
        <button className="x" onClick={onClose} type="button" aria-label="إغلاق">
          <Icon name="close" />
        </button>
      </div>
      <div className="modal-body">{open ? children : null}</div>
    </div>
  </div>
);

export default Modal;
