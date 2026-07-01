import React from 'react';
import Icon from '@/lib/icons';

// يطابق هيكل #modal (modal-bg/modal/modal-head/modal-body) + openModal/closeModal

interface ModalProps {
  title: string;
  open: boolean;
  onClose: () => void;
  children: React.ReactNode;
}

const Modal: React.FC<ModalProps> = ({ title, open, onClose, children }) => (
  <div className={`modal-bg${open ? ' show' : ''}`} onClick={onClose}>
    <div className="modal" onClick={(e) => e.stopPropagation()}>
      <div className="modal-head">
        <h3>{title}</h3>
        <button className="x" onClick={onClose} type="button" aria-label="إغلاق">
          <Icon name="close" />
        </button>
      </div>
      <div className="modal-body">{open ? children : null}</div>
    </div>
  </div>
);

export default Modal;
