<?php

namespace Drupal\stripe_payment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides form to cancel subscription.
 */
class StripeFormCancelSubscription extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'stripe_cancel_subscription_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user = NULL, $id = NULL) {
    $user_id = \Drupal::currentUser()->hasPermission('access user profiles') ? $user : $this->currentUser()->id();
    // Get subscription by id
    $query = \Drupal::database()->select('ppss_sales', 's');
    $query->condition('id', $id);
    $query->condition('uid', $user_id);
    $query->condition('status', 1);
    $query->isNull('expire');
    $query->fields('s');
    $result = $query->execute()->fetchAssoc();
    if($result) {
      $form['reason'] = [
        '#type' => 'select',
        '#title' => 'Razón de la cancelación',
        '#required' => TRUE,
        '#options' => [
          'too_expensive' => $this->t('It’s too expensive'),
          'missing_features' => $this->t('Some features are missing'),
          'switched_service' => $this->t('I’m switching to a different service'),
          'unused' => $this->t('I don’t use the service enough'),
          'customer_service' => $this->t('Customer service was less than expected'),
          'too_complex' => $this->t('Ease of use was less than expected'),
          'low_quality' => $this->t('Quality was less than expected'),
          'other' => $this->t('Other reason'),
        ],
      ];
      $form['id'] = [
        '#type' => 'hidden',
        '#required' => TRUE,
        '#default_value' => $id,
        '#description' => 'ID sale'
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ];
    } else {
      $this->messenger()->addWarning('La suscripción ya esta cancelada');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $reason = $form_state->getValue('reason');
    $id = $form_state->getValue('id');
    //call the service for cancellation
    $cancel = \Drupal::service('stripe_payment.api_service')->cancelSubscription($id, $reason);
    $this->messenger()->addWarning($cancel);
  }

}