<?php

namespace Drupal\commerce_shipmondo_checkout\Service;

use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\commerce_shipmondo\Service\ShippingMethodMapping;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds mount settings for the service point selector UI.
 */
class ServicePointSelectorSettingsBuilder {

  public function __construct(
    protected ServicePointCarrierMapping $carrierMapping,
    protected ShippingMethodMapping $shippingMethodMapping,
    protected ServicePointService $servicePointService,
    protected LanguageManagerInterface $languageManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Builds selector settings for a shipment form.
   *
   * @return array<string, string>|null
   *   Mount settings, or NULL when the selector cannot be shown.
   */
  public function buildForShipment(ShipmentInterface $shipment, ?FormStateInterface $form_state = NULL): ?array {
    $method_id = $this->resolveShippingMethodId($shipment, $form_state);
    if ($method_id === NULL) {
      return NULL;
    }

    $carrier_code = $this->carrierMapping->getCarrierCodeForShippingMethod($method_id);
    if ($carrier_code === '') {
      return NULL;
    }

    $shipping_method = $shipment->getShippingMethod();
    if (!$shipping_method instanceof ShippingMethodInterface || (string) $shipping_method->id() !== $method_id) {
      $shipping_method = \Drupal::entityTypeManager()
        ->getStorage('commerce_shipping_method')
        ->load($method_id);
    }

    $preferred_product_code = '';
    if ($shipping_method instanceof ShippingMethodInterface) {
      $mapping = $this->shippingMethodMapping->get($shipping_method);
      $preferred_product_code = trim((string) ($mapping['product_code'] ?? ''));
    }

    try {
      $product_code = $this->servicePointService->resolveServicePointProductCode(
        $carrier_code,
        $preferred_product_code !== '' ? $preferred_product_code : NULL,
      );
    }
    catch (ShipmondoApiException $exception) {
      $this->logger->warning('Could not load Shipmondo service point products for shipment @id: @message', [
        '@id' => $shipment->id() ?? 'new',
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    $address = $this->resolveShippingAddress($shipment, $form_state);
    if (!$address instanceof AddressItem) {
      return NULL;
    }

    $zip = trim((string) $address->getPostalCode());
    if ($zip === '') {
      return NULL;
    }

    return [
      'servicePointApiUrl' => Url::fromRoute('commerce_shipmondo_checkout.service_points')->toString(),
      'carrierCode' => $carrier_code,
      'productCode' => $product_code,
      'zipCode' => $zip,
      'address' => $this->formatAddressLine($address),
      'city' => trim((string) $address->getLocality()),
      'countryCode' => (string) ($address->getCountryCode() ?: 'DK'),
      'language' => $this->languageManager->getCurrentLanguage()->getId(),
    ];
  }

  /**
   * Resolves the selected shipping method ID from shipment state or form input.
   */
  protected function resolveShippingMethodId(ShipmentInterface $shipment, ?FormStateInterface $form_state): ?string {
    if ($form_state instanceof FormStateInterface) {
      $selected_rate = NestedArray::getValue($form_state->getUserInput(), ['shipping_method', 0]);
      if (!is_string($selected_rate) || $selected_rate === '') {
        $selected_rate = NestedArray::getValue($form_state->getValues(), ['shipping_method', 0]);
      }
      if (is_string($selected_rate) && str_contains($selected_rate, '--')) {
        return explode('--', $selected_rate, 2)[0];
      }
    }

    $shipping_method = $shipment->getShippingMethod();
    return $shipping_method ? (string) $shipping_method->id() : NULL;
  }

  /**
   * Resolves the shipping address from the shipment or current form values.
   */
  protected function resolveShippingAddress(ShipmentInterface $shipment, ?FormStateInterface $form_state): ?AddressItem {
    if ($form_state instanceof FormStateInterface) {
      $address_values = NestedArray::getValue($form_state->getUserInput(), [
        'shipping_profile',
        0,
        'profile',
        'address',
        0,
        'address',
      ]);
      if (!is_array($address_values)) {
        $address_values = NestedArray::getValue($form_state->getValues(), [
          'shipping_profile',
          0,
          'profile',
          'address',
          0,
          'address',
        ]);
      }
      if (is_array($address_values) && !empty($address_values['postal_code'])) {
        $profile = $shipment->getShippingProfile();
        if ($profile instanceof ProfileInterface) {
          $profile->get('address')->setValue([$address_values]);
          $address = $profile->get('address')->first();
          if ($address instanceof AddressItem) {
            return $address;
          }
        }
      }
    }

    $profile = $shipment->getShippingProfile();
    if (!$profile instanceof ProfileInterface || !$profile->hasField('address')) {
      return NULL;
    }

    $address = $profile->get('address')->first();
    return $address instanceof AddressItem ? $address : NULL;
  }

  /**
   * Formats the street address for service point lookup.
   */
  protected function formatAddressLine(AddressItem $address): string {
    $lines = array_filter([
      trim((string) $address->getAddressLine1()),
      trim((string) $address->getAddressLine2()),
    ]);

    return implode(' ', $lines);
  }

}
