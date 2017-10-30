<?php

namespace Drupal\commerce_authnet\PluginForm\AuthorizeNet;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;

class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    // Alter the form with AuthorizeNet Accept JS specific needs.
    $element['#attributes']['class'][] = 'authorize-net-accept-js-form';
    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AuthorizeNetInterface $plugin */
    $plugin = $this->plugin;

    if ($plugin->getMode() == 'test') {
      $element['#attached']['library'][] = 'commerce_authnet/accept-js-sandbox';
    }
    else {
      $element['#attached']['library'][] = 'commerce_authnet/accept-js-production';
    }
    $element['#attached']['library'][] = 'commerce_authnet/form';
    $element['#attached']['drupalSettings']['commerceAuthorizeNet'] = [
      'clientKey' => $plugin->getClientKey(),
      'apiLoginID' => $plugin->getApiLogin(),
      'fieldsSelector' => [
        'creditCardNumber' => ['selector' => '#credit-card-number-element'],
        'cvv' => ['selector' => '#cvv-element'],
        'expirationMonth' => ['selector' => '#expiration-month-element'],
        'expirationYear' => ['selector' => '#expiration-year-element'],
      ],
    ];

    // Fields placeholder to be built by the JS
    $element['card_number'] = [
      '#type' => 'item',
      '#title' => t('Card number'),
      '#label_attributes' => [
        'class' => ['js-form-required', 'form-required'],
      ],
      '#markup' => '<div id="credit-card-number-element" class="accept-js-form-element"></div>',
    ];
    $element['expiration'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['credit-card-form__expiration'],
      ],
    ];
    $element['expiration']['month'] = [
      '#type' => 'item',
      '#title' => t('Month'),
      '#label_attributes' => [
        'class' => ['js-form-required', 'form-required'],
      ],
      '#markup' => '<div id="expiration-month-element" class="accept-js-form-element"></div>',
    ];
    $element['expiration']['divider'] = [
      '#type' => 'item',
      '#title' => '',
      '#markup' => '<span class="credit-card-form__divider">/</span>',
    ];
    $element['expiration']['year'] = [
      '#type' => 'item',
      '#title' => t('Year'),
      '#label_attributes' => [
        'class' => ['js-form-required', 'form-required'],
      ],
      '#markup' => '<div id="expiration-year-element" class="accept-js-form-element"></div>',
    ];
    $element['cvv'] = [
      '#type' => 'item',
      '#title' => t('CVV'),
      '#label_attributes' => [
        'class' => ['js-form-required', 'form-required'],
      ],
      '#markup' => '<div id="cvv-element" class="accept-js-form-element"></div>',
    ];

    // Populated by the JS library after receiving a response from AuthorizeNet.
    $element['data_descriptor'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-descriptor'],
      ],
    ];
    $element['data_value'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-value'],
      ],
    ];
    $element['last4'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-last4'],
      ],
    ];
    $element['expiration_month'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-month'],
      ],
    ];
    $element['expiration_year'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-year'],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // The JS library performs its own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    // The payment gateway plugin will process the submitted payment details.
    $values = $form_state->getValues();
    if (!empty($values['contact_information']['email'])) {
      // then we are dealing with anonymous user. Adding a customer email.
      $payment_details = $values['payment_information']['add_payment_method']['payment_details'];
      $payment_details['customer_email'] = $values['contact_information']['email'];
      $form_state->setValue(['payment_information', 'add_payment_method', 'payment_details'], $payment_details);
    }
  }

}
