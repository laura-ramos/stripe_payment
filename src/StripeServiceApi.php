<?php

namespace Drupal\stripe_payment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactory;

/**
 *
 * Service Api.
 *
 */
class StripeServiceApi
{

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  public function __construct(ClientInterface $http_client)
  {
    $this->httpClient = $http_client;
  }

  /**
   *  cancel subscription from encuentralo use the Stripe API server.
   * 
   * @param integer
   *    id ppss_sale
   * @param string
   *    cancellation reason
   * 
   * @return string
   *    cancellation status message
   */
  public function cancelSubscription($id, $reason)
  {
    // Get active subscription
    $query = \Drupal::database()->select('ppss_sales', 's');
    $query->condition('id', $id);
    $query->condition('status', 1);
    $query->isNull('expire');
    $query->fields('s');
    $result = $query->execute()->fetchAssoc();
    if($result) {
      $data = [];
      //reason for cancellation
      $data['reason'] = $reason;
      try {
        // Set your Stripe API secret key
        $config = \Drupal::config('stripe_payment.settings');
        // Get stripe secret.
        $secretKey = $config->get('secret_key');
        $stripe = new \Stripe\StripeClient($secretKey);
        $res = $stripe->subscriptions->cancel(
          $result['id_subscription'],
          []
        );
        return 'Cancellation subscription will be applied during the day.';

      } catch (RequestException $e) {
        return 'An error has occurred';
      }
    } else {
      return 'Subscription is not active';
    }
  }
}