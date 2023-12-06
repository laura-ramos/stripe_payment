<?php

/**
 * @file
 * A form to sale subscriptions using node details.
 */

namespace Drupal\stripe_payment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
* Provides an only one button form.
*/
class BtnPaySubscription extends FormBase
{
  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'stripe_button_pay_subscription';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // Atempt to get the fully loaded node object of the viewed page and settings.
    $node = \Drupal::routeMatch()->getParameter('node');
    $secretKey = \Drupal::service('stripe_payment.api_service')->getApiKey();

    // Only shows form if credentials are correctly configured and content is a node.
    if (!(empty($secretKey) || (is_null($node)))) {
      $query = \Drupal::database()->select('ppss_sales', 's');
      $query->condition('uid', \Drupal::currentUser()->id());
      $query->condition('status', 1);
      $query->fields('s');
      $subscription = $query->execute()->fetchAssoc();
      // if subscription exists display plan update link
      if($subscription) {
        $details = json_decode($subscription['details']);
        $form['link'] = [
          '#title' => t('Actualizar plan'),
          '#type' => 'link',
          '#url' => Url::fromRoute('stripe_payment.manage_subscription', ["customer" => $details->customer]),
        ];
      } else {
        $form['submit'] = [
          '#type' => 'submit',
          '#value' => t('Buy Subscription Now'),
        ];
      }
    } else {
      // Nothing to display.
      $message = "Stripe payment module don't has configured properly,
        please review your settings.";
      \Drupal::logger('system')->alert($message);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Atempt to get the fully loaded node object of the viewed page and settings.
    $node = \Drupal::routeMatch()->getParameter('node');
    $config = $this->config('stripe_payment.settings');
    $fieldPriceId = $config->get('field_price');
    $fieldRole = $config->get('field_role');
    $newRole = strlen($fieldRole) == 0 ? '' : $node->get($fieldRole)->getString();
    $taxRate = $config->get('tax_rate');
    //$plan = $node->getTitle();
    // The price ID passed from the front end.
    $priceId = $node->get($fieldPriceId)->getString();
    
    // define url
    $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
    // Stripe redirects to this page after the customer successfully completes the checkout.
    $success = Url::fromRoute('stripe_payment.payment_success', [], ['absolute' => true])->toString();
    // Stripe redirects to this page when the customer clicks the back button in Checkout.
    $cancel = $baseUrl . $this->config('ppss.settings')->get('error_url');

    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $email = $user->get('mail')->value;
    
    // Get stripe client
    $stripe = \Drupal::service('stripe_payment.api_service')->getStripeClient();

    /**
     * A Checkout Session controls what your customer sees in the Stripe-hosted payment page
     * Specify URLs for success and cancel pages—make sure they’re publicly accessible so Stripe can redirect customers to them.
     * Use subscription mode to set up a subscription. Checkout also has payment and setup modes.
     * Pass in the predefined price ID retrieved above.
     */

    $params = [
      'success_url' => $success.'?session_id={CHECKOUT_SESSION_ID}&roleid='.$newRole,
      'cancel_url' => $cancel,
      'mode' => 'subscription',
      'payment_method_types' => ['card'],
      'line_items' => [
        [
          'price' => $priceId,
          'quantity' => 1,
          'tax_rates' => [$taxRate],
        ]
      ],
    ];
    if($email) {
      // Search for customers you’ve previously created
      $customer = $stripe->customers->search([
        'query' => 'email:\''.$email .'\'',
        'limit' => 1
      ]);
      // validate if customer exist
      if($customer->data) {
        // set customer id to params
        $params['customer'] = $customer->data[0]['id'];
      } else {
        // set customer email to params
        $params['customer_email'] = $email;
      }
    }
    // Creates a Session object.
    $session = $stripe->checkout->sessions->create($params);

    // Redirect to the URL returned on the Checkout Session.
    header("Location: " . $session->url);
    exit();
  }

}