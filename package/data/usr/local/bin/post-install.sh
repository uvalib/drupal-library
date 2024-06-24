#!/bin/bash

cd /opt/drupal && composer install --no-dev --optimize-autoloader

DOCROOT=/var/www/html/local/ddev

if [ ! -d ${DOCROOT} ]; then
	mkdir -p /var/www/html/local/ddev
fi

if [ ! -d web/themes/uvalib-drupal-theme]; then
	git clone https://github.com/uvalib/uvalib-drupal-theme.git ${DOCROOT}/web/themes/uvalib-drupal-theme
fi

if [ ! -d ${DOCROOT}/modules/custom/drupal_jsonapi_search_api_extension ]; then
	git clone https://github.com/uvalib/drupal_jsonapi_search_api_extension.git ${DOCROOT}/modules/custom/drupal_jsonapi_search_api_extension
fi
if [ ! -d ${DOCROOT}/modules/uvaldap ]; then
	git clone https://github.com/uvalib/drupal-uvaldap-module.git ${DOCROOT}/modules/uvaldap
fi
if [ ! -d /opt/drupal/drupal-library ]; then
	mkdir -p /opt/drupal
	git clone https://github.com/uvalib/drupal-library.git /opt/drupal/drupal-library
fi
