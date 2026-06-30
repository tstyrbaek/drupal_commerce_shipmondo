<?php

namespace Drupal\commerce_shipmondo\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\commerce_shipmondo\Service\TrackingUrlBuilder;
use Drupal\commerce_shipping\Plugin\Field\FieldFormatter\TrackingLinkFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extends Commerce tracking links with Shipmondo track & trace URLs.
 */
class ShipmondoTrackingLinkFormatter extends TrackingLinkFormatter implements ContainerFactoryPluginInterface {

  public function __construct(
    string $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    protected TrackingUrlBuilder $trackingUrlBuilder,
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('commerce_shipmondo.tracking_url_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = parent::viewElements($items, $langcode);
    if ($this->isTrackingLinkElement($elements)) {
      return $elements;
    }

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $items->getEntity();
    $tracking_code = trim((string) $shipment->getTrackingCode());
    if ($tracking_code === '') {
      return [];
    }

    $tracking_url = $this->trackingUrlBuilder->buildUrl($shipment);
    if (!$tracking_url) {
      return [['#markup' => Xss::filterAdmin($tracking_code)]];
    }

    return [
      [
        '#type' => 'link',
        '#title' => $tracking_code,
        '#url' => $tracking_url,
        '#attributes' => [
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ],
    ];
  }

  /**
   * Whether the formatter output is already a tracking link.
   */
  protected function isTrackingLinkElement(array $elements): bool {
    return isset($elements[0]['#type']) && $elements[0]['#type'] === 'link';
  }

}
