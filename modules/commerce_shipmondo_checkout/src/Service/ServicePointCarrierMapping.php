<?php

namespace Drupal\commerce_shipmondo_checkout\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves Shipmondo carrier codes for Commerce shipping methods.
 */
class ServicePointCarrierMapping {

  private const STORAGE_PREFIX = 'shipping_method_';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the mapped Shipmondo carrier code for a shipping method.
   */
  public function getCarrierCodeForShippingMethod(string $method_id): string {
    $method_id = trim($method_id);
    if ($method_id === '') {
      return '';
    }

    return $this->loadCarrierMapping()[$method_id] ?? '';
  }

  /**
   * Whether the shipping method is configured for service point checkout.
   */
  public function isServicePointShippingMethod(string $method_id): bool {
    return $this->getCarrierCodeForShippingMethod($method_id) !== '';
  }

  /**
   * Loads carrier mapping from all checkout flows.
   *
   * @return array<string, string>
   *   Shipping method IDs keyed to carrier codes.
   */
  public function loadCarrierMapping(): array {
    $mapping = [];
    $storage = $this->entityTypeManager->getStorage('commerce_checkout_flow');
    foreach ($storage->loadMultiple() as $checkout_flow) {
      $pane_config = $checkout_flow->getPlugin()->getConfiguration()['panes']['commerce_shipmondo_service_point'] ?? [];
      $carrier_mapping = $pane_config['carrier_mapping'] ?? [];
      if (!is_array($carrier_mapping)) {
        continue;
      }

      foreach ($carrier_mapping as $key => $carrier_code) {
        $carrier_code = trim((string) $carrier_code);
        if ($carrier_code === '') {
          continue;
        }

        $method_id = $this->resolveMethodIdFromStorageKey((string) $key);
        if ($method_id !== '') {
          $mapping[$method_id] = $carrier_code;
        }
      }
    }

    return $mapping;
  }

  /**
   * Resolves a shipping method ID from a carrier mapping storage key.
   */
  protected function resolveMethodIdFromStorageKey(string $key): string {
    if (str_starts_with($key, self::STORAGE_PREFIX)) {
      return substr($key, strlen(self::STORAGE_PREFIX));
    }

    return is_numeric($key) ? '' : $key;
  }

}
