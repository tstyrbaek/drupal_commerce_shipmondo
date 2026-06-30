<?php

namespace Drupal\commerce_shipmondo_checkout\Service;

use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Wrapper for Shipmondo Shipping Module and service point APIs.
 *
 * Supports frontend_key query auth or Basic Auth via configured API keys.
 */
class ServicePointService {

  private const PRODUCTION_BASE_URI = 'https://app.shipmondo.com/api/public/v3';

  private const SANDBOX_BASE_URI = 'https://sandbox.shipmondo.com/api/public/v3';

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns carriers available on the Shipmondo account.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getCarriers(): array {
    $response = $this->request('GET', '/shipping_modules/carriers');
    return $this->extractList($response, ['carriers', 'data', 'items']);
  }

  /**
   * Returns carrier options for select elements.
   *
   * Keys are carrier codes; values are human-readable labels.
   *
   * @return array<string, string>
   */
  public function getCarrierOptions(): array {
    $options = [];
    foreach ($this->getCarriers() as $carrier) {
      if (!is_array($carrier)) {
        continue;
      }
      $code = trim((string) ($carrier['code'] ?? $carrier['carrier_code'] ?? ''));
      if ($code === '') {
        continue;
      }
      $name = trim((string) ($carrier['name'] ?? $code));
      $options[$code] = $name . ' (' . $code . ')';
    }
    ksort($options);
    return $options;
  }

  /**
   * Returns products for a carrier, optionally filtered to service points.
   *
   * @param string $carrier_code
   *   Shipmondo carrier code.
   * @param bool $service_point_only
   *   When TRUE, only products with service_point_product = TRUE are returned.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getProducts(string $carrier_code, bool $service_point_only = FALSE): array {
    $carrier_code = trim($carrier_code);
    if ($carrier_code === '') {
      throw new ShipmondoApiException('Carrier code is required.');
    }

    $response = $this->request('GET', '/shipping_modules/products', [
      'carrier_code' => $carrier_code,
    ]);
    $products = $this->extractList($response, ['products', 'data', 'items']);

    if (!$service_point_only) {
      return $products;
    }

    return array_values(array_filter($products, static function (array $product): bool {
      return !empty($product['service_point_product']);
    }));
  }

  /**
   * Extracts a product code from a Shipmondo product record.
   */
  public function getProductCode(array $product): string {
    return trim((string) ($product['code'] ?? $product['product_code'] ?? ''));
  }

  /**
   * Whether a product code is a service point product for a carrier.
   */
  public function isServicePointProduct(string $carrier_code, string $product_code): bool {
    $product_code = trim($product_code);
    if ($product_code === '') {
      return FALSE;
    }

    foreach ($this->getProducts($carrier_code, TRUE) as $product) {
      if ($this->getProductCode($product) === $product_code) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolves a service point product code for a carrier.
   *
   * Uses the preferred product when it is a valid service point product;
   * otherwise falls back to the first available service point product.
   */
  public function resolveServicePointProductCode(string $carrier_code, ?string $preferred_product_code = NULL): ?string {
    $carrier_code = trim($carrier_code);
    if ($carrier_code === '') {
      return NULL;
    }

    $preferred_product_code = trim((string) $preferred_product_code);
    $service_point_products = $this->getProducts($carrier_code, TRUE);
    if ($service_point_products === []) {
      return NULL;
    }

    if ($preferred_product_code !== '' && $this->isServicePointProduct($carrier_code, $preferred_product_code)) {
      return $preferred_product_code;
    }

    return $this->getProductCode($service_point_products[0]) ?: NULL;
  }

  /**
   * Returns nearby service points for a carrier and destination address.
   *
   * @param string $carrier_code
   *   Shipmondo carrier code.
   * @param string $zip_code
   *   Destination postal code.
   * @param string $product_code
   *   Shipmondo product code for the service point shipment.
   * @param string $country_code
   *   ISO 3166-1 alpha-2 destination country code.
   * @param string|null $address
   *   Optional street address for distance-based sorting.
   * @param string|null $city
   *   Optional city name for distance-based sorting.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getServicePoints(
    string $carrier_code,
    string $zip_code,
    string $product_code,
    string $country_code = 'DK',
    ?string $address = NULL,
    ?string $city = NULL,
  ): array {
    $carrier_code = trim($carrier_code);
    $zip_code = trim($zip_code);
    $product_code = trim($product_code);
    $country_code = strtoupper(trim($country_code));
    $address = trim((string) $address);
    $city = trim((string) $city);
    if ($carrier_code === '' || $zip_code === '' || $product_code === '' || $country_code === '') {
      throw new ShipmondoApiException('Carrier code, postal code, product code, and country code are required.');
    }

    $query = [
      'carrier_code' => $carrier_code,
      'zipcode' => $zip_code,
      'product_code' => $product_code,
      'country_code' => $country_code,
    ];
    if ($address !== '') {
      $query['address'] = $address;
    }
    if ($city !== '') {
      $query['city'] = $city;
    }

    $response = $this->request('GET', '/service_point/service_points', $query);

    return $this->normalizeServicePoints(
      $this->extractList($response, ['service_points', 'data', 'items']),
    );
  }

  /**
   * Performs an authenticated Shipping Module API request.
   *
   * @return array<string, mixed>|array<int, array<string, mixed>>
   */
  protected function request(string $method, string $path, array $query = []): array {
    $options = [
      'headers' => [
        'Accept' => 'application/json',
      ],
      'query' => $query,
    ];
    $this->applyRequestAuthentication($options, $query);
    $options['query'] = $query;

    try {
      $response = $this->httpClient->request($method, $this->getBaseUri() . $path, $options);
    }
    catch (RequestException $exception) {
      $status = $exception->hasResponse() ? $exception->getResponse()->getStatusCode() : 0;
      $body = $exception->hasResponse() ? (string) $exception->getResponse()->getBody() : '';
      $this->logger->error('Shipmondo Shipping Module API request failed (@status) on @path: @body', [
        '@status' => $status,
        '@path' => $path,
        '@body' => $body,
      ]);
      throw new ShipmondoApiException(
        $this->parseErrorMessage($body, $exception->getMessage()),
        $status,
        $body,
        $exception,
      );
    }
    catch (\Exception $exception) {
      $this->logger->error('Shipmondo Shipping Module API request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new ShipmondoApiException(
        'Shipmondo Shipping Module API request failed: ' . $exception->getMessage(),
        0,
        '',
        $exception,
      );
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status < 200 || $status >= 300) {
      $this->logger->error('Shipmondo Shipping Module API error (@status) on @path: @body', [
        '@status' => $status,
        '@path' => $path,
        '@body' => $body,
      ]);
      throw new ShipmondoApiException($this->parseErrorMessage($body), $status, $body);
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new ShipmondoApiException(
        'Invalid JSON response from Shipmondo Shipping Module API.',
        $status,
        $body,
      );
    }

    return $decoded;
  }

  /**
   * Applies frontend_key or Basic Auth to a Shipping Module API request.
   */
  protected function applyRequestAuthentication(array &$options, array &$query): void {
    $frontend_key = $this->getFrontendKey(FALSE);
    if ($frontend_key !== '') {
      $query['frontend_key'] = $frontend_key;
      return;
    }

    if ($this->hasBasicAuthCredentials()) {
      $options['headers'] = ($options['headers'] ?? []) + $this->getAuthHeaders();
      return;
    }

    throw new ShipmondoApiException(
      'Shipmondo service point API credentials are not configured. Configure a frontend key or API user/key.',
    );
  }

  /**
   * Whether API user and key are configured for Basic Auth.
   */
  protected function hasBasicAuthCredentials(): bool {
    $config = $this->configFactory->get('commerce_shipmondo.settings');
    return (string) $config->get('api_user_key') !== '' && (string) $config->get('api_key_key') !== '';
  }

  /**
   * Builds Basic Auth headers from configured API keys.
   *
   * @return array<string, string>
   */
  protected function getAuthHeaders(): array {
    $config = $this->configFactory->get('commerce_shipmondo.settings');
    $api_user = $this->getKeyValue((string) $config->get('api_user_key'));
    $api_key = $this->getKeyValue((string) $config->get('api_key_key'));
    $token = base64_encode($api_user . ':' . $api_key);

    return [
      'Authorization' => 'Basic ' . $token,
    ];
  }

  /**
   * Returns the configured frontend key value when available.
   */
  protected function getFrontendKey(bool $required = TRUE): string {
    $key_id = (string) $this->configFactory->get('commerce_shipmondo.settings')->get('frontend_key_key');
    if ($key_id === '') {
      if ($required) {
        throw new ShipmondoApiException('Shipmondo frontend key is not configured.');
      }
      return '';
    }

    $key = $this->keyRepository->getKey($key_id);
    if (!$key) {
      throw new ShipmondoApiException(sprintf('Configured Shipmondo frontend key "%s" was not found.', $key_id));
    }

    $value = (string) $key->getKeyValue();
    if ($value === '') {
      throw new ShipmondoApiException(sprintf('Configured Shipmondo frontend key "%s" has no value.', $key_id));
    }

    return $value;
  }

  /**
   * Loads a key value by key entity ID.
   */
  protected function getKeyValue(string $keyId): string {
    if ($keyId === '') {
      throw new ShipmondoApiException('Shipmondo API credentials are not configured.');
    }
    $key = $this->keyRepository->getKey($keyId);
    if (!$key) {
      throw new ShipmondoApiException(sprintf('Configured Shipmondo key "%s" was not found.', $keyId));
    }
    $value = (string) $key->getKeyValue();
    if ($value === '') {
      throw new ShipmondoApiException(sprintf('Configured Shipmondo key "%s" has no value.', $keyId));
    }
    return $value;
  }

  /**
   * Returns the API base URI for production or sandbox.
   */
  protected function getBaseUri(): string {
    if ($this->configFactory->get('commerce_shipmondo.settings')->get('use_sandbox')) {
      return self::SANDBOX_BASE_URI;
    }
    return self::PRODUCTION_BASE_URI;
  }

  /**
   * Normalizes a list response from known wrapper keys.
   *
   * @param array<string, mixed>|array<int, array<string, mixed>> $response
   * @param string[] $keys
   *
   * @return array<int, array<string, mixed>>
   */
  protected function extractList(array $response, array $keys): array {
    if (isset($response[0]) && is_array($response[0])) {
      return $response;
    }

    foreach ($keys as $key) {
      if (isset($response[$key]) && is_array($response[$key])) {
        return $response[$key];
      }
    }

    return [];
  }

  /**
   * Normalizes service point records for the JSON API.
   *
   * @param array<int, array<string, mixed>> $service_points
   *
   * @return array<int, array<string, mixed>>
   */
  protected function normalizeServicePoints(array $service_points): array {
    $normalized = [];
    foreach ($service_points as $service_point) {
      if (!is_array($service_point)) {
        continue;
      }
      $normalized[] = [
        'id' => $service_point['id'] ?? $service_point['number'] ?? NULL,
        'number' => $service_point['number'] ?? $service_point['id'] ?? NULL,
        'name' => (string) ($service_point['name'] ?? ''),
        'address' => (string) ($service_point['address'] ?? ''),
        'zipcode' => (string) ($service_point['zipcode'] ?? $service_point['zip_code'] ?? ''),
        'city' => (string) ($service_point['city'] ?? ''),
        'distance' => $service_point['distance'] ?? NULL,
        'longitude' => $service_point['longitude'] ?? NULL,
        'latitude' => $service_point['latitude'] ?? NULL,
        'opening_hours' => $service_point['opening_hours'] ?? [],
      ];
    }
    return $normalized;
  }

  /**
   * Extracts a human-readable message from an API error body.
   */
  protected function parseErrorMessage(string $body, string $fallback = 'Shipmondo Shipping Module API returned an error.'): string {
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      return $fallback;
    }
    return (string) ($decoded['error'] ?? $decoded['message'] ?? $fallback);
  }

}
