#!/bin/bash -x

if [ ! -d ${DOCROOT} ]; then
	echo DOCROOT is not a directory! DOCROOT=${DOCROOT}
	exit 1
fi

if [ ! -d ${DOCROOT}/themes/uvalib-drupal-theme ]; then
	git clone https://github.com/uvalib/uvalib-drupal-theme.git ${DOCROOT}/themes/uvalib-drupal-theme
fi

if [ ! -d ${DOCROOT}/modules/custom/drupal_jsonapi_search_api_extension ]; then
	git clone https://github.com/uvalib/drupal_jsonapi_search_api_extension.git ${DOCROOT}/modules/custom/drupal_jsonapi_search_api_extension
fi

if [ ! -d ${DOCROOT}/modules/uvaldap ]; then
	git clone https://github.com/uvalib/drupal-uvaldap-module.git ${DOCROOT}/modules/uvaldap
fi

if [ ! -d /var/www/drupal-library ]; then
	mkdir -p /var/www
	git clone https://github.com/uvalib/drupal-library.git /var/www/drupal-library
fi
