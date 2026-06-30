<?php

namespace Drupal\commerce_shipmondo\Service;

use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipmondo\Exception\ShipmondoValidationException;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\physical\WeightUnit;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps Commerce shipments to Shipmondo API payloads.
 */
class OrderShipmentMapper {

  /**
   * Service codes that require specific receiver contact fields.
   */
  private const SERVICE_REQUIREMENTS = [
    'EMAIL_NT' => 'email',
    'SMS_NT' => 'phone',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ShippingMethodMapping $shippingMethodMapping,
    protected ShipmondoApiClient $apiClient,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Builds the Shipmondo create-shipment payload.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The commerce shipment.
   *
   * @return array
   *   The API payload.
   *
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoValidationException
   */
  public function buildPayload(ShipmentInterface $shipment): array {
    $this->validate($shipment);

    $order = $shipment->getOrder();
    assert($order instanceof OrderInterface);
    $config = $this->configFactory->get('commerce_shipmondo.settings');
    $shipping_method = $shipment->getShippingMethod();
    assert($shipping_method instanceof ShippingMethodInterface);
    $mapping = $this->shippingMethodMapping->get($shipping_method);

    $product_code = trim((string) ($mapping['product_code'] ?? ''));
    $receiver_party = $this->buildReceiverParty($shipment, $order);
    $country_code = (string) ($receiver_party['country_code'] ?? 'DK');
    $service_codes = $this->resolveServiceCodes(
      $product_code,
      $country_code,
      trim((string) ($mapping['service_codes'] ?? '')),
    );
    $this->validateServiceCodes($service_codes, $receiver_party);

    $payload = [
      'own_agreement' => (bool) $config->get('own_agreement'),
      'label_format' => (string) $config->get('label_format'),
      'product_code' => $product_code,
      'reference' => 'Order ' . $order->getOrderNumber(),
      'parties' => [
        $this->buildSenderParty(),
        $receiver_party,
      ],
      'parcels' => [
        [
          'weight' => $this->getWeightInGrams($shipment),
        ],
      ],
    ];

    if ($service_codes !== '') {
      $payload['service_codes'] = $service_codes;
    }

    $service_point_id = $this->getShipmentServicePointId($shipment);
    if ($service_point_id !== NULL) {
      // Shipmondo API v3: use service_point_id with parties (not deprecated service_point).
      $payload['service_point_id'] = $service_point_id;
      $payload['automatic_select_service_point'] = FALSE;
    }

    return $payload;
  }

  /**
   * Validates that a shipment can be sent to Shipmondo.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment.
   *
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoValidationException
   */
  public function validate(ShipmentInterface $shipment): void {
    $this->validateRequirements($shipment);
    $this->validateWeight($shipment);
  }

  /**
   * Validates shipment requirements excluding weight.
   */
  public function validateRequirements(ShipmentInterface $shipment): void {
    if ($this->hasLabel($shipment)) {
      throw new ShipmondoValidationException('A Shipmondo label already exists for this shipment.');
    }

    $order = $shipment->getOrder();
    if (!$order) {
      throw new ShipmondoValidationException('Shipment is not attached to an order.');
    }

    $shipping_method = $shipment->getShippingMethod();
    if (!$shipping_method) {
      throw new ShipmondoValidationException('Shipment has no shipping method.');
    }

    $mapping = $this->shippingMethodMapping->get($shipping_method);
    if (empty($mapping['product_code'])) {
      throw new ShipmondoValidationException(sprintf(
        'Configure a Shipmondo product code on the shipping method "%s".',
        $shipping_method->label(),
      ));
    }

    $profile = $shipment->getShippingProfile();
    if (!$profile) {
      throw new ShipmondoValidationException('Shipment has no shipping profile.');
    }

    if (!$profile->hasField('address') || $profile->get('address')->isEmpty()) {
      throw new ShipmondoValidationException('Shipping profile is missing an address.');
    }

    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem|null $address */
    $address = $profile->get('address')->first();
    if (!$address instanceof AddressItem) {
      throw new ShipmondoValidationException('Shipping profile address is invalid.');
    }

    if (!$address->getPostalCode() || !$address->getCountryCode()) {
      throw new ShipmondoValidationException('Shipping address requires postal code and country.');
    }

    if (!$address->getAddressLine1() || !$address->getLocality()) {
      throw new ShipmondoValidationException('Shipping address requires street and city.');
    }

    $sender = $this->configFactory->get('commerce_shipmondo.settings')->get('sender') ?? [];
    if (empty($sender['name']) || empty($sender['address1']) || empty($sender['zipcode']) || empty($sender['city']) || empty($sender['country_code'])) {
      throw new ShipmondoValidationException('Shipmondo sender address is incomplete. Configure it at Commerce > Configuration > Shipmondo.');
    }
  }

  /**
   * Validates that the shipment has a usable weight for label creation.
   */
  public function validateWeight(ShipmentInterface $shipment): void {
    if ($shipment->get('weight')->isEmpty()) {
      throw new ShipmondoValidationException('Shipment has no weight. Ensure shippable products have weight configured.');
    }

    $weight = $this->getWeightInGrams($shipment);
    if ($weight <= 0) {
      throw new ShipmondoValidationException('Shipment weight must be greater than zero.');
    }

    if (!$shipment->hasItems()) {
      throw new ShipmondoValidationException('Shipment has no items with weight.');
    }

    if ($this->getItemsWeightInGrams($shipment) <= 0) {
      throw new ShipmondoValidationException('Shipment items have no weight. Configure weight on product variations.');
    }
  }

  /**
   * Whether the shipment already has a stored Shipmondo label.
   */
  public function hasLabel(ShipmentInterface $shipment): bool {
    $data = $shipment->getData('commerce_shipmondo') ?? [];
    return !empty($data['shipment_id']) && !empty($data['label_fid']);
  }

  /**
   * Whether a label can be created for the shipment.
   */
  public function canCreateLabel(ShipmentInterface $shipment): bool {
    try {
      $this->validate($shipment);
      return TRUE;
    }
    catch (ShipmondoValidationException) {
      return FALSE;
    }
  }

  /**
   * Gets shipment weight in grams.
   *
   * Returns 0 when the shipment has no stored weight.
   */
  public function getWeightInGrams(ShipmentInterface $shipment): int {
    if ($shipment->get('weight')->isEmpty()) {
      return 0;
    }

    $weight = $shipment->getWeight();
    if (!$weight) {
      return 0;
    }

    return (int) round((float) $weight->convert(WeightUnit::GRAM)->getNumber());
  }

  /**
   * Gets the combined shipment item weight in grams.
   *
   * Package type weight is excluded. This reflects product weight only.
   */
  public function getItemsWeightInGrams(ShipmentInterface $shipment): int {
    if (!$shipment->hasItems()) {
      return 0;
    }

    $weight = NULL;
    foreach ($shipment->getItems() as $shipment_item) {
      $item_weight = $shipment_item->getWeight();
      $weight = $weight ? $weight->add($item_weight) : $item_weight;
    }

    if (!$weight) {
      return 0;
    }

    return (int) round((float) $weight->convert(WeightUnit::GRAM)->getNumber());
  }

  /**
   * Builds a summary for the confirmation form.
   */
  public function buildSummary(ShipmentInterface $shipment): array {
    $shipping_method = $shipment->getShippingMethod();
    $mapping = $shipping_method ? $this->shippingMethodMapping->get($shipping_method) : [];
    $order = $shipment->getOrder();
    $product_code = trim((string) ($mapping['product_code'] ?? ''));
    $receiver_party = ($shipment->getShippingProfile() && $order)
      ? $this->buildReceiverParty($shipment, $order)
      : [];

    $service_point = $this->getShipmentServicePoint($shipment);

    return [
      'recipient' => $this->formatPartyLabel($receiver_party),
      'service_point' => $service_point ? $this->formatServicePointLabel($service_point) : '',
      'weight_grams' => $this->getWeightInGrams($shipment),
      'product_code' => $product_code,
      'service_codes' => $this->resolveServiceCodes(
        $product_code,
        (string) ($receiver_party['country_code'] ?? 'DK'),
        trim((string) ($mapping['service_codes'] ?? '')),
      ),
      'shipping_method' => $shipping_method ? $shipping_method->label() : '',
    ];
  }

  /**
   * Gets the selected service point from the shipment field.
   */
  protected function getShipmentServicePoint(ShipmentInterface $shipment): ?array {
    if ($shipment->hasField('shipmondo_service_point') && !$shipment->get('shipmondo_service_point')->isEmpty()) {
      $service_point_json = (string) $shipment->get('shipmondo_service_point')->value;
      $service_point = $service_point_json !== '' ? json_decode($service_point_json, TRUE) : NULL;
      if (is_array($service_point)) {
        return $service_point;
      }
    }

    return NULL;
  }

  /**
   * Gets the Shipmondo service point ID from shipment data.
   */
  protected function getShipmentServicePointId(ShipmentInterface $shipment): ?string {
    $service_point = $this->getShipmentServicePoint($shipment);
    if ($service_point === NULL) {
      return NULL;
    }

    $service_point_id = trim((string) ($service_point['id'] ?? $service_point['number'] ?? ''));
    return $service_point_id !== '' ? $service_point_id : NULL;
  }

  /**
   * Formats a stored service point for summaries.
   */
  protected function formatServicePointLabel(array $service_point): string {
    $parts = array_filter([
      $service_point['name'] ?? '',
      $service_point['address'] ?? '',
      trim(($service_point['zipcode'] ?? '') . ' ' . ($service_point['city'] ?? '')),
    ]);
    return implode(', ', $parts);
  }

  /**
   * Formats a party array as a single-line address for summaries.
   */
  protected function formatPartyLabel(array $party): string {
    $parts = array_filter([
      $party['name'] ?? '',
      $party['attention'] ?? '',
      trim(($party['address1'] ?? '') . ' ' . ($party['address2'] ?? '')),
      trim(($party['postal_code'] ?? '') . ' ' . ($party['city'] ?? '')),
      $party['country_code'] ?? '',
    ]);
    return implode(', ', $parts);
  }

  /**
   * Merges configured, default, and API-required Shipmondo service codes.
   */
  public function resolveServiceCodes(string $product_code, string $country_code, string $configured): string {
    $codes = $this->parseServiceCodes($configured);

    $defaults = $this->parseServiceCodes(
      (string) $this->configFactory->get('commerce_shipmondo.settings')->get('default_service_codes'),
    );
    $codes = array_merge($codes, $defaults);

    if ($product_code !== '' && $country_code !== '') {
      try {
        $required = $this->apiClient->getRequiredServiceCodes($product_code, $country_code);
        $codes = array_merge($codes, $required);
      }
      catch (\Exception $exception) {
        $this->logger->warning('Could not load required Shipmondo services for @product: @message', [
          '@product' => $product_code,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    $codes = array_values(array_unique(array_filter($codes)));
    return implode(',', $codes);
  }

  /**
   * Parses a comma-separated service code string.
   *
   * @return string[]
   */
  protected function parseServiceCodes(string $service_codes): array {
    if ($service_codes === '') {
      return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $service_codes))));
  }

  /**
   * Builds the sender party for the Shipmondo API.
   */
  protected function buildSenderParty(): array {
    $sender = $this->configFactory->get('commerce_shipmondo.settings')->get('sender') ?? [];
    $phone = (string) ($sender['mobile'] ?? '') ?: (string) ($sender['telephone'] ?? '');

    return $this->buildParty('sender', [
      'name' => (string) ($sender['name'] ?? ''),
      'address1' => (string) ($sender['address1'] ?? ''),
      'address2' => (string) ($sender['address2'] ?? ''),
      'postal_code' => (string) ($sender['zipcode'] ?? ''),
      'city' => (string) ($sender['city'] ?? ''),
      'country_code' => (string) ($sender['country_code'] ?? 'DK'),
      'email' => (string) ($sender['email'] ?? ''),
      'phone' => $phone,
    ]);
  }

  /**
   * Builds the receiver party from the shipment shipping profile.
   */
  protected function buildReceiverParty(ShipmentInterface $shipment, OrderInterface $order): array {
    $profile = $shipment->getShippingProfile();
    assert($profile instanceof ProfileInterface);
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $profile->get('address')->first();

    $person_name = trim($address->getGivenName() . ' ' . $address->getFamilyName());
    $organization = trim((string) $address->getOrganization());
    if ($organization !== '') {
      $name = $organization;
      $attention = $person_name !== '' ? $person_name : NULL;
    }
    else {
      $name = $person_name !== '' ? $person_name : ($profile->label() ?: 'Customer');
      $attention = NULL;
    }

    return $this->buildParty('receiver', [
      'name' => $name,
      'attention' => $attention,
      'address1' => (string) $address->getAddressLine1(),
      'address2' => (string) $address->getAddressLine2(),
      'postal_code' => (string) $address->getPostalCode(),
      'city' => (string) $address->getLocality(),
      'country_code' => (string) $address->getCountryCode(),
      'email' => $this->getProfileEmail($profile, $order),
      'phone' => $this->getProfilePhone($profile, $order),
    ]);
  }

  /**
   * Builds a Shipmondo ShipmentParty structure.
   */
  protected function buildParty(string $type, array $data): array {
    $party = [
      'type' => $type,
      'name' => $data['name'],
      'address1' => $data['address1'],
      'postal_code' => $data['postal_code'],
      'city' => $data['city'],
      'country_code' => $data['country_code'],
    ];

    if (!empty($data['address2'])) {
      $party['address2'] = $data['address2'];
    }
    if (!empty($data['attention'])) {
      $party['attention'] = $data['attention'];
    }
    if (!empty($data['email'])) {
      $party['email'] = $data['email'];
    }
    if (!empty($data['phone'])) {
      $party['phone'] = $data['phone'];
    }

    return $party;
  }

  /**
   * Gets receiver email from profile, config, or order.
   */
  protected function getProfileEmail(ProfileInterface $profile, OrderInterface $order): string {
    $configured = trim((string) $this->configFactory->get('commerce_shipmondo.settings')->get('receiver_email_field'));
    if ($configured !== '') {
      $value = $this->getProfileFieldValue($profile, $configured);
      if ($value !== '') {
        return $value;
      }
    }

    $value = $this->getProfileFieldValueFromCandidates($profile, [
      'field_email',
      'field_e_mail',
      'field_mail',
      'mail',
    ]);
    if ($value !== '') {
      return $value;
    }

    return (string) $order->getEmail();
  }

  /**
   * Gets receiver phone from profile, config, or customer user.
   */
  protected function getProfilePhone(ProfileInterface $profile, OrderInterface $order): string {
    $configured = trim((string) $this->configFactory->get('commerce_shipmondo.settings')->get('receiver_phone_field'));
    if ($configured !== '') {
      $value = $this->getProfileFieldValue($profile, $configured);
      if ($value !== '') {
        return $value;
      }
    }

    $value = $this->getProfileFieldValueFromCandidates($profile, [
      'field_phone_number',
      'field_phone',
      'field_telephone',
      'field_telefon',
      'field_telefonnummer',
      'field_mobil',
      'field_mobile',
      'field_mobile_number',
      'phone',
    ]);
    if ($value !== '') {
      return $value;
    }

    $value = $this->discoverProfileFieldValue($profile, '/phone|telefon|mobil|mobile/i');
    if ($value !== '') {
      return $value;
    }

    return $this->getCustomerPhone($order);
  }

  /**
   * Tries a list of profile field machine names.
   */
  protected function getProfileFieldValueFromCandidates(FieldableEntityInterface $entity, array $fieldNames): string {
    foreach ($fieldNames as $fieldName) {
      $value = $this->getProfileFieldValue($entity, $fieldName);
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /**
   * Reads a single profile field value.
   */
  protected function getProfileFieldValue(FieldableEntityInterface $entity, string $fieldName): string {
    if (!$entity->hasField($fieldName)) {
      return '';
    }
    return $this->extractFieldValue($entity->get($fieldName));
  }

  /**
   * Finds the first non-empty field matching a name pattern.
   */
  protected function discoverProfileFieldValue(FieldableEntityInterface $entity, string $pattern): string {
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($fieldName === 'address' || $definition->isComputed()) {
        continue;
      }
      if (!preg_match($pattern, $fieldName)) {
        continue;
      }
      $value = $this->getProfileFieldValue($entity, $fieldName);
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /**
   * Extracts a scalar string from a field item list.
   */
  protected function extractFieldValue(FieldItemListInterface $field): string {
    if ($field->isEmpty()) {
      return '';
    }

    $item = $field->first();
    if ($item === NULL) {
      return '';
    }

    $raw = $item->getValue();
    if (is_array($raw)) {
      foreach (['value', 'phone_number', 'local_number', 'phone', 'telephone', 'mobile'] as $key) {
        if (!empty($raw[$key]) && is_scalar($raw[$key])) {
          return trim((string) $raw[$key]);
        }
      }
    }

    if (isset($item->value) && is_scalar($item->value) && (string) $item->value !== '') {
      return trim((string) $item->value);
    }

    return trim($field->getString());
  }

  /**
   * Gets phone from the order customer account when available.
   */
  protected function getCustomerPhone(OrderInterface $order): string {
    $customer = $order->getCustomer();
    if (!$customer instanceof UserInterface || $customer->isAnonymous()) {
      return '';
    }

    return $this->getProfileFieldValueFromCandidates($customer, [
      'field_phone_number',
      'field_phone',
      'field_telephone',
      'field_telefon',
      'field_telefonnummer',
      'field_mobil',
      'field_mobile',
      'phone',
    ]) ?: $this->discoverProfileFieldValue($customer, '/phone|telefon|mobil|mobile/i');
  }

  /**
   * Validates required contact fields for service codes.
   */
  protected function validateServiceCodes(string $service_codes, array $receiver): void {
    if ($service_codes === '') {
      throw new ShipmondoValidationException(
        'No Shipmondo service codes configured. Add EMAIL_NT on the shipping method or set default service codes under Commerce > Configuration > Shipmondo.',
      );
    }

    $codes = array_map('trim', explode(',', $service_codes));
    foreach ($codes as $code) {
      if (!isset(self::SERVICE_REQUIREMENTS[$code])) {
        continue;
      }
      $required = self::SERVICE_REQUIREMENTS[$code];
      if (empty($receiver[$required])) {
        $hint = $required === 'phone'
          ? 'Add a phone field on the shipping profile (or set the field machine name under Commerce > Configuration > Shipmondo > Receiver contact fields).'
          : 'Add it to the shipping profile or order.';
        throw new ShipmondoValidationException(sprintf(
          'Service code %s requires receiver %s. %s',
          $code,
          $required,
          $hint,
        ));
      }
    }
  }

}
