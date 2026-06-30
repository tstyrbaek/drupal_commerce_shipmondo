<?php

namespace Drupal\commerce_shipmondo_checkout\Plugin\Field\FieldFormatter;

use Drupal\commerce_shipmondo_checkout\Service\ServicePointOrderSync;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'shipmondo_service_point' formatter.
 *
 * @FieldFormatter(
 *   id = "shipmondo_service_point",
 *   label = @Translation("Pakkeshop"),
 *   field_types = {
 *     "string_long"
 *   }
 * )
 */
class ServicePointFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    protected ServicePointOrderSync $servicePointOrderSync,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
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
      $container->get('commerce_shipmondo_checkout.service_point_sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $data = NULL;
    if (!$items->isEmpty()) {
      $value = trim((string) $items->first()->value);
      if ($value !== '') {
        $data = json_decode($value, TRUE);
      }
    }

    $entity = $items->getEntity();
    if ((!is_array($data) || empty($data['id'])) && $entity instanceof ShipmentInterface) {
      $data = $this->servicePointOrderSync->resolveServicePointForDisplay($entity);
    }

    if (!is_array($data) || empty($data['id'])) {
      return [];
    }

    return [
      [
        '#theme' => 'shipmondo_service_point',
        '#carrier_code' => strtoupper((string) ($data['carrier_code'] ?? '')),
        '#name' => (string) ($data['name'] ?? ''),
        '#address' => (string) ($data['address'] ?? ''),
        '#zipcode' => (string) ($data['zipcode'] ?? ''),
        '#city' => (string) ($data['city'] ?? ''),
        '#attached' => [
          'library' => ['commerce_shipmondo_checkout/service_point_formatter'],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    return in_array($field_definition->getTargetEntityTypeId(), ['commerce_order', 'commerce_shipment'], TRUE)
      && $field_definition->getName() === 'shipmondo_service_point';
  }

}
