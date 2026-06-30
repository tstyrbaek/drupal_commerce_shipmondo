import { ServicePointCard } from './ServicePointCard';

export function ServicePointList({
  servicePoints,
  selectedId,
  onSelect,
  t,
}) {
  return (
    <div className="sps-space-y-3">
      {servicePoints.map((servicePoint) => (
        <ServicePointCard
          key={servicePoint.id}
          servicePoint={servicePoint}
          selected={servicePoint.id === selectedId}
          onSelect={onSelect}
          t={t}
        />
      ))}
    </div>
  );
}
