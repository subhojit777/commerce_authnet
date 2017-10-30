<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the AuthorizeNet payment gateway.
 */
interface AuthorizeNetInterface extends OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * Get the AuthorizeNet API Client Key set for the payment gateway.
   *
   * Used by the add-payment-method plugin form.
   *
   * @return string
   *   The AuthorizeNet Client Key.
   */
  public function getClientKey();

  /**
   * Get the AuthorizeNet API Client Key set for the payment gateway.
   *
   * Used by the add-payment-method plugin form.
   *
   * @return string
   *   The AuthorizeNet Client Key.
   */
  public function getApiLogin();

}
