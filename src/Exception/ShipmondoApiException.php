<?php

namespace Drupal\commerce_shipmondo\Exception;

/**
 * Exception thrown when the Shipmondo API returns an error.
 */
class ShipmondoApiException extends \RuntimeException {

  /**
   * Constructs a ShipmondoApiException.
   *
   * @param string $message
   *   The error message.
   * @param int $statusCode
   *   The HTTP status code.
   * @param string $responseBody
   *   The raw response body.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(
    string $message,
    public readonly int $statusCode = 0,
    public readonly string $responseBody = '',
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $statusCode, $previous);
  }

}
