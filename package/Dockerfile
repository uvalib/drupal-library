
# build the target container
#
FROM public.ecr.aws/docker/library/drupal:10.6.10

# update the packages
RUN apt-get -y update && apt-get -y upgrade && apt-get -y install default-mysql-client git less vim msmtp-mta \
	&& apt-get -y install rsync

# set the timezone appropriatly.
# NOTE: must be America/New_York, not UTC. SimpleSAMLphp runs in-process here (via
# simplesamlphp_auth) and its config reads 'timezone' => getenv('TZ') ?: 'UTC', then calls
# date_default_timezone_set() on every authenticated request -- clobbering Drupal's own
# America/New_York and skewing all timestamps by the ET offset. See
# docs/incidents/2026-07-10-scheduler-timezone-utc-clobber.md
ENV TZ=America/New_York
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Specify home
ENV APP_HOME /drupal-library
WORKDIR $APP_HOME

# Move in necessary assets
COPY package/data/container_bash_profile /root/.profile
COPY package/data/etc/msmtprc /etc/msmtprc
COPY package/data/usr/local/bin/msmtp-local-maildir-write.sh /usr/local/bin
COPY package/data/opt/drupal/composer.json /opt/drupal/composer.json
COPY package/data/opt/drupal/composer.lock /opt/drupal/composer.lock
COPY package/data/opt/drupal/web/sites/default/settings.php /opt/drupal/web/sites/default/settings.php
COPY package/data/etc/apache2/sites-enabled/000-default.conf /etc/apache2/sites-enabled/000-default.conf

# REBUILD DRUPAL
# 1. checkout the github managed parts:
#	a. custom themes (v1 + v2; switch in Drupal Appearance)
#	b. custom modules
#	c. /opt/drupal/config
# 2. composer install -- we can experiment with special flags here (production vs development build e.g.)

RUN a2enmod proxy
RUN a2enmod proxy_http
RUN a2enmod headers

RUN git clone https://github.com/uvalib/uvalib-drupal-theme.git /opt/drupal/web/themes/uvalib-drupal-theme
RUN git clone https://github.com/uvalib/uvalib_drupal_theme_2026.git /opt/drupal/web/themes/custom/uvalib_drupal_theme_2026
RUN git clone https://github.com/uvalib/drupal_jsonapi_search_api_extension.git /opt/drupal/web/modules/custom/drupal_jsonapi_search_api_extension
RUN git clone https://github.com/uvalib/drupal-uvaldap-module.git /opt/drupal/web/modules/uvaldap
RUN git clone https://github.com/uvalib/drupal-library.git /opt/drupal/util/drupal-library
RUN ln -s /opt/drupal/util/drupal-library/package/data/opt/drupal/config /opt/drupal/config
RUN ln -s /opt/drupal/util/drupal-library/local/ddev/patches /opt/drupal/patches
RUN ln -s /opt/drupal/util/drupal-library/package/data/opt/drupal/vendor-archive /opt/drupal/vendor-archive

# devops docs: the pre-built static mkdocs site (static/) is committed to this
# repo, so the clone above already contains it -- just symlink the module in.
# Rebuild static/ locally with scripts/build-docs.sh after editing docs/.
RUN ln -s /opt/drupal/util/drupal-library/package/data/opt/drupal/web/modules/custom/devops_docs /opt/drupal/web/modules/custom/devops_docs

RUN cd /opt/drupal && ls -l
RUN cd /opt/drupal && ls -la vendor-archive/
RUN cd /opt/drupal && composer install --no-dev --optimize-autoloader

COPY package/data/opt/drupal/scripts/pull-uvalib-drupal-theme /opt/drupal/scripts/pull-uvalib-drupal-theme
RUN chmod +x /opt/drupal/scripts/pull-uvalib-drupal-theme

# Eventually we will want to make this configurable outside the container
COPY package/data/usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# install other dependancies
RUN pecl install apcu && docker-php-ext-enable apcu

# Add the build tag
ARG BUILD_TAG
RUN test -n "$BUILD_TAG" && touch $APP_HOME/buildtag.build-$BUILD_TAG || touch $APP_HOME/buildtag.build-0

#
# end of file
#
