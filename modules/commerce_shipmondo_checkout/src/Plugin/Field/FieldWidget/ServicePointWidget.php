<?php

namespace Drupal\commerce_shipmondo_checkout\Plugin\Field\FieldWidget;

use Drupal\commerce_shipmondo_checkout\Service\ServicePointSelectorSettingsBuilder;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'shipmondo_service_point_selector' widget.
 *
 * @FieldWidget(
 *   id = "shipmondo_service_point_selector",
 *   label = @Translation("Shipmondo service point selector"),
 *   field_types = {
 *     "string_long"
 *   }
 * )
 */
class ServicePointWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    protected ServicePointSelectorSettingsBuilder $settingsBuilder,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
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
      $configuration['third_party_settings'],
      $container->get('commerce_shipmondo_checkout.service_point_selector_settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    return $field_definition->getTargetEntityTypeId() === 'commerce_shipment'
      && $field_definition->getName() === 'shipmondo_service_point';
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $entity = $items->getEntity();
    if (!$entity instanceof ShipmentInterface) {
      return $element;
    }

    $stored_value = (string) ($items[$delta]->value ?? '');
    $selector_settings = $this->settingsBuilder->buildForShipment($entity, $form_state);

    $element += [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['commerce-shipmondo-service-point-widget'],
        'id' => 'shipmondo-service-point-field-wrapper',
      ],
    ];

    $element['value'] = [
      '#type' => 'hidden',
      '#default_value' => $stored_value,
      '#attributes' => [
        'class' => ['commerce-shipmondo-service-point-input'],
      ],
    ];

    if ($selector_settings === NULL) {
      $element['message'] = [
        '#type' => 'item',
        '#title' => $this->fieldDefinition->getLabel(),
        '#markup' => $this->t('Select a mapped service point shipping method and ensure the shipping address has a postal code.'),
      ];
      return $element;
    }

    $element['selector'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['commerce-shipmondo-service-point-selector'],
        'data-shipmondo-sps' => json_encode($selector_settings, JSON_THROW_ON_ERROR),
      ],
    ];

    $element['#attached']['library'][] = 'commerce_shipmondo_checkout/service_point_selector';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $entity = $form_state->getFormObject()?->getEntity();
    if (!$entity instanceof ShipmentInterface) {
      return $values;
    }

    foreach ($values as $delta => $value) {
      if (!is_array($value)) {
        continue;
      }

      $raw_value = trim((string) ($value['value'] ?? ''));
      if ($raw_value === '') {
        $values[$delta]['value'] = NULL;
        continue;
      }

      $decoded = json_decode($raw_value, TRUE);
      if (is_array($decoded)) {
        $selector_settings = $this->settingsBuilder->buildForShipment($entity, $form_state);
        if (!empty($selector_settings['carrierCode'])) {
          $decoded['carrier_code'] = $selector_settings['carrierCode'];
        }
        $raw_value = json_encode($decoded, JSON_UNESCAPED_UNICODE);
      }

      $values[$delta]['value'] = $raw_value;
    }

    return $values;
  }

}
