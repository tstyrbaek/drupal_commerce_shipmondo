<?php

namespace Drupal\commerce_shipmondo\Service;

use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\commerce_shipmondo\Exception\ShipmondoValidationException;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\FileRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates Shipmondo label creation and storage.
 */
class ShipmondoLabelManager {

  public function __construct(
    protected ShipmondoApiClient $apiClient,
    protected OrderShipmentMapper $mapper,
    protected TrackingUrlBuilder $trackingUrlBuilder,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected MessengerInterface $messenger,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Creates a Shipmondo label for the given shipment.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The commerce shipment.
   *
   * @return array
   *   Stored Shipmondo metadata.
   *
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoValidationException
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoApiException
   */
  public function createLabel(ShipmentInterface $shipment): array {
    $payload = $this->mapper->buildPayload($shipment);
    $this->logger->debug('Shipmondo create shipment: @payload', [
      '@payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $response = $this->apiClient->createShipment($payload);

    $shipmondo_id = (int) ($response['id'] ?? $response['shipment_id'] ?? 0);
    if ($shipmondo_id <= 0) {
      throw new ShipmondoApiException('Shipmondo API did not return a shipment ID.', 0, json_encode($response));
    }

    $label_format = (string) $this->configFactory->get('commerce_shipmondo.settings')->get('label_format');
    $label_contents = $this->apiClient->extractLabelFromShipmentResponse($response);
    if ($label_contents === '') {
      $label_contents = $this->apiClient->getShipmentLabels($shipmondo_id, $label_format);
    }
    if ($label_contents === '') {
      throw new ShipmondoApiException(
        'Shipmondo did not return label data. Ensure label_format is set and the shipment exists in your Shipmondo account (test vs. production).',
        0,
        json_encode($response),
      );
    }
    $file = $this->saveLabelFile($shipment, $shipmondo_id, $label_contents, $label_format);

    $tracking_number = (string) ($response['pkg_no'] ?? $response['track_and_trace'] ?? $response['tracking_number'] ?? '');
    $carrier_code = (string) ($response['carrier_code'] ?? '');
    $tracking_url = $carrier_code !== '' && $tracking_number !== ''
      ? $this->trackingUrlBuilder->buildUri($carrier_code, $tracking_number)
      : '';

    $metadata = [
      'shipment_id' => $shipmondo_id,
      'tracking_number' => $tracking_number,
      'carrier_code' => $carrier_code,
      'tracking_url' => $tracking_url,
      'label_fid' => (int) $file->id(),
      'created' => time(),
    ];

    $shipment->setData('commerce_shipmondo', $metadata);
    if ($tracking_number !== '') {
      $shipment->setTrackingCode($tracking_number);
    }
    $shipment->save();

    $this->logger->info('Created Shipmondo label @id for commerce shipment @shipment.', [
      '@id' => $shipmondo_id,
      '@shipment' => $shipment->id(),
    ]);

    return $metadata;
  }

  /**
   * Saves label binary data as a managed file.
   */
  protected function saveLabelFile(ShipmentInterface $shipment, int $shipmondo_id, string $contents, string $label_format): object {
    $directory = 'private://shipmondo_labels';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $extension = str_contains($label_format, 'pdf') ? 'pdf' : 'bin';
    $filename = sprintf('shipmondo-%d-shipment-%d.%s', $shipmondo_id, $shipment->id(), $extension);
    $uri = $directory . '/' . $filename;

    return $this->fileRepository->writeData($contents, $uri, FileExists::Replace);
  }

  /**
   * Loads stored label file for a shipment.
   */
  public function getLabelFile(ShipmentInterface $shipment): ?object {
    $data = $shipment->getData('commerce_shipmondo') ?? [];
    if (empty($data['label_fid'])) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('file')->load($data['label_fid']);
  }

}
