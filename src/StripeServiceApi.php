<?php

namespace Drupal\stripe_payment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Stripe\StripeClient;

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

  /**
   * Drupal\Core\Config\ConfigFactory definition.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory)
  {
    $this->httpClient = $http_client;
    $this->config = $config_factory->get('stripe_payment.settings');
  }

  /**
   *  Cancel subscription using the Stripe API server.
   * 
   * @param integer $id
   *    id ppss_sale
   * @param string $reason
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
        $stripe = $this->getStripeClient();
        $stripe->subscriptions->cancel(
          $result['id_subscription'],
          [
            "cancellation_details" => [
              'feedback' => $reason
            ]
          ]
        );
        return 'Cancellation subscription will be applied during the day.';

      } catch (\Stripe\Exception\InvalidRequestException $e) {
        return 'An error has occurred: ' . $e->getError()->message;
      }
    } else {
      return 'Subscription is not active.';
    }
  }

  /**
   *  Process a queue of webhook notification event customer.subscription.deleted
   * 
   *  @param array $id
   *    Unique identifier for the object.
   * 
   */
  public function receiveWebhookCancel($entity_data)
  {
    // Get all payments
    $query = \Drupal::database()->select('ppss_sales', 's');
    $query->join('ppss_sales_details', 'sd', 's.id = sd.sid');
    $query->condition('id_subscription', $entity_data->data->object->subscription);
    $query->fields('s', ['id','uid','frequency', 'frequency_interval', 'status', 'id_role', 'mail']);
    $query->fields('sd',['id', 'created']);
    $query->orderBy('created', 'DESC');
    $results = $query->execute()->fetchAll();
    if (!empty($results)) {
      $subscription = $results[0]; // get the last payment
      $user = \Drupal\user\Entity\User::load($subscription->uid); //get subscription user
      // Validate supscription end date
      // o last payment date add +1 frecuency(month/year)
      //$expire = strtotime(date('d-m-Y', $subscription->created). '+'.$subscription->frequency_interval . $subscription->frequency);
      $expire = $entity_data->data->object->current_period_end;
      $today = date('d-m-Y');
    
      // Validate expiration date with current expiration date
      if (date('d-m-Y', $expire) == $today) {
        try {
          // Update ppss_sales table
          \Drupal::database()->update('ppss_sales')->fields([
            'status' => 0,
            'expire' => $expire, ])->condition('id_subscription', $id, '=')->execute();
          // Remove user role added by subscription purchased
          $user->removeRole($subscription->id_role);
          $user->save();

          $title = '¡Cancelación de suscripción en Encuéntralo!';
          $msg_user = 'Tu suscripción ha sido cancelada, mientras tanto recuerda que puedes continuar publicando anuncios con la versión gratuita';
          \Drupal::logger('stripe_payment')->info('Se ha cancelado la suscripción de plan '.$subscription->id_role.
          ' del usuario '.$subscription->uid);
        } catch (\Exception $e) {
          \Drupal::logger('stripe payment')->error($e->getMessage());
        }
      } else {
        // Update ppss_sales table
        \Drupal::database()->update('ppss_sales')->fields([
          'expire' => $expire,])->condition('id_subscription', $id, '=')->execute();

        $title = '¡Cancelación de pagos de suscripción en Encuéntralo!';
        $msg_user = 'Tu suscripción ha sido programada para ser cancelada el día '.date('d/m/Y', $expire).
        ' mientras tanto recuerda que puedes continuar publicando tus anuncios';
        \Drupal::logger('stripe payment')->info('Se ha programado con fecha '.date('d/m/Y', $expire).' la cancelación de la suscripción de plan '
        .$subscription->id_role.' del usuario '.$subscription->uid);
      }
      // Unpublish all ads setting a new date in the future
      $this->unpublishNodes($subscription->uid, $subscription->id_role, $expire);
      // send email
      $this->sendEmail($user->getEmail(), $title, $msg_user);
    }
  }

  public function sendEmail($email, $title, $msg_user) {
    $msg = '
    <div style="text-align: center;  margin: 20px;">
      <h1> ¡Hasta pronto! </h1>
      <h1> '.$title.' &#128522;</h1>
      <br>
      <div style="text-align: center; font-size: 24px;">'.$msg_user.'</div><br><br>
      <div style="text-align: center; border-top: 1px solid #bdc1c6; padding-top: 20px; font-style: italic; font-size: medium; color: #83878c;">
        <br>--  El equipo de Encuéntralo
      </div>
    </div>';

    // Send alert by email to stakeholders
    $module = 'stripe_paymet';
    $key = 'cancel_subscription';
    $to = $email.";".\Drupal::config('system.site')->get('mail');
    $params['subject'] = $title;
    $params['message'] = $msg;
    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = true;
    $result = \Drupal::service('plugin.manager.mail')->mail($module, $key, $to, $langcode, $params, NULL, $send);

    if (!$result['result']) {
      \Drupal::logger('stripe payment')->error('There was a problem sending your message and it was not sent.');
    } else {
      \Drupal::logger('stripe payment')->info('Your email has been sent.');
    }
  }

  public function unpublishNodes($uid, $id_role, $expire) {
    // Get content type based on role
    if ($id_role == 'enterprise') {
      $typeContent = "nvi_anuncios_e";
    } elseif($id_role == 'comercial') {
      $typeContent = "nvi_anuncios_c";
    } elseif($id_role == 'basic') {
      $typeContent = "nvi_anuncios_b";
    }

    //get all user ads by content type
    $nids = \Drupal::entityQuery("node")->condition('uid', $uid)
    ->condition('type', $typeContent)->condition('status', 1)->execute();
    $entity = \Drupal::entityTypeManager()->getStorage("node");
    $nodes = $entity->loadMultiple($nids);

    foreach ($nodes as $node) {
      $node->unpublish_on = $expire;
      //$node->setUnpublished();
      $node->save();
    }

  }

  /**
   * Save payment recurrent
   * 
   * @param array $entity_data
   *   Data receive from webhook notification.
   */
  public function paymentCompleted($entity_data) {
    //get data subscription
    $query = \Drupal::database()->select('ppss_sales', 's');
    $query->condition('id_subscription', $entity_data->data->object->subscription);
    $query->fields('s', ['id','uid','frequency', 'status', 'details']);
    $results = $query->execute()->fetchAll();
    $subscription = $results[0];
    
    try {
      // Get the number of payments
      $payment = \Drupal::database()->select('ppss_sales_details', 's')
                  ->condition('sid', $subscription->id)
                  ->condition('event_id', 0)
                  ->fields('s')
                  ->execute()->fetchAll();
      if(count($payment) == 1){
        // if it is the first payment
        // update event_id from webhook
        \Drupal::database()->update('ppss_sales_details')->fields([
          'event_id' => $entity_data->id,
        ])->condition('id', $payment[0]->id, '=')->execute();
      } else {
        // Insert a new recurring payment
        $query = \Drupal::database()->insert('ppss_sales_details');
        $query->fields(['sid', 'tax', 'price', 'total', 'created', 'event_id']);
        $query->values([
          $subscription->id,
          number_format($entity_data->data->object->tax / 100, 2),
          number_format($entity_data->data->object->subtotal_excluding_tax / 100, 2),
          number_format($entity_data->data->object->total / 100, 2),
          $entity_data->created,
          $entity_data->id
        ]);
        $query->execute();
        \Drupal::logger('stripe payment')->info('Recurring payment has been made: '. $entity_data->data->object->subscription);
      }
    } catch (\Exception $e) {
      \Drupal::logger('stripe payment')->error($e->getMessage());
    }
  }

  /**
   * Get a Stripe Client.
   *
   * @return \Stripe\StripeClient
   *   The StripeClient.
   */
  public function getStripeClient() {
    return new StripeClient($this->getApiKey());
  }

  /**
   * Get secret key.
   *
   * @return string
   *   Secret key.
   */
  public function getApiKey() {
    // See your keys here: https://dashboard.stripe.com/apikeys
    if ($this->config->get('sandbox_mode')) {
      return $this->config->get('secret_key_test');
    } else {
      return $this->config->get('secret_key_live');
    }
  }
}