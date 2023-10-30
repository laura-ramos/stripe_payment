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
    return 'button_pay_subscription';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // Atempt to get the fully loaded node object of the viewed page and settings.
    $node = \Drupal::routeMatch()->getParameter('node');
    $config = \Drupal::config('stripe_payment.settings');
    $secretKey = $config->get('secret_key');

    // Only shows form if credentials are correctly configured and content is a node.
    if (!(empty($secretKey) || (is_null($node)))) {
      $form['submit'] = [
        '#type' => 'submit',
        '#value' => t('Buy Subscription Now'),
      ];
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
    $secretKey = $config->get('secret_key');
    $fieldPriceId = $config->get('field_price');

    // Set your secret key. Remember to switch to your live secret key in production.
    // See your keys here: https://dashboard.stripe.com/apikeys
    $stripe = new \Stripe\StripeClient($secretKey);

    // The price ID passed from the front end.
    $priceId = $node->get($fieldPriceId)->getString();
    
    // define url
    $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
    // Stripe redirects to this page after the customer successfully completes the checkout.
    $success = Url::fromRoute('stripe_payment.payment_success', [], ['absolute' => true])->toString();
    // Stripe redirects to this page when the customer clicks the back button in Checkout.
    $cancel = $baseUrl . $config->get('cancel_url');

    /**
     * A Checkout Session controls what your customer sees in the Stripe-hosted payment page
     * Specify URLs for success and cancel pages—make sure they’re publicly accessible so Stripe can redirect customers to them.
     * Use subscription mode to set up a subscription. Checkout also has payment and setup modes.
     * Pass in the predefined price ID retrieved above.
     */
     
    $session = $stripe->checkout->sessions->create([
      'success_url' => $success.'?session_id={CHECKOUT_SESSION_ID}',
      'cancel_url' => $cancel,
      'mode' => 'subscription',
      'payment_method_types' => ['card'],
      'line_items' => [[
        'price' => $priceId,
        // For metered billing, do not pass quantity
        'quantity' => 1,
      ]],
      //"customer" => 'cus_Ou5KA0FNCgf9dJ',
    ]);

    // Redirect to the URL returned on the Checkout Session.
    header("Location: " . $session->url);
    exit();
  }

}