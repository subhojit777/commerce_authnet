<?php

namespace Drupal\Tests\commerce_authnet\FunctionalJavascript;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\Core\Url;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\Tests\commerce\FunctionalJavascript\JavascriptTestTrait;

/**
 * Tests the managing Authorize.net payment methods.
 *
 * @group commerce_authnet
 */
class ManagePaymentMethodsTest extends CommerceBrowserTestBase {

  use JavascriptTestTrait;
  use StoreCreationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface;
   */
  protected $account;

  /**
   * The product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['system', 'field', 'user', 'text',
    'entity', 'views', 'address', 'profile', 'commerce', 'inline_entity_form',
    'commerce_price', 'commerce_store', 'commerce_product', 'commerce_cart',
    'commerce_checkout', 'commerce_order', 'views_ui', 'commerce_authnet',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer payments',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $store = $this->createStore('Demo', 'demo@example.com', 'default', TRUE);

    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'amount' => 9.99,
        'currency_code' => 'USD',
      ],
    ]);

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $this->product = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'My product',
      'variations' => [$variation],
      'stores' => [$store],
    ]);

    PaymentGateway::create([
      'id' => 'authorize_net_us',
      'label' => 'Authorize.net US',
      'plugin' => 'authorizenet',
      'status' => '1',
      'configuration' => [
        'api_login' => '5KP3u95bQpv',
        'transaction_key' => '346HZ32z3fP4hTG2',
        'mode' => 'test',
      ],
    ])->save();
  }

  /**
   * Tests than an order can go through checkout steps.
   *
   * @group registered
   */
  public function testAddingPaymentMethod() {
    /** @var \Drupal\commerce_payment\PaymentGatewayStorageInterface $payment_gateway_storage */
    $payment_gateway_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_gateway');
    $payment_gateway = $payment_gateway_storage->loadForUser($this->loggedInUser);
    $this->assertTrue(!$payment_gateway || !($payment_gateway->getPlugin() instanceof SupportsStoredPaymentMethodsInterface));
    $payment_method_types = $payment_gateway->getPlugin()->getPaymentMethodTypes();
    $this->assertEquals(1, count($payment_method_types));

    $this->drupalGet(Url::fromRoute('entity.commerce_payment_method.add_form', [
      'user' => $this->loggedInUser->id(),
    ])->toString());
    // In tests there's a continue step?
    $this->getSession()->getPage()->pressButton('Continue');
    $this->assertSession()->pageTextContains('Add payment method');

    $this->getSession()->getPage()->fillField('payment_information[add_payment_method][billing_information][address][0][country_code]', 'US');
    $this->getSession()->wait(4000, 'jQuery(\'select[name="payment_information[add_payment_method][billing_information][address][0][administrative_area]"]\').length > 0 && jQuery.active == 0;');
    $this->assertSession()->fieldExists('payment_information[add_payment_method][billing_information][address][0][administrative_area]');
    // @todo This works when not in test. But an illegal choice error thrown?
    $this->submitForm([
      'payment_information[add_payment_method][payment_details][number]' => '4111111111111111',
      'payment_information[add_payment_method][payment_details][expiration][month]' => '01',
      'payment_information[add_payment_method][payment_details][expiration][year]' => '2030',
      'payment_information[add_payment_method][payment_details][security_code]' => '123',
      'payment_information[add_payment_method][billing_information][address][0][recipient]' => 'Johnny Appleseed',
      'payment_information[add_payment_method][billing_information][address][0][address_line1]' => '123 New York Drive',
      'payment_information[add_payment_method][billing_information][address][0][locality]' => 'New York City',
      'payment_information[add_payment_method][billing_information][address][0][administrative_area]' => 'US-NY',
      'payment_information[add_payment_method][billing_information][address][0][postal_code]' => '10001',
    ], 'Save');
    $this->assertSession()->pageTextNotContains('We encountered an error processing your payment method. Please verify your details and try again.');
    $this->assertSession()->pageTextNotContains('We encountered an unexpected error processing your payment method. Please try again later.');

    $html_output = 'GET request to: ' . $this->getSession()->getCurrentUrl() .
      '<hr />Ending URL: ' . $this->getSession()->getCurrentUrl();
    $html_output .= '<hr />' . $this->getSession()->getPage()->getContent();
    $html_output .= $this->getHtmlOutputHeaders();
    $this->htmlOutput($html_output);
  }

}
