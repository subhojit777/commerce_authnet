<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use CommerceGuys\AuthNet\Response\ResponseInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\CreateCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\CreateCustomerProfileRequest;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use CommerceGuys\AuthNet\DataTypes\CreditCard as CreditCardDataType;
use CommerceGuys\AuthNet\DataTypes\MerchantAuthentication;
use CommerceGuys\AuthNet\DataTypes\Order as OrderDataType;
use CommerceGuys\AuthNet\DataTypes\PaymentProfile;
use CommerceGuys\AuthNet\DataTypes\Profile;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\DeleteCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\Request\XmlRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Authorize.net payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "authorizenet",
 *   label = "Authorize.net",
 *   display_label = "Authorize.net",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa"
 *   },
 * )
 */
class AuthorizeNet extends OnsitePaymentGatewayBase implements AuthorizeNetInterface {

  /**
   * The Authorize.net API configuration.
   *
   * @var \CommerceGuys\AuthNet\Configuration
   */
  protected $authnetConfiguration;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ClientInterface $client, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->httpClient = $client;
    $this->logger = $logger;
    $this->authnetConfiguration = new Configuration([
      'sandbox' => ($this->getMode() == 'test'),
      'api_login' => $this->configuration['api_login'],
      'transaction_key' => $this->configuration['transaction_key'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client'),
      $container->get('commerce_authnet.logger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_login' => '',
      'transaction_key' => '',
      'transaction_type' => TransactionRequest::AUTH_ONLY,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['api_login'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Login ID'),
      '#default_value' => $this->configuration['api_login'],
      '#required' => TRUE,
    ];
    $form['transaction_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction Key'),
      '#default_value' => $this->configuration['transaction_key'],
      '#required' => TRUE,
    ];
    $form['transaction_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default credit card transaction type'),
      '#description' => t('The default will be used to process transactions during checkout.'),
      '#default_value' => $this->configuration['transaction_type'],
      '#options' => [
        TransactionRequest::AUTH_ONLY => $this->t('Authorization only (requires manual or automated capture after checkout)'),
        TransactionRequest::AUTH_CAPTURE => $this->t('Authorization and capture'),
        // @todo AUTH_ONLY but causes capture at placed transition.
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    if (!empty($values['api_login']) && !empty($values['transaction_key'])) {
      $request = new XmlRequest(new Configuration([
        'sandbox' => ($values['mode'] == 'test'),
        'api_login' => $values['api_login'],
        'transaction_key' => $values['transaction_key'],
      ]), $this->httpClient, 'authenticateTestRequest');
      $request->addDataType(new MerchantAuthentication([
        'name' => $values['api_login'],
        'transactionKey' => $values['transaction_key'],
      ]));
      $response = $request->sendRequest();

      if ($response->getResultCode() != 'Ok') {
        $this->logResponse($response);
        drupal_set_message($this->describeResponse($response), 'error');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_login'] = $values['api_login'];
      $this->configuration['transaction_key'] = $values['transaction_key'];
      $this->configuration['transaction_type'] = $values['transaction_type'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    $order = $payment->getOrder();
    $owner = $payment_method->getOwner();
    $customer_id = $this->getRemoteCustomerId($owner);

    $transactionRequest = new TransactionRequest([
      'transactionType' => ($capture) ? TransactionRequest::AUTH_CAPTURE : TransactionRequest::AUTH_ONLY,
      'amount' => $payment->getAmount()->getNumber(),
    ]);
    // @todo update SDK to support data type like this.
    $transactionRequest->addDataType(new Profile([
      'customerProfileId' => $customer_id,
      'paymentProfile' => [
        'paymentProfileId' => $payment_method->getRemoteId(),
      ],
    ]));
    $transactionRequest->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber(),
    ]));
    $transactionRequest->addData('customerIP', $order->getIpAddress());

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest($transactionRequest);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      switch ($message->getCode()) {
        case 'E00040':
          $payment_method->delete();
          throw new PaymentGatewayException('The provided payment method is no longer valid');

        default:
          throw new PaymentGatewayException($message->getText());
      }
    }

    if (!empty($response->getErrors())) {
      $message = $response->getErrors()[0];
      throw new HardDeclineException($message->getText());
    }

    $next_state = $capture ? 'completed' : 'authorization';
    $payment->setState($next_state);
    $payment->setRemoteId($response->transactionResponse->transId);
    // @todo Find out how long an authorization is valid, set its expiration.
    $payment->save();

  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest(new TransactionRequest([
      'transactionType' => TransactionRequest::PRIOR_AUTH_CAPTURE,
      'amount' => $amount->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]));
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest(new TransactionRequest([
      'transactionType' => TransactionRequest::VOID,
      'amount' => $payment->getAmount()->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]));
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $transaction_request = new TransactionRequest([
      'transactionType' => TransactionRequest::REFUND,
      'amount' => $amount->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]);
    /** @var \Drupal\commerce_payment\Entity\PaymentMethod $payment_method */
    $payment_method = $payment->getPaymentMethod();
    $transaction_request->addPayment(new CreditCardDataType([
      'cardNumber' => $payment_method->card_number->value,
      'expirationDate' => $payment_method->card_exp_month->value . $payment_method->card_exp_year->value,
    ]));
    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @todo Needs kernel test
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $remote_payment_method = $this->doCreatePaymentMethod($payment_method, $payment_details);

    $payment_method->card_type = $remote_payment_method['card_type'];
    $payment_method->card_number = $remote_payment_method['last4'];
    $payment_method->card_exp_month = $remote_payment_method['expiration_month'];
    $payment_method->card_exp_year = $remote_payment_method['expiration_year'];
    $payment_method->setRemoteId($remote_payment_method['token']);
    $expires = CreditCard::calculateExpirationTimestamp($remote_payment_method['expiration_month'], $remote_payment_method['expiration_year']);
    $payment_method->setExpiresTime($expires);

    $payment_method->save();
  }

  /**
   * Creates the payment method on the gateway.
   *
   * @todo Rename to customer profile
   * @todo Make a method for just creating payment profile on existing profile.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   *
   * @return array
   *   The payment method information returned by the gateway. Notable keys:
   *   - token: The remote ID.
   *   Credit card specific keys:
   *   - card_type: The card type.
   *   - last4: The last 4 digits of the credit card number.
   *   - expiration_month: The expiration month.
   *   - expiration_year: The expiration year.
   */
  protected function doCreatePaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $card_type = CreditCard::detectType($payment_details['number'])->getId();
    $owner = $payment_method->getOwner();
    $customer_id = NULL;
    if ($owner->isAuthenticated()) {
      $customer_id = $this->getRemoteCustomerId($owner);
    }

    if ($customer_id) {
      $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_id);
      $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
      $request->setCustomerProfileId($customer_id);
      $request->setPaymentProfile($payment_profile);
      $response = $request->execute();

      if ($response->getResultCode() != 'Ok') {
        $this->logResponse($response);
        $error = $response->getMessages()[0];
        switch ($error->getCode()) {
          case 'E00039':
            if (!isset($response->customerPaymentProfileId)) {
              throw new InvalidResponseException('Duplicate payment profile ID, however could not get existing ID.');
            }
            break;

          case 'E00040':
            // The customer record ID is invalid, remove it.
            // @note this should only happen in development scenarios.
            $this->setRemoteCustomerId($owner, NULL);
            $owner->save();
            throw new InvalidResponseException('The customer record could not be found');

          default:
            throw new InvalidResponseException($error->getText());
        }
      }

      $payment_profile_id = $response->customerPaymentProfileId;
    }
    else {
      $request = new CreateCustomerProfileRequest($this->authnetConfiguration, $this->httpClient);

      if ($owner->isAuthenticated()) {
        $profile = new Profile([
          // @todo how to allow altering.
          'merchantCustomerId' => $owner->id(),
          'email' => $owner->getEmail(),
        ]);
      }
      else {
        $profile = new Profile([
          // @todo how to allow altering.
          'merchantCustomerId' => $owner->id() . '_' . $this->time->getRequestTime(),
          'email' => $owner->getEmail(),
        ]);
      }
      $profile->addPaymentProfile($this->buildCustomerPaymentProfile($payment_method, $payment_details));
      $request->setProfile($profile);
      $response = $request->execute();

      if ($response->getResultCode() == 'Ok') {
        $payment_profile_id = $response->customerPaymentProfileIdList->numericString;
      }
      else {
        // Handle duplicate.
        if ($response->getMessages()[0]->getCode() == 'E00039') {
          $result = array_filter(explode(' ', $response->getMessages()[0]->getText()), 'is_numeric');
          $customer_id = reset($result);

          $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_id);
          $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
          $request->setCustomerProfileId($customer_id);
          $request->setPaymentProfile($payment_profile);
          $response = $request->execute();

          if ($response->getResultCode() != 'Ok') {
            $this->logResponse($response);
            throw new InvalidResponseException("Unable to create payment profile for existing customer");
          }

          $payment_profile_id = $response->customerPaymentProfileId;
        }
        else {
          $this->logResponse($response);
          throw new InvalidResponseException("Unable to create customer profile.");
        }
      }

      if ($owner) {
        $this->setRemoteCustomerId($owner, $response->customerProfileId);
        $owner->save();
      }
    }

    return [
      'token' => $payment_profile_id,
      'card_type' => $card_type,
      'last4' => substr($payment_details['number'], -4),
      'expiration_month' => $payment_details['expiration']['month'],
      'expiration_year' => $payment_details['expiration']['year'],
    ];
  }

  /**
   * Creates a new customer payment profile in Authorize.net CIM.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   * @param string $customer_id
   *   The remote customer ID, if available.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\PaymentProfile
   *   The payment profile data type.
   */
  protected function buildCustomerPaymentProfile(PaymentMethodInterface $payment_method, array $payment_details, $customer_id = NULL) {
    $payment_profile = new PaymentProfile([
      // @todo how to allow customizing this.
      'customerType' => 'individual',
    ]);
    /** @var \Drupal\address\AddressInterface $address */
    $address = $payment_method->getBillingProfile()->address->first();
    $payment_profile->addBillTo(new BillTo([
      // @todo how to allow customizing this.
      'firstName' => $address->getGivenName(),
      'lastName' => $address->getFamilyName(),
      'company' => $address->getOrganization(),
      'address' => $address->getAddressLine1() . ' ' . $address->getAddressLine2(),
      // @todo Use locality  / administrative area codes where available.
      'city' => $address->getLocality(),
      'state' => $address->getAdministrativeArea(),
      'zip' => $address->getPostalCode(),
      'country' => $address->getCountryCode(),
      // @todo support adding phone and fax
    ]));
    $payment_profile->addPayment(new CreditCardDataType([
      'cardNumber' => $payment_details['number'],
      'expirationDate' => $payment_details['expiration']['year'] . '-' . str_pad($payment_details['expiration']['month'], 2, '0', STR_PAD_LEFT),
      'cardCode' => $payment_details['security_code'],
    ]));
    return $payment_profile;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Needs kernel test
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $owner = $payment_method->getOwner();
    $customer_id = $this->getRemoteCustomerId($owner);

    $request = new DeleteCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
    $request->setCustomerProfileId($customer_id);
    $request->setCustomerPaymentProfileId($payment_method->getRemoteId());
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      // If the error is not "record not found" throw an error.
      if ($message->getCode() != 'E00040') {
        throw new InvalidResponseException("Unable to delete payment method");
      }
    }

    $payment_method->delete();
  }

  /**
   * Maps the Authorize.Net credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Authorize.Net credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'American Express' => 'amex',
      'Diners Club' => 'dinersclub',
      'Discover' => 'discover',
      'JCB' => 'jcb',
      'MasterCard' => 'mastercard',
      'Visa' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

  /**
   * Writes an API response to the log for debugging.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   */
  protected function logResponse(ResponseInterface $response) {
    $message = $this->describeResponse($response);
    $level = $response->getResultCode() === 'Error' ? 'error' : 'info';
    $this->logger->log($level, $message);
  }

  /**
   * Formats an API response as a string.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   *
   * @return string
   *   The message.
   */
  protected function describeResponse(ResponseInterface $response) {
    $messages = [];
    foreach ($response->getMessages() as $message) {
      $messages[] = $message->getCode() . ': ' . $message->getText();
    }

    return $this->t('Received response with code %code from Authorize.net: @messages', [
      '%code' => $response->getResultCode(),
      '@messages' => implode("\n", $messages),
    ]);
  }

}
