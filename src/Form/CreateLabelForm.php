<?php

namespace Drupal\commerce_shipmondo\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipmondo\Exception\ShipmondoApiException;
use Drupal\commerce_shipmondo\Exception\ShipmondoValidationException;
use Drupal\commerce_shipmondo\Service\OrderShipmentMapper;
use Drupal\commerce_shipmondo\Service\ShipmondoLabelManager;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Confirmation form for creating a Shipmondo label.
 */
class CreateLabelForm extends ConfirmFormBase {

  protected ?OrderInterface $order = NULL;

  protected ?ShipmentInterface $shipment = NULL;

  public function __construct(
    protected ShipmondoLabelManager $labelManager,
    protected OrderShipmentMapper $mapper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_shipmondo.label_manager'),
      $container->get('commerce_shipmondo.order_shipment_mapper'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_shipmondo_create_label_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->t('Create a Shipmondo label for this shipment?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->order->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->t('Create label');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $commerce_order = NULL, ?ShipmentInterface $commerce_shipment = NULL): array {
    $this->order = $commerce_order;
    $this->shipment = $commerce_shipment;

    if (!$this->order || !$this->shipment) {
      throw new ShipmondoValidationException('Order or shipment is missing.');
    }

    if ((int) $this->shipment->getOrderId() !== (int) $this->order->id()) {
      throw new ShipmondoValidationException('Shipment does not belong to this order.');
    }

    try {
      $this->mapper->validateRequirements($this->shipment);
    }
    catch (ShipmondoValidationException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setResponse(new RedirectResponse($this->order->toUrl('canonical')->toString()));
      return [];
    }

    $weight_error = NULL;
    try {
      $this->mapper->validateWeight($this->shipment);
    }
    catch (ShipmondoValidationException $exception) {
      $weight_error = $exception->getMessage();
    }

    if ($weight_error === NULL) {
      $summary = $this->mapper->buildSummary($this->shipment);
      $summary_items = [
        $this->t('Shipping method: @method', ['@method' => $summary['shipping_method']]),
        $this->t('Recipient: @name', ['@name' => $summary['recipient']]),
      ];
      if (!empty($summary['service_point'])) {
        $summary_items[] = $this->t('Pickup point: @point', ['@point' => $summary['service_point']]);
      }
      $summary_items[] = $this->t('Weight: @weight g', ['@weight' => $summary['weight_grams']]);
      $summary_items[] = $this->t('Product code: @code', ['@code' => $summary['product_code']]);
      $summary_items[] = $this->t('Service codes: @codes', ['@codes' => $summary['service_codes'] ?: $this->t('(none)')]);

      $form['summary'] = [
        '#type' => 'item_list',
        '#title' => $this->t('Shipment details'),
        '#items' => $summary_items,
      ];
    }

    $form = parent::buildForm($form, $form_state);

    if ($weight_error !== NULL) {
      $form['weight_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        '#weight' => -10,
        'message' => [
          '#markup' => $weight_error,
        ],
      ];
      $form['actions']['submit']['#disabled'] = TRUE;
      $form['actions']['submit']['#attributes']['class'][] = 'is-disabled';
      $form['actions']['submit']['#attributes']['title'] = $weight_error;
    }

    $form['#attributes']['class'][] = 'commerce-shipmondo-create-label-form';
    $form['#attached']['library'][] = 'commerce_shipmondo/create_label';

    $form['loading_status'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['commerce-shipmondo-create-label-status'],
        'hidden' => 'hidden',
        'role' => 'status',
        'aria-live' => 'polite',
      ],
      '#weight' => 100,
      'progress' => [
        '#type' => 'inline_template',
        '#template' => '<div class="commerce-shipmondo-loader"><div class="commerce-shipmondo-loader__spinner" aria-hidden="true"></div><div class="commerce-shipmondo-loader__content"><p class="commerce-shipmondo-loader__title">{{ message }}<span class="commerce-shipmondo-loader__dots" aria-hidden="true"><span>.</span><span>.</span><span>.</span></span></p><p class="commerce-shipmondo-loader__hint">{{ hint }}</p></div></div>',
        '#context' => [
          'message' => $this->t('Creating Shipmondo label'),
          'hint' => $this->t('Connecting to Shipmondo and generating your label. This may take a few seconds.'),
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $metadata = $this->labelManager->createLabel($this->shipment);
      $label_url = Url::fromRoute('commerce_shipmondo.download_label', [
        'commerce_order' => $this->order->id(),
        'commerce_shipment' => $this->shipment->id(),
      ], [
        'attributes' => [
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ]);
      $message_parts = [
        $this->t('Shipmondo label created.'),
        Link::fromTextAndUrl($this->t('Open label'), $label_url)->toString(),
      ];
      if (!empty($metadata['tracking_number'])) {
        if (!empty($metadata['tracking_url'])) {
          $tracking_url = Url::fromUri($metadata['tracking_url'], [
            'attributes' => [
              'target' => '_blank',
              'rel' => 'noopener noreferrer',
            ],
          ]);
          $message_parts[] = $this->t('Tracking: @link', [
            '@link' => Link::fromTextAndUrl($metadata['tracking_number'], $tracking_url)->toString(),
          ]);
        }
        else {
          $message_parts[] = $this->t('Tracking: @tracking', [
            '@tracking' => $metadata['tracking_number'],
          ]);
        }
      }

      $this->messenger()->addStatus(Markup::create(implode(' ', $message_parts)));
      $form_state->setRedirectUrl($this->order->toUrl('canonical'));
    }
    catch (ShipmondoValidationException | ShipmondoApiException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setRedirectUrl($this->getCancelUrl());
    }
  }

}
