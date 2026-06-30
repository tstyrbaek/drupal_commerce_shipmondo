<?php

namespace Drupal\commerce_shipmondo_checkout\EventSubscriber;

use Drupal\commerce_shipmondo_checkout\Service\ServicePointOrderSync;
use Drupal\commerce_shipping\Event\ShipmentEvent;
use Drupal\commerce_shipping\Event\ShippingEvents;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Copies checkout service point selections onto shipments in the backend.
 */
class ServicePointShipmentSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected ServicePointOrderSync $servicePointOrderSync,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderWorkflowTransition'],
      'commerce_order.validate.post_transition' => ['onOrderWorkflowTransition'],
      ShippingEvents::SHIPMENT_INSERT => ['onShipmentInsert'],
    ];
  }

  /**
   * Syncs service points when an order is placed or validated.
   */
  public function onOrderWorkflowTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    $this->servicePointOrderSync->syncOrderToShipments($order);
  }

  /**
   * Syncs service points when a shipment is created in the backend.
   */
  public function onShipmentInsert(ShipmentEvent $event): void {
    $this->servicePointOrderSync->syncOrderToShipment($event->getShipment());
  }

}
