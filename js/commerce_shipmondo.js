(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.commerceShipmondoOpenLabel = {
    attach(context) {
      const settings = drupalSettings.commerceShipmondo;
      if (!settings?.openLabelUrl) {
        return;
      }

      once('commerce-shipmondo-open-label', 'body', context).forEach(() => {
        window.open(settings.openLabelUrl, '_blank', 'noopener,noreferrer');

        const url = new URL(window.location.href);
        url.searchParams.delete('open_shipmondo_label');
        window.history.replaceState({}, '', url.toString());
      });
    },
  };
})(Drupal, drupalSettings, once);
