<?php

namespace Drupal\commerce_shipmondo_checkout\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;

/**
 * Copies order service point selections onto matching shipments.
 */
class ServicePointOrderSync {

  public function __construct(
    protected ServicePointCarrierMapping $carrierMapping,
  ) {}

  /**
   * Syncs the order service point to all eligible shipments.
   */
  public function syncOrderToShipments(OrderInterface $order): void {
    if (!$order->hasField('shipments')) {
      return;
    }

    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      if ($shipment instanceof ShipmentInterface) {
        $this->syncOrderToShipment($shipment);
      }
    }
  }

  /**
   * Syncs the order service point to a single shipment when applicable.
   */
  public function syncOrderToShipment(ShipmentInterface $shipment): void {
    if (!$shipment->hasField('shipmondo_service_point')) {
      return;
    }

    if (!$shipment->get('shipmondo_service_point')->isEmpty()) {
      return;
    }

    $order = $shipment->getOrder();
    if (!$order instanceof OrderInterface) {
      return;
    }

    $service_point = $this->getOrderServicePoint($order);
    if ($service_point === NULL || !$this->shouldSyncToShipment($service_point, $shipment)) {
      return;
    }

    $shipment->set(
      'shipmondo_service_point',
      json_encode($this->prepareServicePointForShipment($service_point, $shipment), JSON_UNESCAPED_UNICODE),
    );
    $shipment->save();
  }

  /**
   * Resolves the service point to display for a shipment.
   *
   * Falls back to the order field when the shipment copy is still empty.
   */
  public function resolveServicePointForDisplay(ShipmentInterface $shipment): ?array {
    $service_point = $this->getShipmentServicePoint($shipment);
    if ($service_point !== NULL) {
      return $service_point;
    }

    $order = $shipment->getOrder();
    if (!$order instanceof OrderInterface) {
      return NULL;
    }

    $order_service_point = $this->getOrderServicePoint($order);
    if ($order_service_point === NULL || !$this->shouldSyncToShipment($order_service_point, $shipment)) {
      return NULL;
    }

    return $this->prepareServicePointForShipment($order_service_point, $shipment);
  }

  /**
   * Whether the order service point should be copied to the shipment.
   */
  public function shouldSyncToShipment(array $service_point, ShipmentInterface $shipment): bool {
    $shipping_method = $shipment->getShippingMethod();
    if (!$shipping_method) {
      return FALSE;
    }

    $method_id = (string) $shipping_method->id();
    if (!$this->carrierMapping->isServicePointShippingMethod($method_id)) {
      return FALSE;
    }

    $order_carrier = strtolower(trim((string) ($service_point['carrier_code'] ?? '')));
    $shipment_carrier = strtolower($this->carrierMapping->getCarrierCodeForShippingMethod($method_id));
    if ($order_carrier === '' || $shipment_carrier === '') {
      return FALSE;
    }

    return $order_carrier === $shipment_carrier;
  }

  /**
   * Gets the decoded service point from the shipment field.
   */
  protected function getShipmentServicePoint(ShipmentInterface $shipment): ?array {
    if (!$shipment->hasField('shipmondo_service_point') || $shipment->get('shipmondo_service_point')->isEmpty()) {
      return NULL;
    }

    return $this->decodeServicePointJson((string) $shipment->get('shipmondo_service_point')->value);
  }

  /**
   * Gets the decoded service point from the order field.
   */
  protected function getOrderServicePoint(OrderInterface $order): ?array {
    if (!$order->hasField('shipmondo_service_point') || $order->get('shipmondo_service_point')->isEmpty()) {
      return NULL;
    }

    return $this->decodeServicePointJson((string) $order->get('shipmondo_service_point')->value);
  }

  /**
   * Decodes stored service point JSON.
   */
  protected function decodeServicePointJson(string $service_point_json): ?array {
    if ($service_point_json === '') {
      return NULL;
    }

    $service_point = json_decode($service_point_json, TRUE);
    return is_array($service_point) && !empty($service_point['id']) ? $service_point : NULL;
  }

  /**
   * Ensures the shipment copy carries the shipment method carrier code.
   */
  protected function prepareServicePointForShipment(array $service_point, ShipmentInterface $shipment): array {
    $shipping_method = $shipment->getShippingMethod();
    if ($shipping_method) {
      $carrier_code = $this->carrierMapping->getCarrierCodeForShippingMethod((string) $shipping_method->id());
      if ($carrier_code !== '') {
        $service_point['carrier_code'] = $carrier_code;
      }
    }

    return $service_point;
  }

}
