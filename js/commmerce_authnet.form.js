/**
 * @file
 * Javascript to generate Stripe token in PCI-compliant way.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Attaches the coomerceAuthorizwNet behavior.
   * @type {{attach: Drupal.behaviors.commerceAuthorizeNetForm.attach}}
   */
  Drupal.behaviors.commerceAuthorizeNetForm = {
    attach: function (context) {
      var $form = $('.authorize-net-accept-js-form', context).closest('form').once('authorize-net-accept-js-processed');
      if ($form.length === 0) {
        return;
      }
      var settings = drupalSettings.commerceAuthorizeNet;
      var last4 = '';
      // to be used to temporarily store month and year.
      var expiration = {};

      // Adding the card number input.
      var cardNumber = $('<input/>').attr({
        id: 'credit-card-number',
        type: 'tel',
        placeholder: '•••• •••• •••• ••••',
        autocomplete: 'off',
        autocorrect: 'off',
        autocapitalize: 'none'
      });
      $(settings.fieldsSelector.creditCardNumber.selector, $form).append(cardNumber);
      // Adding expiration month and year inputs.
      var expirationMonth = $('<input/>').attr({
        id: 'expiration-month',
        type: 'tel',
        placeholder: 'MM',
        autocomplete: 'off',
        autocorrect: 'off',
        autocapitalize: 'none',
        maxlength: '2'
      });
      $(settings.fieldsSelector.expirationMonth.selector, $form).append(expirationMonth);
      // Adding expiration month and year inputs.
      var expirationYear = $('<input/>').attr({
        id: 'expiration-year',
        type: 'tel',
        placeholder: 'YY',
        autocomplete: 'off',
        autocorrect: 'off',
        autocapitalize: 'none',
        maxlength: '2'
      });
      $(settings.fieldsSelector.expirationYear.selector, $form).append(expirationYear);
      // Adding expiration month and year inputs.
      var cvv = $('<input/>').attr({
        id: 'cvv',
        type: 'tel',
        placeholder: '•••',
        autocomplete: 'off',
        autocorrect: 'off',
        autocapitalize: 'none',
        maxlength: '4'
      });
      $(settings.fieldsSelector.cvv.selector, $form).append(cvv);

      // Sends the card data to Authorize.Net and receive the payment nonce in response.
      var sendPaymentDataToAnet = function (event) {
        var secureData = {};
        var authData = {};
        var cardData = {};

        // Extract the card number, expiration date, and card code.
        cardData.cardNumber = $('#credit-card-number').val();
        cardData.month = $('#expiration-month').val();
        cardData.year = $('#expiration-year').val();
        cardData.cardCode = $('#cvv').val();
        secureData.cardData = cardData;

        // The Authorize.Net Client Key is used in place of the traditional Transaction Key. The Transaction Key
        // is a shared secret and must never be exposed. The Client Key is a public key suitable for use where
        // someone outside the merchant might see it.
        authData.clientKey = settings.clientKey;
        authData.apiLoginID = settings.apiLoginID;
        secureData.authData = authData;

        // Pass the card number and expiration date to Accept.js for submission to Authorize.Net.
        Accept.dispatchData(secureData, responseHandler);
      };

      // Process the response from Authorize.Net to retrieve the two elements of the payment nonce.
      // If the data looks correct, record the OpaqueData to the console and call the transaction processing function.
      var responseHandler = function (response) {
        if (response.messages.resultCode === 'Error') {
          for (var i = 0; i < response.messages.message.length; i++) {
            console.log(response.messages.message[i].code + ': ' + response.messages.message[i].text);
          }
          alert('acceptJS library error!');
          event.preventDefault();
        }
        else {
          console.log(response);
          console.log(response.opaqueData);
          processTransactionDataFromAnet(response.opaqueData);
        }
      };

      var processTransactionDataFromAnet = function (responseData) {
        $('.accept-js-data-descriptor', $form).val(responseData.dataDescriptor);
        $('.accept-js-data-value', $form).val(responseData.dataValue);

        $('.accept-js-data-last4', $form).val(last4);
        $('.accept-js-data-month', $form).val(expiration.month);
        $('.accept-js-data-year', $form).val(expiration.year);

        // Submit the form.
        $form.get(0).submit();
        // @todo maybe check if we should unset the form values here so that they don't get submitted
      };

      // Form submit
      $form.on('submit', function (event) {
        // Disable the submit button to prevent repeated clicks.
        $form.find('button').prop('disabled', true);

        // store last4 digit
        var credit_card_number = $('#credit-card-number').val();
        last4 = credit_card_number.substr(credit_card_number.length - 4);
        expiration = {
          month: $('#expiration-month').val(),
          year: $('#expiration-year').val()
        };

        // send payment data to anet.
        sendPaymentDataToAnet(event);

        // Prevent the form from submitting with the default action.
        if ($('#credit-card-number-element', $form).length) {
          return false;
        }
      });
    }
  };

  $.extend(Drupal.theme, /** @lends Drupal.theme */{
    commerceAuthorizeNetError: function (message) {
      return $('<div class="messages messages--error"></div>').html(message);
    }
  });

})(jQuery, Drupal, drupalSettings);
