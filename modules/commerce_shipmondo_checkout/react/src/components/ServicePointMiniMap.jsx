import { useEffect, useRef } from 'react';
import L from 'leaflet';
import { selectedIcon } from '../utils/leafletIcons';
import 'leaflet/dist/leaflet.css';

function hasCoordinates(servicePoint) {
  return (
    servicePoint?.latitude != null
    && servicePoint?.longitude != null
    && !Number.isNaN(Number(servicePoint.latitude))
    && !Number.isNaN(Number(servicePoint.longitude))
  );
}

export function ServicePointMiniMap({ servicePoint }) {
  const mapContainerRef = useRef(null);
  const mapRef = useRef(null);

  useEffect(() => {
    if (!mapContainerRef.current || !hasCoordinates(servicePoint)) {
      return undefined;
    }

    const lat = Number(servicePoint.latitude);
    const lng = Number(servicePoint.longitude);

    mapRef.current = L.map(mapContainerRef.current, {
      zoomControl: false,
      attributionControl: false,
      dragging: false,
      scrollWheelZoom: false,
      doubleClickZoom: false,
      boxZoom: false,
      keyboard: false,
      tap: false,
    }).setView([lat, lng], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
    }).addTo(mapRef.current);

    L.marker([lat, lng], { icon: selectedIcon }).addTo(mapRef.current);

    const timer = setTimeout(() => {
      mapRef.current?.invalidateSize();
    }, 50);

    return () => {
      clearTimeout(timer);
      mapRef.current?.remove();
      mapRef.current = null;
    };
  }, [servicePoint]);

  if (!hasCoordinates(servicePoint)) {
    return (
      <div
        className="sps-mini-map-container sps-flex sps-items-center sps-justify-center sps-bg-gray-100 sps-text-xs sps-text-gray-400"
        aria-hidden="true"
      />
    );
  }

  return (
    <div
      ref={mapContainerRef}
      className="sps-mini-map-container"
      aria-hidden="true"
    />
  );
}
