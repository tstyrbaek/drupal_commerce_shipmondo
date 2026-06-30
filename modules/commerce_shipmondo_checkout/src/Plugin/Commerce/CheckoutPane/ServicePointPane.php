<?php

namespace Drupal\commerce_shipmondo_checkout\Plugin\Commerce\CheckoutPane;

use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\Component\Utility\NestedArray;
use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\commerce_shipmondo\Service\ShippingMethodMapping;
use Drupal\commerce_shipmondo_checkout\Service\ServicePointService;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a service point selector pane for checkout.
 */
#[CommerceCheckoutPane(
  id: 'commerce_shipmondo_service_point',
  label: new TranslatableMarkup('Shipmondo service point'),
  admin_description: new TranslatableMarkup('Lets the customer choose a Shipmondo service point for pickup.'),
  default_step: 'order_information',
  wrapper_element: 'fieldset',
)]
class ServicePointPane extends CheckoutPaneBase {

  /**
   * Prefix for carrier mapping keys in stored configuration.
   *
   * Numeric shipping method IDs are re-indexed by NestedArray::mergeDeep()
   * unless the keys are non-numeric.
   */
  protected const CARRIER_MAPPING_STORAGE_PREFIX = 'shipping_method_';

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The service point API service.
   *
   * @var \Drupal\commerce_shipmondo_checkout\Service\ServicePointService
   */
  protected ServicePointService $servicePointService;

  /**
   * The shipping method mapping storage.
   *
   * @var \Drupal\commerce_shipmondo\Service\ShippingMethodMapping
   */
  protected ShippingMethodMapping $shippingMethodMapping;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, $checkout_flow = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->languageManager = $container->get('language_manager');
    $instance->servicePointService = $container->get('commerce_shipmondo_checkout.service_point');
    $instance->shippingMethodMapping = $container->get('commerce_shipmondo.shipping_method_mapping');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'carrier_mapping' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationSummary() {
    $summary = parent::buildConfigurationSummary();
    $mapped = array_filter($this->configuration['carrier_mapping'] ?? []);
    $count = count($mapped);
    $mapping_summary = $this->formatPlural(
      $count,
      '1 shipping method mapped to a carrier.',
      '@count shipping methods mapped to carriers.',
    );

    return $summary ? implode('<br>', [$summary, $mapping_summary]) : $mapping_summary;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $methods = $this->entityTypeManager
      ->getStorage('commerce_shipping_method')
      ->loadByProperties(['status' => TRUE]);
    uasort($methods, static function (ShippingMethodInterface $a, ShippingMethodInterface $b): int {
      return strnatcmp((string) $a->id(), (string) $b->id());
    });

    $form['carrier_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Carrier mapping'),
      '#description' => $this->t('Map Commerce shipping methods to Shipmondo carrier codes. Only mapped methods show the service point selector during checkout.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    if ($methods === []) {
      $form['carrier_mapping']['empty'] = [
        '#markup' => $this->t('No active shipping methods were found.'),
      ];
      return $form;
    }

    $carrier_options = $this->loadCarrierOptions();

    /** @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface $method */
    foreach ($methods as $method) {
      $method_id = (string) $method->id();
      $default_value = $this->getCarrierCodeFromMapping($method_id);

      $row_key = 'shipping_method_' . $method_id;
      $form['carrier_mapping'][$row_key] = [
        '#type' => 'container',
        '#tree' => TRUE,
        'method_id' => [
          '#type' => 'value',
          '#value' => $method_id,
        ],
      ];

      if ($carrier_options !== []) {
        if ($default_value !== '' && !isset($carrier_options[$default_value])) {
          $carrier_options[$default_value] = $default_value;
        }
        $form['carrier_mapping'][$row_key]['carrier_code'] = [
          '#type' => 'select',
          '#title' => $method->label(),
          '#description' => $this->t('Select the Shipmondo carrier for service point lookup. Choose "- None -" to disable service point selection for this method.'),
          '#options' => ['' => $this->t('- None -')] + $carrier_options,
          '#default_value' => $default_value,
        ];
        continue;
      }

      $form['carrier_mapping'][$row_key]['carrier_code'] = [
        '#type' => 'textfield',
        '#title' => $method->label(),
        '#description' => $this->t('Shipmondo carrier code, e.g. gls or pdk. Configure a frontend key or API user/key under Commerce > Configuration > Shipmondo to load carriers from the API.'),
        '#default_value' => $default_value,
        '#size' => 20,
        '#maxlength' => 64,
      ];
    }

    return $form;
  }

  /**
   * Loads carrier options from the Shipmondo Shipping Module API.
   *
   * @return array<string, string>
   */
  protected function loadCarrierOptions(): array {
    try {
      return $this->servicePointService->getCarrierOptions();
    }
    catch (ShipmondoApiException $exception) {
      $this->messenger()->addWarning($this->t('Could not load Shipmondo carriers: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if ($form_state->getErrors()) {
      return;
    }

    $values = $form_state->getValue($form['#parents']);
    $carrier_mapping = [];
    foreach ($values['carrier_mapping'] ?? [] as $mapping_values) {
      if (!is_array($mapping_values)) {
        continue;
      }

      $method_id = trim((string) ($mapping_values['method_id'] ?? ''));
      $carrier_code = trim((string) ($mapping_values['carrier_code'] ?? ''));
      if ($method_id === '' || $carrier_code === '') {
        continue;
      }

      $carrier_mapping[$this->getCarrierMappingStorageKey($method_id)] = $carrier_code;
    }
    $this->configuration['carrier_mapping'] = $carrier_mapping;
    $this->syncCarrierMappingFormState($form, $form_state, $carrier_mapping);
  }

  /**
   * Builds the storage key for a shipping method carrier mapping.
   */
  protected function getCarrierMappingStorageKey(string $method_id): string {
    return self::CARRIER_MAPPING_STORAGE_PREFIX . $method_id;
  }

  /**
   * Reads a mapped carrier code for a shipping method ID.
   */
  protected function getCarrierCodeFromMapping(string $method_id): string {
    $mapping = $this->configuration['carrier_mapping'] ?? [];
    $storage_key = $this->getCarrierMappingStorageKey($method_id);

    if (!empty($mapping[$storage_key])) {
      return trim((string) $mapping[$storage_key]);
    }

    if (!empty($mapping[$method_id])) {
      return trim((string) $mapping[$method_id]);
    }

    return $this->resolveLegacyIndexedCarrierMapping($method_id, $mapping);
  }

  /**
   * Resolves carrier codes stored under zero-indexed keys after mergeDeep().
   */
  protected function resolveLegacyIndexedCarrierMapping(string $method_id, array $mapping): string {
    if ($mapping === []) {
      return '';
    }

    $keys = array_keys($mapping);
    if ($keys !== range(0, count($mapping) - 1)) {
      return '';
    }

    $methods = $this->entityTypeManager
      ->getStorage('commerce_shipping_method')
      ->loadByProperties(['status' => TRUE]);
    uasort($methods, static function (ShippingMethodInterface $a, ShippingMethodInterface $b): int {
      return strnatcmp((string) $a->id(), (string) $b->id());
    });

    $index = 0;
    foreach ($methods as $method) {
      if ((string) $method->id() === $method_id) {
        return trim((string) ($mapping[$index] ?? ''));
      }
      $index++;
    }

    return '';
  }

  /**
   * Keeps form state in sync after submit so AJAX rebuild shows saved values.
   */
  protected function syncCarrierMappingFormState(array $form, FormStateInterface $form_state, array $carrier_mapping): void {
    $methods = $this->entityTypeManager
      ->getStorage('commerce_shipping_method')
      ->loadByProperties(['status' => TRUE]);
    uasort($methods, static function (ShippingMethodInterface $a, ShippingMethodInterface $b): int {
      return strnatcmp((string) $a->id(), (string) $b->id());
    });

    $form_values = [];
    foreach ($methods as $method) {
      $method_id = (string) $method->id();
      $row_key = $this->getCarrierMappingStorageKey($method_id);
      $form_values[$row_key] = [
        'method_id' => $method_id,
        'carrier_code' => $carrier_mapping[$row_key] ?? '',
      ];
    }

    $parents = array_merge($form['#parents'], ['carrier_mapping']);
    $form_state->setValue($parents, $form_values);

    $user_input = $form_state->getUserInput();
    NestedArray::setValue($user_input, $parents, $form_values);
    $form_state->setUserInput($user_input);
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $carrier_code = $this->getCarrierCodeForOrder();
    $product_code = $this->getProductCodeForOrder();
    if ($carrier_code === NULL || $product_code === NULL) {
      $pane_form['#access'] = FALSE;
      return $pane_form;
    }

    $default_value = $this->getStoredServicePointJson();

    $pane_form['#commerce_shipmondo_service_point_required'] = TRUE;

    $pane_form['#attributes']['class'][] = 'commerce-shipmondo-service-point-widget';

    $pane_form['service_point'] = [
      '#type' => 'hidden',
      '#default_value' => $default_value,
      '#attributes' => [
        'id' => 'commerce-shipmondo-service-point',
        'class' => ['commerce-shipmondo-service-point-input'],
      ],
    ];

    $selector_settings = [
      'servicePointApiUrl' => Url::fromRoute('commerce_shipmondo_checkout.service_points')->toString(),
      'carrierCode' => $carrier_code,
      'productCode' => $product_code,
      'zipCode' => $this->getShippingPostalCode(),
      'address' => $this->getShippingAddressLine(),
      'city' => $this->getShippingCity(),
      'countryCode' => $this->getShippingCountryCode(),
      'language' => $this->languageManager->getCurrentLanguage()->getId(),
    ];

    $pane_form['service_point_selector'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'commerce-shipmondo-service-point-selector',
        'class' => [
          'commerce-shipmondo-service-point-selector',
        ],
        'data-shipmondo-sps' => json_encode($selector_settings, JSON_THROW_ON_ERROR),
      ],
    ];

    $complete_form['#attached']['library'][] = 'commerce_shipmondo_checkout/service_point_selector';
    $complete_form['#attached']['drupalSettings']['commerceShipmondo'] = array_merge(
      $complete_form['#attached']['drupalSettings']['commerceShipmondo'] ?? [],
      $selector_settings,
    );

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    if (empty($pane_form['#commerce_shipmondo_service_point_required'])) {
      return;
    }

    $values = $form_state->getValue($pane_form['#parents']);
    $raw_value = trim((string) ($values['service_point'] ?? ''));
    if ($raw_value === '') {
      $form_state->setError($pane_form, $this->t('Please select a pickup location.'));
      return;
    }

    $decoded = json_decode($raw_value, TRUE);
    if (!is_array($decoded) || empty($decoded['id'])) {
      $form_state->setError($pane_form, $this->t('Please select a valid pickup location.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    if (!$this->order->hasField('shipmondo_service_point')) {
      return;
    }

    if (empty($pane_form['#commerce_shipmondo_service_point_required'])) {
      $this->order->set('shipmondo_service_point', NULL);
      return;
    }

    $values = $form_state->getValue($pane_form['#parents']);
    $value = trim((string) ($values['service_point'] ?? ''));
    if ($value === '') {
      return;
    }

    $decoded = json_decode($value, TRUE);
    if (!is_array($decoded)) {
      return;
    }

    $carrier_code = $this->getCarrierCodeForOrder();
    if ($carrier_code !== NULL) {
      $decoded['carrier_code'] = $carrier_code;
    }

    $this->order->set('shipmondo_service_point', json_encode($decoded, JSON_UNESCAPED_UNICODE));
  }

  /**
   * Gets the stored service point JSON from the order field.
   */
  protected function getStoredServicePointJson(): string {
    if ($this->order->hasField('shipmondo_service_point') && !$this->order->get('shipmondo_service_point')->isEmpty()) {
      return (string) $this->order->get('shipmondo_service_point')->value;
    }

    $legacy = $this->order->getData('commerce_shipmondo_service_point') ?? [];
    return is_array($legacy) ? json_encode($legacy, JSON_UNESCAPED_UNICODE) : '';
  }

  /**
   * Gets the Shipmondo carrier code for the order's selected shipping method.
   */
  protected function getCarrierCodeForOrder(): ?string {
    $method_id = $this->getSelectedShippingMethodId();
    if ($method_id === NULL) {
      return NULL;
    }

    $carrier_code = $this->getCarrierCodeFromMapping($method_id);
    return $carrier_code !== '' ? $carrier_code : NULL;
  }

  /**
   * Gets the selected shipping method from the order shipment.
   */
  protected function getSelectedShippingMethod(): ?ShippingMethodInterface {
    foreach ($this->order->get('shipments')->referencedEntities() as $shipment) {
      if (!$shipment instanceof ShipmentInterface) {
        continue;
      }
      $shipping_method = $shipment->getShippingMethod();
      if ($shipping_method instanceof ShippingMethodInterface) {
        return $shipping_method;
      }
    }
    return NULL;
  }

  /**
   * Gets the selected shipping method ID from the order shipment.
   */
  protected function getSelectedShippingMethodId(): ?string {
    $shipping_method = $this->getSelectedShippingMethod();
    return $shipping_method ? (string) $shipping_method->id() : NULL;
  }

  /**
   * Gets the Shipmondo product code for the order's selected shipping method.
   */
  protected function getProductCodeForOrder(): ?string {
    $carrier_code = $this->getCarrierCodeForOrder();
    if ($carrier_code === NULL) {
      return NULL;
    }

    $preferred_product_code = NULL;
    $shipping_method = $this->getSelectedShippingMethod();
    if ($shipping_method) {
      $mapping = $this->shippingMethodMapping->get($shipping_method);
      $preferred_product_code = trim((string) ($mapping['product_code'] ?? ''));
    }

    try {
      return $this->servicePointService->resolveServicePointProductCode(
        $carrier_code,
        $preferred_product_code !== '' ? $preferred_product_code : NULL,
      );
    }
    catch (ShipmondoApiException $exception) {
      $this->messenger()->addWarning($this->t('Could not load Shipmondo service point products: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return NULL;
    }
  }

  /**
   * Gets the shipping address from the order shipping profile.
   */
  protected function getShippingAddress(): ?AddressItem {
    $shipping_profile = $this->order->collectProfiles()['shipping'] ?? NULL;
    if (!$shipping_profile instanceof ProfileInterface || !$shipping_profile->hasField('address')) {
      return NULL;
    }

    $address = $shipping_profile->get('address')->first();
    return $address instanceof AddressItem ? $address : NULL;
  }

  /**
   * Gets the postal code from the order shipping profile.
   */
  protected function getShippingPostalCode(): string {
    $address = $this->getShippingAddress();
    return $address ? (string) $address->getPostalCode() : '';
  }

  /**
   * Gets the street address from the order shipping profile.
   */
  protected function getShippingAddressLine(): string {
    $address = $this->getShippingAddress();
    if (!$address) {
      return '';
    }

    $lines = array_filter([
      trim((string) $address->getAddressLine1()),
      trim((string) $address->getAddressLine2()),
    ]);

    return implode(' ', $lines);
  }

  /**
   * Gets the city from the order shipping profile.
   */
  protected function getShippingCity(): string {
    $address = $this->getShippingAddress();
    return $address ? trim((string) $address->getLocality()) : '';
  }

  /**
   * Gets the country code from the order shipping profile.
   */
  protected function getShippingCountryCode(): string {
    $address = $this->getShippingAddress();
    return $address ? (string) $address->getCountryCode() : 'DK';
  }

}
