<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use CommerceGuys\AuthNet\Response\ResponseInterface;
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
use CommerceGuys\AuthNet\DataTypes\OpaqueData;
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
 *   label = "Authorize.net (Accept.js)",
 *   display_label = "Authorize.net",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_authnet\PluginForm\AuthorizeNet\PaymentMethodAddForm",
 *   },
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
      'client_key' => $this->configuration['client_key'],
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
      'client_key' => '',
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

    $form['client_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Key'),
      '#description' => $this->t('Follow the instructions <a href="https://developer.authorize.net/api/reference/features/acceptjs.html#Obtaining_a_Public_Client_Key">here</a> to get a client key.'),
      '#default_value' => $this->configuration['client_key'],
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
      $this->configuration['client_key'] = $values['client_key'];
      $this->configuration['transaction_type'] = $values['transaction_type'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getClientKey() {
    return $this->configuration['client_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getApiLogin() {
    return $this->configuration['api_login'];
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
    $customer_profile_id = $this->getRemoteCustomerId($owner);

    // Anonymous users get the customer profile and payment profile ids from
    // the payment method remote id.
    if (!$customer_profile_id) {
      list($customer_profile_id, $payment_profile_id) = explode('|', $payment_method->getRemoteId());
    }
    else {
      $payment_profile_id = $payment_method->getRemoteId();
    }

    // Transaction request
    $transaction_request = new TransactionRequest([
      'transactionType' => ($capture) ? TransactionRequest::AUTH_CAPTURE : TransactionRequest::AUTH_ONLY,
      'amount' => $payment->getAmount()->getNumber(),
    ]);

    // @todo update SDK to support data type like this.
    // Initializing the profile to charge and adding it to the transaction.
    $profile_to_charge = new Profile(['customerProfileId' => $customer_profile_id]);
    $profile_to_charge->addData('paymentProfile', ['paymentProfileId' => $payment_profile_id]);
    $transaction_request->addData('profile', $profile_to_charge->toArray());

    // Adding order information to the transaction
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber(),
    ]));
    $transaction_request->addData('customerIP', $order->getIpAddress());

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest($transaction_request);
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
    $required_keys = [
      'data_descriptor', 'data_value'
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    $remote_payment_method = $this->doCreatePaymentMethod($payment_method, $payment_details);

    // @todo Make payment methods reusable. Currently they represent 15min nonce.
    // @see https://community.developer.authorize.net/t5/Integration-and-Testing/Question-about-tokens-transaction-keys/td-p/56689
    // "You are correct that the Accept.js payment nonce must be used within 15 minutes before it expires."
    // Meet specific requirements for reusable, permanent methods.
    $payment_method->setReusable(FALSE);
    $payment_method->card_type = $this->mapCreditCardType($remote_payment_method['card_type']);
    $payment_method->card_number = $remote_payment_method['last4'];
    $payment_method->card_exp_month = $remote_payment_method['expiration_month'];
    $payment_method->card_exp_year = $remote_payment_method['expiration_year'];
    $payment_method->setRemoteId($remote_payment_method['token']);

    // OpaqueData expire after 15min. We reduce that time by 5s to account for the
    // time it took to do the server request after the JS tokenization.
    $expires = $this->time->getRequestTime() + (15 * 60) - 5;
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
    $owner = $payment_method->getOwner();
    $customer_profile_id = NULL;
    $customer_data = [];
    if ($owner && !$owner->isAnonymous()) {
      $customer_profile_id = $this->getRemoteCustomerId($owner);
      $customer_data['email'] = $owner->getEmail();
    }

    if ($customer_profile_id) {
      $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_profile_id);
      $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
      $request->setCustomerProfileId($customer_profile_id);
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
          'email' => $payment_details['customer_email'],
        ]);
      }
      $profile->addPaymentProfile($this->buildCustomerPaymentProfile($payment_method, $payment_details));
      $request->setProfile($profile);
      $response = $request->execute();

      if ($response->getResultCode() == 'Ok') {
        $payment_profile_id = $response->customerPaymentProfileIdList->numericString;
        $customer_profile_id = $response->customerProfileId;
      }
      else {
        // Handle duplicate.
        if ($response->getMessages()[0]->getCode() == 'E00039') {
          $result = array_filter(explode(' ', $response->getMessages()[0]->getText()), 'is_numeric');
          $customer_profile_id = reset($result);

          $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_profile_id);
          $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
          $request->setCustomerProfileId($customer_profile_id);
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
        $this->setRemoteCustomerId($owner, $customer_profile_id);
        $owner->save();
      }
    }

    // Maybe we should make sure that this is going to be a string before calling an explode on it.
    if ($owner->isAuthenticated()) {
      $validation_direct_response = explode(',', $response->contents()->validationDirectResponse);

      // when user is authenticated we can retrieve customer profile from the user entity so
      // we only need to save the payment profile id as token.
      $token = $payment_profile_id;
    }
    else {
      // somehow for anonymous user it's returning this way
      $validation_direct_response = explode(',', $response->contents()->validationDirectResponseList->string);

      // For anonymous user we use both customer id
      // and payment profile id as token.
      $token = $customer_profile_id . '|' . $payment_profile_id;
    }

    // Assuming the explode is working card_type is at index 51 and mask card number at index 50
    // on the form XXXX1111. Not sure if we should use this to get last4 and remove the one in JS.
    // The explode doesn't work as expected I guess we are screwed.
    $card_type = $validation_direct_response[51];

    return [
      'token' => $token,
      'data_descriptor' => $payment_details['data_descriptor'],
      'data_value' => $payment_details['data_value'],
      'card_type' => $card_type,
      'last4' => $payment_details['last4'],
      'expiration_month' => $payment_details['expiration_month'],
      'expiration_year' => $payment_details['expiration_year'],
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
    /** @var \Drupal\address\AddressInterface $address */
    $address = $payment_method->getBillingProfile()->address->first();
    $bill_to = new BillTo([
      // @todo how to allow customizing this.
      'firstName' => $address->getGivenName(),
      'lastName' => $address->getFamilyName(),
      'company' => $address->getOrganization(),
      'address' => $address->getAddressLine1() . ' ' . $address->getAddressLine2(),
      // @todo Use locality  / administrative area codes where available.
      'city' => $address->getLocality(),
      //'state' => $address->getAdministrativeArea(),
      'zip' => $address->getPostalCode(),
      'country' => $address->getCountryCode(),
      // @todo support adding phone and fax
    ]);

    $payment = new OpaqueData([
      'dataDescriptor' => $payment_details['data_descriptor'],
      'dataValue' => $payment_details['data_value'],
    ]);

    $payment_profile = new PaymentProfile([
      // @todo how to allow customizing this.
      'customerType' => 'individual',
    ]);
    $payment_profile->addBillTo($bill_to);
    $payment_profile->addPayment($payment);

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
