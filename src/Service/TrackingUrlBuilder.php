<?php

namespace Drupal\commerce_shipmondo\Service;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Url;

/**
 * Builds Shipmondo track & trace URLs for commerce shipments.
 */
class TrackingUrlBuilder {

  private const TRACKING_BASE_URI = 'https://track.shipmondo.com';

  /**
   * Builds a Shipmondo tracking URL for a shipment, if possible.
   */
  public function buildUrl(ShipmentInterface $shipment): ?Url {
    $data = $shipment->getData('commerce_shipmondo') ?? [];

    $stored_url = trim((string) ($data['tracking_url'] ?? ''));
    if ($stored_url !== '') {
      return Url::fromUri($stored_url, ['external' => TRUE]);
    }

    $carrier_code = trim((string) ($data['carrier_code'] ?? ''));
    $tracking_number = trim((string) ($shipment->getTrackingCode() ?: ($data['tracking_number'] ?? '')));
    if ($carrier_code === '' || $tracking_number === '') {
      return NULL;
    }

    return Url::fromUri($this->buildUri($carrier_code, $tracking_number), ['external' => TRUE]);
  }

  /**
   * Builds a Shipmondo tracking URI from carrier code and package number.
   */
  public function buildUri(string $carrier_code, string $tracking_number): string {
    $carrier_code = rawurlencode(trim($carrier_code));
    $tracking_number = rawurlencode(trim($tracking_number));

    return self::TRACKING_BASE_URI . '/' . $carrier_code . '/' . $tracking_number;
  }

}
