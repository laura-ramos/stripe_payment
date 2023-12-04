<?php

/**
 * @file
 * description 
 */

namespace Drupal\stripe_payment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\stripe_payment\StripeServiceApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Config\ConfigFactoryInterface;

class StripeCheckoutController extends ControllerBase
{
  
  /**
   * Drupal\stripe_payment\StripeServiceApi definition.
   *
   * @var \Drupal\stripe_payment\StripeServiceApi
   */
  protected $stripeServiceApi;

  /**
   * Used to get the current return URL, plus the query parameters.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * UserSubscriptionsController constructor.
   *
   * @param \Drupal\stripe_payment\StripeServiceApi $stripeServiceApi
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   */

  public function __construct(StripeServiceApi $stripeServiceApi, RequestStack $requestStack) {
    $this->stripeServiceApi = $stripeServiceApi;
    $this->currentRequest = $requestStack->getCurrentRequest();
  }

    /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('stripe_payment.api_service'),
      $container->get('request_stack')
    );
  }

  /**
   * Function to process payment.
   *
  */
  public function successCheckout() {
    // Get stripe client.
    $stripe = $this->stripeServiceApi->getStripeClient();
    // Retrieve the Checkout Session ID, newRole, plan from the URL parameter
    $sessionId = $this->currentRequest->query->get('session_id');
    $newRole = $this->currentRequest->query->get('roleid');
    $plan = $this->currentRequest->query->get('plan');
    $isAnonymous = \Drupal::currentUser()->isAnonymous();
    // Retrieve a Session
    $session = $stripe->checkout->sessions->retrieve($sessionId);
    $email = $session->customer_details->email;

    if ($session->payment_status === 'paid') {
      $session['description'] = $plan;

      // The payment is successful. You can handle any additional actions here.
      // validate purchase register in ppss_sales
      $query = \Drupal::database()->select('ppss_sales', 's')->condition('id_subscription', $session->subscription);
      $numRows = $query->countQuery()->execute()->fetchField();
      if ($numRows == 0) {
        // Retrieves the subscription with the given ID.
        $subscription = $stripe->subscriptions->retrieve(
          $session->subscription,
          []
        );
        // create or update user
        if ($isAnonymous) {
          // It's an anonymous user. First will search about if returned email by
          // Stripe exist, if not, trying to create an account with data returned.
          
          $ids = \Drupal::entityQuery('user')
            ->accessCheck(TRUE)
            ->condition('mail', $email)
            ->execute();
  
          // Find if email exist.
          if (!empty($ids)) {
            // This mail already exists. Only will assign the role of the subscription
            $uid = intval(current($ids));
            try {
              $user = \Drupal\user\Entity\User::load($uid);
              $user->addRole($newRole);
              $user->save();
            } catch (\Exception $e) {
              $errorInfo = t('Charge was made correctly but something was wrong when trying
                to assign the new subscription plan to your account. Please contact
                with the site administrator and explain this situation.');
              // Show error message to the user
              \Drupal::messenger()->addError($errorInfo);
              \Drupal::logger('stripe payment')->error($errorInfo);
              \Drupal::logger('stripe payment')->error($e->getMessage());
            }
            $description = $this->t("Please login with your user account linked to this email: @email for begin use our services.", ['@email' => $email]);
          } else {
            // Creates a new user with the Stripe email.
            try {
              // Get te user name to register from the email
              $temp = explode("@", $email);
              $userName = $temp[0];
              $user = User::create();
              $user->set('status', 1);
              $user->setEmail($email);
              $user->setUsername($userName);
              $user->addRole($newRole);
              $user->enforceIsNew();
              $user->log;
              $user->save();
  
            } catch (\Exception $e) {
              $errorInfo = t('Charge was made correctly but something was wrong when trying
                to create your account. Please contact with the site administrator
                and explain this situation.');
                // Show error message to the user
              \Drupal::messenger()->addError($errorInfo);
              \Drupal::logger('stripe payment')->error($errorInfo);
              \Drupal::logger('stripe payment')->error($e->getMessage());
            }
  
            // Send confirmation email.
            $result = array();
            $result = _user_mail_notify('register_no_approval_required', $user);
  
            if ((is_null($result)) || $result == false) {
              $message = t('There was a problem sending your email notification to @email.',
                array('@email' => $email));
              \Drupal::messenger()->addError($message);
              \Drupal::logger('stripe payment')->error($message);
            } else {
              $description = $this->t("Please review your email: @email to login details and begin use our services.", ['@email' => $email]);
            }
            // Get the uid of the new user.
            $ids = \Drupal::entityQuery('user')
              ->accessCheck(TRUE)
              ->condition('mail', $email)
              ->execute();
          }
  
          $uid = intval(current($ids));
  
        } else {
          // Only will assign the role of the subscription
          // plan purchased to the current user
          $uid = \Drupal::currentUser()->id();
          try {
            $user = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id($uid));
            $user->addRole($newRole);
            $user->save();
            $description = $this->t("Your user account linked to this email: @email was successfully upgraded, please enjoy.", ['@email' => $email]);
          } catch (\Exception $e) {
            // Show error message to the user
            $errorInfo = t('Charge was made correctly but something was wrong when trying
              to assign the new subscription plan to your account. Please contact
              with the site administrator and explain this situation.');
            \Drupal::messenger()->addError($errorInfo);
            \Drupal::logger('stripe_payment')->error($errorInfo);
            \Drupal::logger('stripe_payment')->error($e->getMessage());
          }
        }
        
        // Save all transaction data in DB for future reference.
        try {
          // Initiate missing variables to save.
          $currentTime = \Drupal::time()->getRequestTime();

          // Save the values to the database
          // Start to build a query builder object $query.
          // Ref.: https://www.drupal.org/docs/drupal-apis/database-api/insert-queries
          $newSale = \Drupal::database()->insert('ppss_sales');
          // Specify the fields taht the query will insert to.
          $newSale->fields([
            'uid',
            'status',
            'mail',
            'platform',
            'frequency',
            'frequency_interval',
            'details',
            'created',
            'id_subscription',
            'id_role'
          ]);

          // Set the values of the fields we selected.
          // Note that then must be in the same order as we defined them in the $query->fields([...]) above.
          $newSale->values([
            $uid,
            1,
            $session->customer_details->email,
            'stripe',
            $subscription->items->data[0]->price->recurring->interval,
            $subscription->items->data[0]->price->recurring->interval_count,
            str_replace('Stripe\Checkout\Session JSON:', '', $session),
            $currentTime,
            $session->subscription,
            $newRole
          ]);
          // Execute the query!
          $newSale->execute();

          //get the ppss_sales data inserted above
          $getSale = \Drupal::database()->select('ppss_sales', 's');
          $getSale->condition('id_subscription', $session->subscription);
          $getSale->fields('s', ['id']);
          $subscription = $getSale->execute()->fetchAssoc();

          // insert sales details
          $newSaleDetail = \Drupal::database()->insert('ppss_sales_details');
          $newSaleDetail->fields(['sid', 'tax', 'price', 'total', 'created', 'event_id']);
          $newSaleDetail->values([
            $subscription['id'],
            number_format($session->total_details->amount_tax / 100, 2),
            number_format(($session->amount_total - $session->total_details->amount_tax) / 100, 2),
            number_format($session->amount_total / 100, 2),
            $currentTime,
            0
          ]);
          $newSaleDetail->execute();
          
          \Drupal::messenger()->addMessage(t('Successful subscription purchase.'));
        } catch (\Exception $e) {
          // Show error message to the user
          $errorInfo = t('Unable to save payment to DB at this time due to database error.
            Please contact with the site administrator and explain this situation.');
          \Drupal::messenger()->addError($errorInfo);
          \Drupal::logger('Sales')->error($errorInfo);
          \Drupal::logger('stripe_payment')->error($e->getMessage());
        }
      }

      $description = "Thank you for your purchase!";
      \Drupal::messenger()->addMessage($description);
      return new RedirectResponse($this->config('ppss.settings')->get('success_url'));

    } else {
      // The payment is not yet completed or failed. Handle accordingly.
      \Drupal::messenger()->addMessage("Payment not completed. Please contact support.");
      return new RedirectResponse($this->config('ppss.settings')->get('cancel_url'));
    }
    /*return [
      '#markup' => $description,
    ];*/
  }

  /**
   * Purchase history by user.
   *
   */
  public function purchaseStripe() {
    $user_id = \Drupal::currentUser()->hasPermission('access user profiles') ? \Drupal::routeMatch()->getParameter('user') : $this->currentUser()->id();
    //create table header
    $header_table = array(
      'name' => $this->t('Plan'),
      'platform' => $this->t('Payment type'),
      'date' => $this->t('Start date'),
      'status' => $this->t('Status'),
      'details' => $this->t('Details')
    );
    //select records from table ppss_sales
    $query = \Drupal::database()->select('ppss_sales', 's');
    $query->condition('uid', $user_id);
    $query->fields('s', ['id','uid','mail','platform','details', 'created', 'status', 'id_subscription']);
    $results = $query->execute()->fetchAll();

    $rows = array();
    foreach ($results as $data) {
      $details = json_decode($data->details);
      $operations = Url::fromRoute('stripe_payment.manage_subscription', ['customer' => $details->customer], []);
      $cancel = Url::fromRoute('stripe_payment.cancel_subscription', ['user' => $user_id, 'id' => $data->id], []);
      
      //print the data from table
      $rows[] = array(
        'name' => $details->description,
        'platform' => $data->platform,
        'date' => date('d/m/Y', $data->created),
        'status' => $data->status ? 'Activo' : 'Inactivo',
        'details' => Link::fromTextAndUrl($this->t('Details'), $operations),
        //'cancel' => Link::fromTextAndUrl($this->t('Cancel'), $cancel),
      );
    }
    //display data in site
    $form['table'] = [
      '#type' => 'table',
      '#header' => $header_table,
      '#rows' => $rows,
      '#empty' => 'No hay compras',
    ];
    return $form;
  }

  /**
   *  Integrate the customer portal.
   * 
   *  @param $customer
   *  Customer id
   * 
   */
  public function manageSubscription($customer) {
    try {
      // Get stripe client
      $stripe = $this->stripeServiceApi->getStripeClient();
      // Creates a session of the customer portal.
      // https://stripe.com/docs/api/customer_portal/sessions/create
      $session = $stripe->billingPortal->sessions->create([
        'customer' => $customer,
        'return_url' => $this->currentRequest->getSchemeAndHttpHost() . $this->config('ppss.settings')->get('error_url'),
      ]);
      header("Location: " . $session->url);
      exit();

    } catch (\Exception $exception) {
      return [
        '#markup' => 'Something went wrong! ' . $exception->getMessage(),
      ];
    }
  }

}
