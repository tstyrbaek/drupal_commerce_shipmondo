import { useCallback, useEffect, useRef, useState } from 'react';
import { ServicePointCard } from './components/ServicePointCard';
import { ServicePointPickerModal } from './components/ServicePointPickerModal';
import { useServicePoints } from './hooks/useServicePoints';
import { getTranslations } from './translations';
import './styles/base.css';

/**
 * Picks the nearest service point from an API result list.
 */
function getNearestServicePoint(servicePoints, preferred) {
  if (!Array.isArray(servicePoints) || servicePoints.length === 0) {
    return preferred ?? null;
  }

  if (preferred?.id != null) {
    const match = servicePoints.find((servicePoint) => servicePoint.id === preferred.id);
    if (match) {
      return match;
    }
  }

  const withDistance = servicePoints.filter(
    (servicePoint) => servicePoint.distance != null && !Number.isNaN(Number(servicePoint.distance)),
  );
  if (withDistance.length > 0) {
    return [...withDistance].sort((a, b) => Number(a.distance) - Number(b.distance))[0];
  }

  return servicePoints[0];
}

export function ServicePointSelector({
  apiUrl,
  carrierCode,
  zipCode,
  address,
  city,
  productCode,
  countryCode,
  language = 'da',
  initialServicePoint = null,
  onSelect,
  onError,
  showMap = true,
  mock = false,
}) {
  const t = getTranslations(language);
  const [selectedId, setSelectedId] = useState(initialServicePoint?.id ?? null);
  const [modalOpen, setModalOpen] = useState(false);
  const userHasChosenRef = useRef(Boolean(initialServicePoint?.id));
  const onSelectRef = useRef(onSelect);
  onSelectRef.current = onSelect;

  const { servicePoints, loading, error } = useServicePoints({
    apiUrl,
    carrierCode,
    zipCode,
    address,
    city,
    productCode,
    countryCode,
    mock,
    onError,
  });

  const handleSelect = useCallback(
    (servicePoint, fromPicker = false) => {
      setSelectedId(servicePoint.id);
      onSelect?.(servicePoint);
      if (fromPicker) {
        userHasChosenRef.current = true;
      }
    },
    [onSelect],
  );

  useEffect(() => {
    if (loading || error || servicePoints.length === 0) {
      return;
    }

    if (userHasChosenRef.current && selectedId != null) {
      const current = servicePoints.find((servicePoint) => servicePoint.id === selectedId);
      if (current) {
        return;
      }
      userHasChosenRef.current = false;
    }

    if (selectedId != null) {
      const current = servicePoints.find((servicePoint) => servicePoint.id === selectedId);
      if (current) {
        onSelectRef.current?.(current);
        return;
      }
    }

    const nearest = getNearestServicePoint(servicePoints, initialServicePoint);
    if (!nearest) {
      return;
    }

    setSelectedId(nearest.id);
    onSelectRef.current?.(nearest);
  }, [servicePoints, loading, error, initialServicePoint, selectedId]);

  const selectedPoint =
    servicePoints.find((servicePoint) => servicePoint.id === selectedId) ??
    (initialServicePoint?.id === selectedId ? initialServicePoint : null);

  const handlePickerSelect = useCallback(
    (servicePoint) => handleSelect(servicePoint, true),
    [handleSelect],
  );

  return (
    <div className="sps-selector sps-w-full">
      {loading && (
        <p className="sps-text-sm sps-text-gray-500" role="status">
          {t.loading}
        </p>
      )}

      {error && !loading && (
        <p className="sps-rounded-md sps-border sps-border-red-200 sps-bg-red-50 sps-p-3 sps-text-sm sps-text-red-700" role="alert">
          {error.message || t.error}
        </p>
      )}

      {!loading && !error && servicePoints.length === 0 && (
        <p className="sps-text-sm sps-text-gray-500">{t.noResults}</p>
      )}

      {!loading && !error && selectedPoint && (
        <div className="sps-space-y-3">
          <h2 className="sps-text-lg sps-font-semibold sps-text-gray-900">
            {t.selectedTitle}
          </h2>

          <ServicePointCard
            servicePoint={selectedPoint}
            selected
            interactive={false}
            showMiniMap={showMap}
            onSelect={handleSelect}
            t={t}
          />

          <button
            type="button"
            onClick={() => setModalOpen(true)}
            className="sps-text-sm sps-font-medium sps-text-sps-primary hover:sps-text-sps-primary-dark sps-underline"
          >
            {t.changePickupPoint}
          </button>
        </div>
      )}

      <ServicePointPickerModal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        servicePoints={servicePoints}
        selectedId={selectedId}
        onSelect={handlePickerSelect}
        showMap={showMap}
        t={t}
      />
    </div>
  );
}
