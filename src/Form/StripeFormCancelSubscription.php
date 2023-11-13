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
          1 => 'La navegación en el sitio web es difícil',
          2 => 'El precio del plan es elevado',
          4 => 'Me cambié a otra plataforma',
          5 => 'Otro',
        ],
        '#ajax' => [
          'callback' => '::otherField',
          'wrapper' => 'container',
        ],
      ];
      $form['id'] = [
        '#type' => 'hidden',
        '#required' => TRUE,
        '#default_value' => $id,
        '#description' => 'ID sale'
      ];
      $form['container'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'container'
        ],
      ];
      if ($form_state->getValue('reason', NULL) === "5") {
        $form['container']['other'] = [
          '#type' => 'textfield',
          '#title' => 'Especificar razón',
          '#required' => TRUE,
        ];
      }
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ];
    } else {
      $this->messenger()->addWarning('La suscripción ya esta cancelada');
    }
    return $form;
  }

  public function otherField($form, FormStateInterface $form_state) {
    return $form['container'];
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $reason = $form['reason']['#options'][$form_state->getValue('reason')];
    $id = $form_state->getValue('id');
    if($form_state->getValue('reason') == '5') {
      $reason = $form_state->getValue('other');
    }
    //call the service for cancellation
    $cancel = \Drupal::service('stripe_payment.api_service')->cancelSubscription($id, $reason);
    $this->messenger()->addWarning($cancel);
  }

}