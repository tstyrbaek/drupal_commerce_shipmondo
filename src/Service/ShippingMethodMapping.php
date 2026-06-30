<?php

namespace Drupal\commerce_shipmondo\Service;

use Drupal\commerce_shipping\Entity\ShippingMethodInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Stores Shipmondo product mappings per commerce shipping method.
 */
class ShippingMethodMapping {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets the Shipmondo mapping for a shipping method.
   *
   * @return array{product_code?: string, service_codes?: string}
   *   Mapping with product_code and service_codes keys.
   */
  public function get(ShippingMethodInterface $shipping_method): array {
    $mappings = $this->configFactory->get('commerce_shipmondo.shipping_methods')->get('mappings') ?? [];
    $id = (string) $shipping_method->id();
    return $mappings[$id] ?? [];
  }

  /**
   * Saves the Shipmondo mapping for a shipping method.
   *
   * @param array{product_code?: string, service_codes?: string} $mapping
   *   The mapping to store.
   */
  public function set(ShippingMethodInterface $shipping_method, array $mapping): void {
    $config = $this->configFactory->getEditable('commerce_shipmondo.shipping_methods');
    $mappings = $config->get('mappings') ?? [];
    $id = (string) $shipping_method->id();
    $mappings[$id] = [
      'product_code' => trim((string) ($mapping['product_code'] ?? '')),
      'service_codes' => trim((string) ($mapping['service_codes'] ?? '')),
    ];
    $config->set('mappings', $mappings)->save();
  }

}
