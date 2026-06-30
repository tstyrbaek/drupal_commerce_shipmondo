import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { ServicePointSelector } from './ServicePointSelector';

export { ServicePointSelector };
export { useServicePoints } from './hooks/useServicePoints';
export { getTranslations } from './translations';

/**
 * Mounts the service point selector into a DOM element (UMD / Drupal usage).
 *
 * @param {string|Element} target - CSS selector or mount container element.
 * @param {object} props - ServicePointSelector props.
 * @returns {{ unmount: () => void } | undefined}
 */
export function mount(target, props) {
  const element = typeof target === 'string' ? document.querySelector(target) : target;
  if (!element) {
    const label = typeof target === 'string' ? target : 'element';
    console.warn(`ShipmondoServicePointSelector: mount target "${label}" not found.`);
    return undefined;
  }

  const root = createRoot(element);
  root.render(createElement(ServicePointSelector, props));

  return {
    unmount: () => root.unmount(),
  };
}

if (typeof window !== 'undefined') {
  window.ShipmondoServicePointSelector = {
    ServicePointSelector,
    mount,
  };
}
