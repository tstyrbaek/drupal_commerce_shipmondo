<?php

namespace Drupal\commerce_shipmondo\Form;

use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\commerce_shipmondo\Service\ShipmondoApiClient;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Commerce Shipmondo.
 */
class ShipmondoSettingsForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    protected ShipmondoApiClient $apiClient,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('commerce_shipmondo.api_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_shipmondo_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['commerce_shipmondo.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('commerce_shipmondo.settings');
    $sender = $config->get('sender') ?? [];

    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API credentials'),
      '#open' => TRUE,
    ];
    $form['api']['api_user_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API user'),
      '#default_value' => $config->get('api_user_key'),
      '#required' => TRUE,
    ];
    $form['api']['api_key_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API key'),
      '#default_value' => $config->get('api_key_key'),
      '#required' => TRUE,
    ];
    $form['api']['frontend_key_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Frontend key'),
      '#description' => $this->t('Optional Shipmondo Shipping Module frontend key for service point lookup in checkout. If empty, the configured API user and API key are used with Basic Auth instead.'),
      '#default_value' => $config->get('frontend_key_key'),
      '#required' => FALSE,
    ];
    $form['api']['use_sandbox'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Shipmondo Sandbox API'),
      '#description' => $this->t('Send requests to sandbox.shipmondo.com. Use sandbox API credentials. Shipments appear in the Shipmondo sandbox environment, not production.'),
      '#default_value' => $config->get('use_sandbox'),
    ];
    $form['api']['own_agreement'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Own carrier agreement'),
      '#default_value' => $config->get('own_agreement'),
    ];

    $default_service_codes = (string) $config->get('default_service_codes');
    $service_options = $this->loadServiceOptions();
    if ($service_options !== []) {
      $form['api']['default_service_codes'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Default service codes'),
        '#description' => $this->t('Optional services added to every label. Required product services are merged automatically from the Shipmondo API.'),
        '#options' => $service_options,
        '#default_value' => ShipmondoApiClient::serviceCodesToDefaultValues($default_service_codes),
      ];
    }
    else {
      $form['api']['default_service_codes'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Default service codes'),
        '#description' => $this->t('Comma-separated codes added to every label (e.g. EMAIL_NT for GLS e-mail notification). Configure API credentials to load available services from Shipmondo.'),
        '#default_value' => $default_service_codes,
      ];
    }

    $label_format = (string) ($config->get('label_format') ?: 'a4_pdf');
    $label_format_options = [
      'a4_pdf' => $this->t('A4 PDF'),
      '10x19_pdf' => $this->t('10×19 cm PDF'),
      '10x19_zpl' => $this->t('10×19 cm ZPL'),
      'compact_pdf' => $this->t('Compact PDF'),
      'compact_zpl' => $this->t('Compact ZPL'),
    ];
    if (!isset($label_format_options[$label_format])) {
      $label_format_options[$label_format] = $label_format;
    }
    $form['api']['label_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Label format'),
      '#description' => $this->t('Format used when creating and downloading labels from Shipmondo.'),
      '#options' => $label_format_options,
      '#default_value' => $label_format,
      '#required' => TRUE,
    ];
    $form['api']['default_receiver_country_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default receiver country'),
      '#description' => $this->t('ISO country code used when loading available products and services in admin forms (e.g. DK for domestic shipments).'),
      '#default_value' => $config->get('default_receiver_country_code') ?: 'DK',
      '#size' => 5,
      '#maxlength' => 2,
      '#required' => TRUE,
    ];

    $form['receiver'] = [
      '#type' => 'details',
      '#title' => $this->t('Receiver contact fields'),
      '#description' => $this->t('Optional machine names of phone/email fields on the shipping profile. Leave empty to auto-detect (e.g. field_phone_number, field_telefonnummer).'),
      '#open' => FALSE,
    ];
    $form['receiver']['receiver_phone_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone field machine name'),
      '#default_value' => $config->get('receiver_phone_field'),
      '#placeholder' => 'field_phone_number',
    ];
    $form['receiver']['receiver_email_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email field machine name'),
      '#default_value' => $config->get('receiver_email_field'),
      '#placeholder' => 'field_email',
    ];

    $form['sender'] = [
      '#type' => 'details',
      '#title' => $this->t('Sender address'),
      '#open' => FALSE,
    ];
    $form['sender']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $sender['name'] ?? '',
      '#required' => TRUE,
    ];
    $form['sender']['address1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address line 1'),
      '#default_value' => $sender['address1'] ?? '',
      '#required' => TRUE,
    ];
    $form['sender']['address2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address line 2'),
      '#default_value' => $sender['address2'] ?? '',
    ];
    $form['sender']['zipcode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postal code'),
      '#default_value' => $sender['zipcode'] ?? '',
      '#required' => TRUE,
    ];
    $form['sender']['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#default_value' => $sender['city'] ?? '',
      '#required' => TRUE,
    ];
    $form['sender']['country_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country code'),
      '#default_value' => $sender['country_code'] ?? 'DK',
      '#size' => 5,
      '#maxlength' => 2,
      '#required' => TRUE,
    ];
    $form['sender']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $sender['email'] ?? '',
    ];
    $form['sender']['telephone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Telephone'),
      '#default_value' => $sender['telephone'] ?? '',
    ];
    $form['sender']['mobile'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile'),
      '#default_value' => $sender['mobile'] ?? '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $default_service_codes = $form_state->getValue('default_service_codes');
    if (is_array($default_service_codes)) {
      $default_service_codes = ShipmondoApiClient::selectedServiceCodesToString($default_service_codes);
    }
    else {
      $default_service_codes = trim((string) $default_service_codes);
    }

    $previous = $this->config('commerce_shipmondo.settings');
    $previous_sender = $previous->get('sender') ?? [];
    $api_changed = $previous->get('api_user_key') !== $form_state->getValue('api_user_key')
      || $previous->get('api_key_key') !== $form_state->getValue('api_key_key')
      || (bool) $previous->get('use_sandbox') !== (bool) $form_state->getValue('use_sandbox')
      || ($previous_sender['country_code'] ?? 'DK') !== $form_state->getValue('country_code')
      || ($previous->get('default_receiver_country_code') ?: 'DK') !== $form_state->getValue('default_receiver_country_code');

    $this->configFactory->getEditable('commerce_shipmondo.settings')
      ->set('api_user_key', $form_state->getValue('api_user_key'))
      ->set('api_key_key', $form_state->getValue('api_key_key'))
      ->set('frontend_key_key', $form_state->getValue('frontend_key_key'))
      ->set('use_sandbox', (bool) $form_state->getValue('use_sandbox'))
      ->set('default_service_codes', $default_service_codes)
      ->set('own_agreement', (bool) $form_state->getValue('own_agreement'))
      ->set('label_format', $form_state->getValue('label_format'))
      ->set('default_receiver_country_code', strtoupper(trim((string) $form_state->getValue('default_receiver_country_code'))))
      ->set('receiver_phone_field', trim((string) $form_state->getValue('receiver_phone_field')))
      ->set('receiver_email_field', trim((string) $form_state->getValue('receiver_email_field')))
      ->set('sender', [
        'name' => $form_state->getValue('name'),
        'address1' => $form_state->getValue('address1'),
        'address2' => $form_state->getValue('address2'),
        'zipcode' => $form_state->getValue('zipcode'),
        'city' => $form_state->getValue('city'),
        'country_code' => $form_state->getValue('country_code'),
        'email' => $form_state->getValue('email'),
        'telephone' => $form_state->getValue('telephone'),
        'mobile' => $form_state->getValue('mobile'),
      ])
      ->save();

    if ($api_changed) {
      $this->apiClient->invalidateServiceOptionsCache();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Loads service options from the Shipmondo API when credentials exist.
   *
   * @return array<string, string>
   */
  protected function loadServiceOptions(): array {
    $config = $this->config('commerce_shipmondo.settings');
    if (!$config->get('api_user_key') || !$config->get('api_key_key')) {
      return [];
    }

    $sender = $config->get('sender') ?? [];
    try {
      return $this->apiClient->getServiceOptions(
        (string) ($sender['country_code'] ?? 'DK'),
        (string) ($config->get('default_receiver_country_code') ?: 'DK'),
      );
    }
    catch (ShipmondoApiException $exception) {
      $this->messenger()->addWarning($this->t('Could not load Shipmondo service codes: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return [];
    }
  }

}
