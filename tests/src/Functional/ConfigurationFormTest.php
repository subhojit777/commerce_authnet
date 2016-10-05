<?php

namespace Drupal\Tests\commerce_authnet\Functional;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * Tests the Authorize.net payment configurationf orm.
 *
 * @group commerce_authnet
 */
class ConfigurationFormTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_authnet',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_payment_gateway',
    ], parent::getAdministratorPermissions());
  }

  /**
   * Tests creating a payment gateway.
   */
  public function testCreateGateway() {
    $this->drupalGet('admin/commerce/config/payment-gateways');
    $this->getSession()->getPage()->clickLink('Add payment gateway');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/add');
    $values = [
      'id' => 'authorize_net_us',
      'label' => 'Authorize.net US',
      'plugin' => 'authorizenet',
      'status' => 1,
    ];
    $this->submitForm($values, 'Save');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/manage/authorize_net_us');
    $this->assertSession()->pageTextContains('Saved the Authorize.net US payment gateway.');
    $values += [
      'configuration[api_login]' => '5KP3u95bQpv',
      'configuration[transaction_key]' => '346HZ32z3fP4hTG2',
      'configuration[mode]' => 'test',
      'status' => '1',
    ];
    $this->submitForm($values, 'Save');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways');
    $payment_gateway = PaymentGateway::load('authorize_net_us');
    $this->assertEquals('authorize_net_us', $payment_gateway->id());
    $this->assertEquals('Authorize.net US', $payment_gateway->label());
    $this->assertEquals('authorizenet', $payment_gateway->getPluginId());
    $this->assertEquals(TRUE, $payment_gateway->status());
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $this->assertEquals('test', $payment_gateway_plugin->getMode());
    $config = $payment_gateway_plugin->getConfiguration();
    $this->assertEquals('5KP3u95bQpv', $config['api_login']);
    $this->assertEquals('346HZ32z3fP4hTG2', $config['transaction_key']);
  }

}
