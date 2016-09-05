#!/bin/bash

set -e $DRUPAL_TI_DEBUG

# Ensure the right Drupal version is installed.
# Note: This function is re-entrant.
# Note: This runs composer install.
drupal_ti_ensure_drupal

# @todo Patch Travis to not link, but add self as VCS and require.
if [ -L "$DRUPAL_TI_MODULES_PATH/$DRUPAL_TI_MODULE_NAME" ]
then
  unlink "$DRUPAL_TI_MODULES_PATH/$DRUPAL_TI_MODULE_NAME"
  # Stub in placeholder so it isn't re-aded.
  mkdir $DRUPAL_TI_MODULES_PATH/$DRUPAL_TI_MODULE_NAME
fi

# Add custom modules to drupal build.
cd "$DRUPAL_TI_DRUPAL_DIR"

composer config repositories.drupal composer https://packages.drupal.org/8
composer config repositories.commerce_authnet vcs $TRAVIS_BUILD_DIR
composer require drupal/commerce:2.x-dev
composer require drupal/commerce_authnet:999.0.0
composer update

composer update -n --lock --verbose

# Enable main module and submodules.
drush en -y commerce commerce_product commerce_order commerce_authnet

# Turn on PhantomJS for functional Javascript tests
phantomjs --ssl-protocol=any --ignore-ssl-errors=true $DRUPAL_TI_DRUPAL_DIR/vendor/jcalderonzumba/gastonjs/src/Client/main.js 8510 1024 768 2>&1 >> /dev/null &
