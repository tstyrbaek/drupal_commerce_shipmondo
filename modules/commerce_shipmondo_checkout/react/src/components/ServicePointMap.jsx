import { useEffect, useRef } from 'react';
import L from 'leaflet';
import { defaultIcon, selectedIcon } from '../utils/leafletIcons';
import 'leaflet/dist/leaflet.css';

function getCenterPoint(servicePoints, selectedId) {
  if (selectedId) {
    const selected = servicePoints.find((point) => point.id === selectedId);
    if (selected?.latitude != null && selected?.longitude != null) {
      return [Number(selected.latitude), Number(selected.longitude)];
    }
  }

  const first = servicePoints.find(
    (point) => point.latitude != null && point.longitude != null,
  );

  if (first) {
    return [Number(first.latitude), Number(first.longitude)];
  }

  return [55.6761, 12.5683];
}

export function ServicePointMap({
  servicePoints,
  selectedId,
  onSelect,
  t,
  isVisible = true,
}) {
  const mapContainerRef = useRef(null);
  const mapRef = useRef(null);
  const markersRef = useRef([]);

  useEffect(() => {
    if (!mapContainerRef.current || mapRef.current) {
      return undefined;
    }

    const [lat, lng] = getCenterPoint(servicePoints, selectedId);
    mapRef.current = L.map(mapContainerRef.current).setView([lat, lng], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19,
    }).addTo(mapRef.current);

    return () => {
      mapRef.current?.remove();
      mapRef.current = null;
    };
  }, []);

  useEffect(() => {
    if (!mapRef.current) {
      return;
    }

    markersRef.current.forEach((marker) => marker.remove());
    markersRef.current = [];

    const validPoints = servicePoints.filter(
      (point) => point.latitude != null && point.longitude != null,
    );

    validPoints.forEach((point) => {
      const isSelected = point.id === selectedId;
      const marker = L.marker(
        [Number(point.latitude), Number(point.longitude)],
        { icon: isSelected ? selectedIcon : defaultIcon },
      ).addTo(mapRef.current);

      marker.bindPopup(
        `<strong>${point.name}</strong><br>${point.address}<br>${point.zipcode} ${point.city}`,
      );

      marker.on('click', () => {
        onSelect(point);
      });

      markersRef.current.push(marker);
    });

    if (validPoints.length > 0) {
      const [lat, lng] = getCenterPoint(validPoints, selectedId);
      mapRef.current.setView([lat, lng], validPoints.length === 1 ? 14 : 13);
    }
  }, [servicePoints, selectedId, onSelect]);

  useEffect(() => {
    if (!mapRef.current || !isVisible) {
      return undefined;
    }

    const timer = setTimeout(() => {
      mapRef.current?.invalidateSize();
    }, 100);

    return () => clearTimeout(timer);
  }, [isVisible, servicePoints, selectedId]);

  return (
    <div className="sps-h-full sps-min-h-[320px]">
      <div
        ref={mapContainerRef}
        className="sps-map-container"
        role="application"
        aria-label={t.selectOnMap}
      />
    </div>
  );
}
