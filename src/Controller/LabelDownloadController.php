<?php

namespace Drupal\commerce_shipmondo\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipmondo\Service\ShipmondoLabelManager;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Downloads stored Shipmondo label files.
 */
class LabelDownloadController extends ControllerBase {

  public function __construct(
    protected ShipmondoLabelManager $labelManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_shipmondo.label_manager'),
    );
  }

  /**
   * Streams the label PDF for a shipment.
   */
  public function download(OrderInterface $commerce_order, ShipmentInterface $commerce_shipment, Request $request): BinaryFileResponse {
    if ((int) $commerce_shipment->getOrderId() !== (int) $commerce_order->id()) {
      throw new NotFoundHttpException();
    }

    $file = $this->labelManager->getLabelFile($commerce_shipment);
    if (!$file) {
      throw new NotFoundHttpException('No Shipmondo label found for this shipment.');
    }

    $mime_type = $file->getMimeType() ?: 'application/pdf';
    $disposition = str_contains($mime_type, 'pdf') ? 'inline' : 'attachment';
    $headers = [
      'Content-Type' => $mime_type,
      'Content-Disposition' => $disposition . '; filename="' . $file->getFilename() . '"',
    ];

    return new BinaryFileResponse($file->getFileUri(), 200, $headers, TRUE);
  }

}
