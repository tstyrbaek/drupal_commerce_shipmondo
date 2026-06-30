import { useState } from 'react';
import { ServicePointMiniMap } from './ServicePointMiniMap';

/**
 * Formats distance in meters or kilometers.
 */
function formatDistance(distance, t) {
  if (distance == null || Number.isNaN(Number(distance))) {
    return null;
  }

  const meters = Number(distance);
  if (meters < 1000) {
    return `${Math.round(meters)} ${t.metersFromYou}`;
  }

  const km = (meters / 1000).toFixed(1).replace('.0', '');
  return `${km} ${t.kmFromYou}`;
}

export function ServicePointCard({
  servicePoint,
  selected,
  onSelect,
  t,
  interactive = true,
  showMiniMap = false,
}) {
  const [hoursOpen, setHoursOpen] = useState(false);
  const distance = formatDistance(servicePoint.distance, t);
  const openingHours = Array.isArray(servicePoint.opening_hours)
    ? servicePoint.opening_hours
    : [];

  const handleKeyDown = (event) => {
    if (!interactive) {
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      onSelect(servicePoint);
    }
  };

  const className = [
    'sps-w-full sps-text-left sps-rounded-lg sps-border-2 sps-p-4 sps-transition-colors',
    'sps-bg-white',
    interactive
      ? 'sps-cursor-pointer hover:sps-bg-gray-50 focus:sps-outline-none focus:sps-ring-2 focus:sps-ring-sps-primary focus:sps-ring-offset-2'
      : '',
    selected
      ? 'sps-border-sps-primary sps-shadow-sm'
      : 'sps-border-gray-200',
  ].join(' ');

  const sharedProps = interactive
    ? {
        role: 'button',
        tabIndex: 0,
        onClick: () => onSelect(servicePoint),
        onKeyDown: handleKeyDown,
        'aria-pressed': selected,
      }
    : {};

  const content = (
    <>
      <div className="sps-font-bold sps-text-base sps-text-gray-900">
        {servicePoint.name}
      </div>

      <div className="sps-mt-1 sps-text-sm sps-text-gray-600">
        {servicePoint.address}
      </div>
      <div className="sps-text-sm sps-text-gray-600">
        {servicePoint.zipcode} {servicePoint.city}
      </div>

      {distance && (
        <div className="sps-mt-2 sps-text-sm sps-text-gray-500">{distance}</div>
      )}

      {openingHours.length > 0 && (
        <div className="sps-mt-3">
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              setHoursOpen((open) => !open);
            }}
            className="sps-text-sm sps-font-medium sps-text-sps-primary hover:sps-text-sps-primary-dark sps-underline"
            aria-expanded={hoursOpen}
          >
            {t.openingHours}
          </button>

          {hoursOpen && (
            <ul className="sps-mt-2 sps-space-y-1 sps-text-sm sps-text-gray-600">
              {openingHours.map((entry, index) => (
                <li key={`${entry.day}-${index}`} className="sps-flex sps-justify-between sps-gap-4">
                  <span>{entry.day}</span>
                  <span>
                    {entry.open && entry.close
                      ? `${entry.open} – ${entry.close}`
                      : t.closed}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </>
  );

  return (
    <div className={className} {...sharedProps}>
      {showMiniMap ? (
        <div className="sps-flex sps-gap-4">
          <div className="sps-min-w-0 sps-flex-1">{content}</div>
          <div className="sps-w-36 sps-shrink-0">
            <ServicePointMiniMap servicePoint={servicePoint} />
          </div>
        </div>
      ) : (
        content
      )}
    </div>
  );
}
