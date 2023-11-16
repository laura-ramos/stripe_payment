<?php

/**
 * @file
 * Content the settings for administering the StripeFormSettings form.
 */

namespace Drupal\stripe_payment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class StripeFormSettings extends ConfigFormBase
{
  /**
   * Constructs an AutoParagraphForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entityTypeManager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager)
  {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

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
    // Get the internal node type machine name.
    $existingContentTypeOptions = $this->getExistingContentTypes();
    $config = $this->config('stripe_payment.settings');

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('The content types to enable payment button'),
      '#default_value' => $config->get('content_types'),
      '#options' => $existingContentTypeOptions,
      '#empty_option' => $this->t('- Select an existing content type -'),
      '#description' => $this->t('On the specified node types, an button
        will be available and can be shown to make purchases.'),
      '#required' => true,
    ];

    $form['mode'] = [
      '#title' => $this->t('Enable SandBox Mode'),
      '#type' => 'checkbox',
      '#default_value' => $config->get('mode'),
      '#required' => true,
    ];

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key'),
      '#default_value' => $config->get('public_key'),
      '#description' => $this->t('Public key to authenticate client-side.'),
      '#required' => false,
    ];

    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret key'),
      '#default_value' => $config->get('secret_key'),
      '#description' => $this->t('Secret key to authenticate API requests.'),
      '#required' => true,
    ];

    $form['return_url'] = [
      '#type' => 'details',
      '#title' => $this->t('URL of return pages'),
      '#open' => true,
    ];

    $form['return_url']['success_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Success URL'),
      '#default_value' => $config->get('success_url'),
      '#description' => $this->t('What is the return URL when a new successful sale was made? Specify a relative URL.'),
      '#size' => 40,
      '#required' => true,
    ];

    $form['return_url']['cancel_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cancel URL'),
      '#default_value' => $config->get('cancel_url'),
      '#description' => $this->t('Stripe redirects to this page when the customer clicks the back button in Checkout.'),
      '#size' => 40,
      '#required' => true,
    ];

    // Start fields configuration.
    $form['fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Fields names settings'),
      '#open' => true,
    ];

    $form['fields']['description'] = [
      '#markup' => $this->t('You always need to add one field of this type in your
        custom node type.'),
    ];

    $form['fields']['field_price'] = [
      '#title' => $this->t('Price field name'),
      '#type' => 'textfield',
      '#default_value' => $config->get('field_price'),
      '#description' => $this->t("What is the internal Drupal system name of the
        field to store prices. Example: 'field_nvi_price'."),
      '#required' => true,
    ];

    $form['fields']['field_role'] = [
      '#title' => $this->t('User role field name'),
      '#type' => 'textfield',
      '#default_value' => $config->get('field_role'),
      '#description' => $this->t("What is the internal Drupal system name of the
        field to store new user role assigned after purchased a plan. Example: 'field_nvi_role'."),
      '#required' => true,
    ];

    // Start payment details.
    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Payment configuration details'),
      '#open' => false,
    ];

    $form['details']['tax_rate'] = [
      '#title' => $this->t('Tax'),
      '#type' => 'textfield',
      '#default_value' => $config->get('tax_rate'),
      '#description' => $this->t('ID of tax rate to charge in all transactions.'),
      '#required' => true,
    ];

    // Webhooks details.
    $form['webhooks'] = [
      '#type' => 'details',
      '#title' => $this->t('Webhook configuration'),
      '#open' => false,
    ];

    $form['webhooks']['url_webhook'] = [
      '#type' => 'textfield',
      '#disabled' => true,
      '#title' => $this->t('URL'),
      '#description' => $this->t('Endpoint URL.'),
      '#default_value' => Url::fromRoute('stripe_payment.webhook_listener')->setAbsolute()->toString(),
    ];

    $form['webhooks']['secret_webhook'] = [
      '#title' => $this->t('Secret Key'),
      '#type' => 'textfield',
      '#default_value' => $config->get('secret_webhook'),
      '#description' => $this->t('Webhooks endpoint secret key.'),
      '#required' => true,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $configKeys = [
      'content_types', 'mode', 'public_key', 'secret_key', 'success_url', 'cancel_url', 'field_price', 'field_role', 'tax_rate', 'secret_webhook'
    ];
    $config = $this->config('stripe_payment.settings');
    foreach ($configKeys as $config_key) {
      if ($form_state->hasValue($config_key)) {
        $config->set($config_key, $form_state->getValue($config_key));
      }
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Returns a list of all the content types currently installed.
   *
   * @return array
   *   An array of content types.
   */
  public function getExistingContentTypes()
  {
    $types = [];
    $contentTypes = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($contentTypes as $contentType) {
      $types[$contentType->id()] = $contentType->label();
    }
    return $types;
  }
}
