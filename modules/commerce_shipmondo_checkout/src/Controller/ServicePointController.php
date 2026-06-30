<?php

namespace Drupal\commerce_shipmondo_checkout\Controller;

use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\commerce_shipmondo_checkout\Service\ServicePointService;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * JSON endpoint for service point lookup during checkout.
 */
class ServicePointController implements ContainerInjectionInterface {

  public function __construct(
    protected ServicePointService $servicePointService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_shipmondo_checkout.service_point'),
    );
  }

  /**
   * Returns nearby service points as JSON.
   */
  public function list(Request $request): JsonResponse {
    $zip = trim((string) $request->query->get('zip', ''));
    $carrier = trim((string) $request->query->get('carrier', ''));
    $product = trim((string) $request->query->get('product', ''));
    $country = strtoupper(trim((string) $request->query->get('country', 'DK')));
    $address = trim((string) $request->query->get('address', ''));
    $city = trim((string) $request->query->get('city', ''));

    if ($zip === '' || $carrier === '' || $product === '') {
      throw new BadRequestHttpException('Query parameters "zip", "carrier", and "product" are required.');
    }

    try {
      $this->assertValidServicePointProduct($carrier, $product);
      $service_points = $this->servicePointService->getServicePoints(
        $carrier,
        $zip,
        $product,
        $country,
        $address !== '' ? $address : NULL,
        $city !== '' ? $city : NULL,
      );
    }
    catch (ShipmondoApiException $exception) {
      if ($exception->statusCode >= 400 && $exception->statusCode < 500) {
        throw new BadRequestHttpException($exception->getMessage(), $exception);
      }
      throw new ServiceUnavailableHttpException(NULL, $exception->getMessage(), $exception);
    }

    return new JsonResponse([
      'carrier' => $carrier,
      'zip' => $zip,
      'product' => $product,
      'country' => $country,
      'address' => $address !== '' ? $address : NULL,
      'city' => $city !== '' ? $city : NULL,
      'service_points' => $service_points,
    ]);
  }

  /**
   * Validates that a product code is a service point product for the carrier.
   */
  protected function assertValidServicePointProduct(string $carrier, string $product): void {
    if ($this->servicePointService->isServicePointProduct($carrier, $product)) {
      return;
    }

    throw new BadRequestHttpException(sprintf(
      'Product "%s" is not a valid service point product for carrier "%s".',
      $product,
      $carrier,
    ));
  }

}
