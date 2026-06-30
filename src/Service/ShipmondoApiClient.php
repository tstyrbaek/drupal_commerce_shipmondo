<?php

namespace Drupal\commerce_shipmondo\Service;

use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the Shipmondo API v3.
 */
class ShipmondoApiClient {

  private const PRODUCTION_BASE_URI = 'https://app.shipmondo.com/api/public/v3';

  private const SANDBOX_BASE_URI = 'https://sandbox.shipmondo.com/api/public/v3';

  private const API_CATALOG_CACHE_TTL = 3600;

  private const API_CATALOG_CACHE_TAG = 'commerce_shipmondo_api_catalog';

  private const PRODUCTS_PER_PAGE = 50;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected LoggerInterface $logger,
    protected CacheBackendInterface $cache,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Creates a shipment in Shipmondo.
   *
   * @param array $payload
   *   The shipment payload.
   *
   * @return array
   *   The decoded API response.
   *
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoApiException
   */
  public function createShipment(array $payload): array {
    return $this->request('POST', '/shipments', ['json' => $payload]);
  }

  /**
   * Gets required service codes for a product in a destination country.
   *
   * @return string[]
   *   Service codes such as EMAIL_NT.
   */
  public function getRequiredServiceCodes(string $product_code, string $country_code): array {
    $response = $this->request('GET', '/products', [
      'query' => [
        'receiver_country_code' => $country_code,
        'product_code' => $product_code,
      ],
    ]);

    foreach ($this->extractProductList($response) as $product) {
      if (!is_array($product) || ($product['code'] ?? '') !== $product_code) {
        continue;
      }
      $codes = [];
      foreach ($product['required_services'] ?? [] as $service) {
        if (is_array($service) && !empty($service['code'])) {
          $codes[] = (string) $service['code'];
        }
      }
      return $codes;
    }

    return [];
  }

  /**
   * Returns product code options for form widgets.
   *
   * Keys are product codes; values are human-readable labels.
   *
   * @param string|null $sender_country_code
   *   ISO 3166-1 alpha-2 sender country filter.
   * @param string|null $receiver_country_code
   *   ISO 3166-1 alpha-2 receiver country filter.
   *
   * @return array<string, string>
   *   Product options keyed by code.
   */
  public function getProductOptions(?string $sender_country_code = NULL, ?string $receiver_country_code = NULL): array {
    $config = $this->configFactory->get('commerce_shipmondo.settings');
    $cache_key = implode(':', [
      'commerce_shipmondo_product_options',
      $config->get('use_sandbox') ? 'sandbox' : 'production',
      (string) $config->get('api_user_key'),
      $sender_country_code ?? '',
      $receiver_country_code ?? '',
    ]);

    if ($cache = $this->cache->get($cache_key)) {
      return $cache->data;
    }

    $options = [];
    foreach ($this->getAllProducts($this->buildProductsQuery($sender_country_code, $receiver_country_code)) as $product) {
      if (!is_array($product) || empty($product['code'])) {
        continue;
      }
      if (array_key_exists('available', $product) && $product['available'] === FALSE) {
        continue;
      }
      $code = (string) $product['code'];
      $name = (string) ($product['name'] ?? $code);
      $carrier = is_array($product['carrier'] ?? NULL)
        ? (string) ($product['carrier']['name'] ?? '')
        : '';
      $label = $name . ' (' . $code . ')';
      if ($carrier !== '') {
        $label = $carrier . ' — ' . $label;
      }
      $options[$code] = $label;
    }

    ksort($options);
    $this->cache->set(
      $cache_key,
      $options,
      time() + self::API_CATALOG_CACHE_TTL,
      [self::API_CATALOG_CACHE_TAG],
    );
    return $options;
  }

  /**
   * Returns service code options for form widgets.
   *
   * Keys are service codes; values are human-readable labels.
   *
   * @param string|null $sender_country_code
   *   ISO 3166-1 alpha-2 sender country filter.
   * @param string|null $receiver_country_code
   *   ISO 3166-1 alpha-2 receiver country filter.
   * @param string|null $product_code
   *   Optional Shipmondo product code filter.
   *
   * @return array<string, string>
   *   Service options keyed by code.
   */
  public function getServiceOptions(?string $sender_country_code = NULL, ?string $receiver_country_code = NULL, ?string $product_code = NULL): array {
    $config = $this->configFactory->get('commerce_shipmondo.settings');
    $cache_key = implode(':', [
      'commerce_shipmondo_service_options',
      $config->get('use_sandbox') ? 'sandbox' : 'production',
      (string) $config->get('api_user_key'),
      $sender_country_code ?? '',
      $receiver_country_code ?? '',
      $product_code ?? '',
    ]);

    if ($cache = $this->cache->get($cache_key)) {
      return $cache->data;
    }

    $options = [];
    foreach ($this->getAllProducts($this->buildProductsQuery($sender_country_code, $receiver_country_code, $product_code)) as $product) {
      if (!is_array($product)) {
        continue;
      }
      foreach (['available_services', 'required_services'] as $service_key) {
        foreach ($product[$service_key] ?? [] as $service) {
          if (!is_array($service) || empty($service['code'])) {
            continue;
          }
          $code = (string) $service['code'];
          $name = (string) ($service['name'] ?? $code);
          $options[$code] = $name . ' (' . $code . ')';
        }
      }
    }

    ksort($options);
    $this->cache->set(
      $cache_key,
      $options,
      time() + self::API_CATALOG_CACHE_TTL,
      [self::API_CATALOG_CACHE_TAG],
    );
    return $options;
  }

  /**
   * Clears cached Shipmondo API catalog data used by admin forms.
   */
  public function invalidateServiceOptionsCache(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::API_CATALOG_CACHE_TAG]);
  }

  /**
   * Converts checkbox values to a comma-separated service code string.
   */
  public static function selectedServiceCodesToString(array $values): string {
    return implode(',', array_keys(array_filter($values)));
  }

  /**
   * Converts a comma-separated service code string to checkbox defaults.
   *
   * @return array<string, string>
   */
  public static function serviceCodesToDefaultValues(string $service_codes): array {
    $codes = array_values(array_filter(array_map('trim', explode(',', $service_codes))));
    if ($codes === []) {
      return [];
    }
    return array_combine($codes, $codes);
  }

  /**
   * Extracts label binary data from a create-shipment API response.
   *
   * Labels are included when label_format was sent in the create request.
   */
  public function extractLabelFromShipmentResponse(array $response): string {
    return $this->decodeLabelsPayload($response['labels'] ?? $response);
  }

  /**
   * Downloads label data for a shipment.
   *
   * @param int $shipmentId
   *   The Shipmondo shipment ID.
   * @param string $labelFormat
   *   The label format (e.g. a4_pdf).
   *
   * @return string
   *   The decoded label file contents (PDF/ZPL/PNG binary).
   *
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoApiException
   */
  public function getShipmentLabels(int $shipmentId, string $labelFormat): string {
    $query = ['label_format' => $labelFormat];

    try {
      $response = $this->request('GET', '/shipments/' . $shipmentId . '/labels', [
        'query' => $query,
      ]);
      return $this->decodeLabelsPayload($response);
    }
    catch (ShipmondoApiException $exception) {
      if ($exception->statusCode !== 404) {
        throw $exception;
      }
    }

    // Fallback endpoint for batched label retrieval.
    $response = $this->request('GET', '/labels', [
      'query' => [
        'ids' => (string) $shipmentId,
        'label_format' => $labelFormat,
      ],
    ]);
    return $this->decodeLabelsPayload($response);
  }

  /**
   * Decodes label file contents from a Shipmondo labels API response.
   *
   * @param mixed $payload
   *   Decoded JSON (list of labels or shipment wrapper).
   */
  public function decodeLabelsPayload(mixed $payload): string {
    if (!is_array($payload)) {
      return '';
    }

    if (isset($payload['labels']) && is_array($payload['labels'])) {
      $payload = $payload['labels'];
    }

    if (isset($payload['base64'])) {
      $payload = [$payload];
    }

    foreach ($payload as $label) {
      if (!is_array($label) || empty($label['base64'])) {
        continue;
      }
      $binary = base64_decode((string) $label['base64'], TRUE);
      if ($binary !== FALSE && $binary !== '') {
        return $binary;
      }
    }

    return '';
  }

  /**
   * Performs an authenticated JSON API request.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   API path starting with /.
   * @param array $options
   *   Guzzle request options.
   *
   * @return array
   *   Decoded JSON response.
   *
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoApiException
   */
  protected function request(string $method, string $path, array $options = []): array {
    $options['headers'] = ($options['headers'] ?? []) + $this->getAuthHeaders();
    if (!isset($options['headers']['Accept'])) {
      $options['headers']['Accept'] = 'application/json';
    }
    if (!isset($options['headers']['Content-Type']) && isset($options['json'])) {
      $options['headers']['Content-Type'] = 'application/json';
    }

    try {
      $response = $this->httpClient->request($method, $this->getBaseUri() . $path, $options);
    }
    catch (RequestException $exception) {
      $status = $exception->hasResponse() ? $exception->getResponse()->getStatusCode() : 0;
      $body = $exception->hasResponse() ? (string) $exception->getResponse()->getBody() : '';
      $this->logger->error('Shipmondo API request failed (@status) on @path: @body', [
        '@status' => $status,
        '@path' => $path,
        '@body' => $body,
      ]);
      throw new ShipmondoApiException($this->parseErrorMessage($body, $exception->getMessage()), $status, $body, $exception);
    }
    catch (\Exception $exception) {
      $this->logger->error('Shipmondo API request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new ShipmondoApiException(
        'Shipmondo API request failed: ' . $exception->getMessage(),
        0,
        '',
        $exception,
      );
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status < 200 || $status >= 300) {
      $this->logger->error('Shipmondo API error (@status) on @path: @body', [
        '@status' => $status,
        '@path' => $path,
        '@body' => $body,
      ]);
      throw new ShipmondoApiException($this->parseErrorMessage($body), $status, $body);
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new ShipmondoApiException(
        'Invalid JSON response from Shipmondo API.',
        $status,
        $body,
      );
    }

    return $decoded;
  }

  /**
   * Builds Basic Auth headers from configured keys.
   *
   * @return array
   *   Request headers.
   *
   * @throws \Drupal\commerce_shipmondo\Exception\ShipmondoApiException
   */
  /**
   * Returns the API base URI for production or sandbox.
   */
  protected function getBaseUri(): string {
    if ($this->configFactory->get('commerce_shipmondo.settings')->get('use_sandbox')) {
      return self::SANDBOX_BASE_URI;
    }
    return self::PRODUCTION_BASE_URI;
  }

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
   * Builds query parameters for the /products endpoint.
   */
  protected function buildProductsQuery(?string $sender_country_code = NULL, ?string $receiver_country_code = NULL, ?string $product_code = NULL): array {
    $query = [];
    if ($sender_country_code !== NULL && $sender_country_code !== '') {
      $query['sender_country_code'] = $sender_country_code;
    }
    if ($receiver_country_code !== NULL && $receiver_country_code !== '') {
      $query['receiver_country_code'] = $receiver_country_code;
    }
    if ($product_code !== NULL && $product_code !== '') {
      $query['product_code'] = $product_code;
    }
    return $query;
  }

  /**
   * Fetches all products from the Shipmondo API, following pagination.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function getAllProducts(array $query = []): array {
    $products = [];
    $page = 1;
    $per_page = self::PRODUCTS_PER_PAGE;

    do {
      $response = $this->request('GET', '/products', [
        'query' => $query + [
          'page' => $page,
          'per_page' => $per_page,
        ],
      ]);
      $batch = $this->extractProductList($response);
      $products = array_merge($products, $batch);
      $page++;
    } while (count($batch) === $per_page);

    return $products;
  }

  /**
   * Normalizes a /products response to a list of product arrays.
   */
  protected function extractProductList(array $response): array {
    if (isset($response[0]) && is_array($response[0])) {
      return $response;
    }
    foreach (['products', 'data', 'items'] as $key) {
      if (isset($response[$key]) && is_array($response[$key])) {
        return $response[$key];
      }
    }
    return [];
  }

  /**
   * Extracts a human-readable message from an API error body.
   */
  protected function parseErrorMessage(string $body, string $fallback = 'Shipmondo API returned an error.'): string {
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      return $fallback;
    }
    return (string) ($decoded['error'] ?? $decoded['message'] ?? $fallback);
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

}
