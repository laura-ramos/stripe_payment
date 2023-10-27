<?php

/**
 * @file
 * Content the settings for administering the StripeFormSettings form.
 */

namespace Drupal\stripe_payment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


class StripeFormSettings extends ConfigFormBase
{
  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    // Unique ID of the form.
    return 'stripe_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames()
  {
    return [
      'stripe_payment.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('stripe_payment.settings');

    $form['mode'] = [
      '#title' => $this->t('Enable SandBox Mode'),
      '#type' => 'checkbox',
      '#default_value' => $config->get('mode'),
      '#required' => TRUE,
    ];

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key'),
      '#default_value' => $config->get('public_key'),
      '#required' => TRUE,
    ];

    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret key'),
      '#default_value' => $config->get('secret_key'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $config_keys = [
      'mode', 'public_key', 'secret_key',
    ];
    $crm_config = $this->config('stripe_payment.settings');
    foreach ($config_keys as $config_key) {
      if ($form_state->hasValue($config_key)) {
        $crm_config->set($config_key, $form_state->getValue($config_key));
      }
    }
    $crm_config->save();
    parent::submitForm($form, $form_state);
  }
}
