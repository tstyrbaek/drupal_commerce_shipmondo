import { useEffect, useId, useRef } from 'react';
import { createPortal } from 'react-dom';
import { ServicePointList } from './ServicePointList';
import { ServicePointMap } from './ServicePointMap';

export function ServicePointPickerModal({
  open,
  onClose,
  servicePoints,
  selectedId,
  onSelect,
  showMap = true,
  t,
}) {
  const titleId = useId();
  const closeButtonRef = useRef(null);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    closeButtonRef.current?.focus();

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        onClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, onClose]);

  if (!open) {
    return null;
  }

  const handleSelect = (servicePoint) => {
    onSelect(servicePoint);
    onClose();
  };

  return createPortal(
    <div className="sps-modal-root">
      <button
        type="button"
        className="sps-modal-backdrop"
        onClick={onClose}
        aria-label={t.close}
      />

      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="sps-modal-panel"
      >
        <div className="sps-modal-header">
          <h2 id={titleId} className="sps-text-lg sps-font-semibold sps-text-gray-900">
            {t.title}
          </h2>
          <button
            ref={closeButtonRef}
            type="button"
            onClick={onClose}
            className="sps-modal-close"
            aria-label={t.close}
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div className="sps-modal-body">
          <div className="sps-flex sps-flex-col sps-gap-4 lg:sps-flex-row lg:sps-gap-6">
            <div className="sps-w-full lg:sps-w-1/2 lg:sps-max-h-[60vh] lg:sps-overflow-y-auto">
              <ServicePointList
                servicePoints={servicePoints}
                selectedId={selectedId}
                onSelect={handleSelect}
                t={t}
              />
            </div>

            {showMap && (
              <div className="sps-w-full lg:sps-w-1/2 lg:sps-min-h-[320px]">
                <ServicePointMap
                  servicePoints={servicePoints}
                  selectedId={selectedId}
                  onSelect={handleSelect}
                  t={t}
                  isVisible={open}
                />
              </div>
            )}
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
