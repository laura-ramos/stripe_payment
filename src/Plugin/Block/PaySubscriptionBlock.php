<?php

/**
 * @file
 * Creates a block which displays the button pay subscription
 */

namespace Drupal\stripe_payment\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\stripe_payment\Form\BtnPaySubscription;

/**
 * Provides the Stripe pay subscription block.
 *
 * @Block(
 *   id = "stripe_btn_pay_subscription",
 *   admin_label = @Translation("Button pay of subscription Stripe")
 * )
 */
class PaySubscriptionBlock extends BlockBase
{
  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $form = \Drupal::formBuilder()->getForm('Drupal\stripe_payment\Form\BtnPaySubscription');
    
    // Takes the block title and prints inside the payment button like
    // call to action text.
    $form['submit']['#value'] = $this->configuration["label"];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockAccess(AccountInterface $account)
  {
    // If viewing a node, get the fully loaded node object.
    $node = \Drupal::routeMatch()->getParameter('node');

    // Only shows button in allowed node types.
    if (!(is_null($node))) {
      $nodeType = $node->getType();
      $allowedNodeTypes = \Drupal::config('ppss.settings')->get('content_types');
      $findedNodeType = array_search($node->getType(), $allowedNodeTypes);

      if ($nodeType == $findedNodeType) {
        return AccessResult::allowedIfHasPermission($account, 'stripe payment button');
      }
    }

    return AccessResult::forbidden();

  }
}
