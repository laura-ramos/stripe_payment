<?php

/**
 * @file
 * Process a queue of webhook notification payload data in listener() contained in
 * StripeWebhookController.php
 */

namespace Drupal\stripe_payment\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\stripe_payment\StripeServiceApi;

/**
 * Process a queue of webhook notification payload data.
 *
 * @QueueWorker(
 *   id = "stripe_webhook_processor",
 *   title = @Translation("Stripe Webhook notification processor"),
 *   cron = {"time" = 30}
 * )
 */
class StripeWebhookQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  
  /**
   * StripeServiceApi service.
   *
   * @var \Drupal\stripe_payment\StripeServiceApi
   */
  protected $apiService;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StripeServiceApi $apiService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->apiService = $apiService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
    $configuration,
    $plugin_id,
    $plugin_definition,
    $container->get('stripe_payment.api_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($payload)
  {
    // Only process the payload if it contains data.
    if (!empty($payload)) {

      // Decode the JSON payload to a PHP object.
      $entity_data = json_decode($payload);

      switch($entity_data->type) {
        case 'customer.subscription.deleted':
          //A billing subscription was cancelled
          $this->apiService->receiveWebhookCancel($entity_data->data->object->id);
          break;
        case 'invoice.payment_succeeded':
          //A payment completed
          $this->apiService->paymentCompleted($entity_data);
          break;
        default:
          Drupal::logger('stripe payment')->info('Received unknown event type ' . $entity_data->type);
      }
    } else {
      \Drupal::logger('stripe payment')->error('Nothing data to process');
    }
  }
}
