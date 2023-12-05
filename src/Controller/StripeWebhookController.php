<?php

/**
 * @file
 * Listen to events in your Stripe account so your integration can automatically trigger reactions.
 */

namespace Drupal\stripe_payment\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defines a controller for managing webhook notifications.
 */
class StripeWebhookController extends ControllerBase
{

  /**
   * The HTTP request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The queue factory.
   *
   * @var Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructs a PPSSWebhookController object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *  The HTTP request object.
   * @param Drupal\Core\Queue\QueueFactory $queue
   *  The queue factory.
   */
  public function __construct(Request $request, QueueFactory $queue)
  {
    $this->request = $request;
    $this->queueFactory = $queue;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
        $container->get('request_stack')->getCurrentRequest(),
        $container->get('queue'),
      );
  }

  /**
   * Listens for webhook notifications and queues them for processing.
   *
   * @return Symfony\Component\HttpFoundation\Response
   *   Webhook providers typically expect an HTTP 200 (OK) response.
   */
  public function listener()
  {
    // Prepare the response.
    $response = new Response();
    $response->setContent('Notification received');

    // Capture the contents of the notification (payload).
    $payload = $this->request->getContent();
    
    // Get the queue implementation.
    $queue = $this->queueFactory->get('stripe_webhook_processor');

    // Add the $payload to the queue.
    $queue->createItem($payload);

    // Respond with the success message.
    return $response;
  }

  /**
   * Checks access for incoming webhook notifications.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access()
  {
    $config = \Drupal::config('stripe_payment.settings');
    $secretKey =  \Drupal::service('stripe_payment.api_service')->getApiKey();
    
    \Stripe\Stripe::setApiKey($secretKey);
    // Replace this endpoint secret with your endpoint's unique secret
    // If you are using an endpoint defined with the API or dashboard, look in your webhook settings
    // at https://dashboard.stripe.com/webhooks
    $endpointSecret = $config->get('secret_webhook');

    $payload = $this->request->getContent();
    $verifyAccess = 0;

    // Only verify the event if there is an endpoint secret defined
    if ($endpointSecret) {
      $sigHeader = $this->request->headers->get('stripe-signature');
      try {
        $event = \Stripe\Webhook::constructEvent(
          $payload,
          $sigHeader,
          $endpointSecret
        );
        $verifyAccess = 1;
      } catch (\UnexpectedValueException $e) {
        // invalid payload
        \Drupal::logger('stripe webhook')->error('Error parsing payload: '. $e->getMessage());
      } catch (\Stripe\Exception\SignatureVerificationException $e) {
        // Invalid signature
        \Drupal::logger('stripe webhook')->error('Error verifying webhook signature: ' . $e->getMessage());
      }
    }
    // If they validation was successful, allow access to the route.
    return AccessResult::allowedIf($verifyAccess); 
  }

}
