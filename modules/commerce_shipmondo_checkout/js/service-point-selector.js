(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Reads mount settings from the container element or drupalSettings fallback.
   */
  function getSelectorSettings(container) {
    const raw = container.getAttribute('data-shipmondo-sps');
    if (raw) {
      try {
        return JSON.parse(raw);
      }
      catch (error) {
        console.warn('Shipmondo service point selector: invalid data-shipmondo-sps JSON.', error);
      }
    }

    return drupalSettings.commerceShipmondo ?? {};
  }

  /**
   * Unmounts a React instance from a container if present.
   */
  function unmountContainer(container) {
    if (typeof container.shipmondoServicePointUnmount === 'function') {
      container.shipmondoServicePointUnmount();
      delete container.shipmondoServicePointUnmount;
    }
  }

  Drupal.behaviors.commerceShipmondoServicePointSelector = {
    attach(context) {
      if (!window.ShipmondoServicePointSelector?.mount) {
        return;
      }

      once(
        'commerce-shipmondo-service-point-selector',
        '.commerce-shipmondo-service-point-selector',
        context,
      ).forEach((container) => {
        const settings = getSelectorSettings(container);
        if (!settings.servicePointApiUrl || !settings.carrierCode || !settings.productCode || !settings.zipCode) {
          return;
        }

        const widget = container.closest('.commerce-shipmondo-service-point-widget');
        const hiddenInput = widget?.querySelector('.commerce-shipmondo-service-point-input')
          || document.getElementById('commerce-shipmondo-service-point');
        let initialServicePoint;

        if (hiddenInput?.value) {
          try {
            const parsed = JSON.parse(hiddenInput.value);
            if (parsed && typeof parsed === 'object' && parsed.id != null) {
              initialServicePoint = parsed;
            }
          }
          catch (error) {
            console.warn('Shipmondo service point selector: invalid stored service point JSON.', error);
          }
        }

        const instance = window.ShipmondoServicePointSelector.mount(container, {
          apiUrl: settings.servicePointApiUrl,
          carrierCode: settings.carrierCode,
          productCode: settings.productCode,
          countryCode: settings.countryCode,
          zipCode: settings.zipCode,
          address: settings.address || '',
          city: settings.city || '',
          language: settings.language || 'da',
          initialServicePoint,
          showMap: true,
          onSelect(servicePoint) {
            if (!hiddenInput) {
              return;
            }
            hiddenInput.value = JSON.stringify(servicePoint);
            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
          },
        });

        if (instance?.unmount) {
          container.shipmondoServicePointUnmount = instance.unmount;
        }
      });
    },

    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        return;
      }

      once
        .remove(
          'commerce-shipmondo-service-point-selector',
          '.commerce-shipmondo-service-point-selector',
          context,
        )
        .forEach(unmountContainer);
    },
  };
})(Drupal, drupalSettings, once);
