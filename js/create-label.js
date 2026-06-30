(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.commerceShipmondoCreateLabel = {
    attach(context) {
      once(
        'commerce-shipmondo-create-label',
        'form.commerce-shipmondo-create-label-form',
        context,
      ).forEach((form) => {
        const status = form.querySelector('.commerce-shipmondo-create-label-status');
        const submit = form.querySelector('[type="submit"]');
        const cancel = form.querySelector('.form-actions a');

        form.addEventListener('submit', () => {
          if (form.classList.contains('is-loading')) {
            return;
          }

          form.classList.add('is-loading');

          if (status) {
            status.removeAttribute('hidden');
            status.setAttribute('aria-busy', 'true');
          }

          if (submit) {
            submit.setAttribute('disabled', 'disabled');
            submit.setAttribute('aria-disabled', 'true');
            submit.classList.add('is-disabled');
          }

          if (cancel) {
            cancel.setAttribute('aria-disabled', 'true');
            cancel.classList.add('is-disabled');
            cancel.style.pointerEvents = 'none';
          }
        });
      });
    },
  };
})(Drupal, once);
