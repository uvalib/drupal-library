
# build the target container
#
FROM public.ecr.aws/docker/library/drupal:10.3

# update the packages
RUN apt-get -y update && apt-get -y upgrade && apt-get -y install default-mysql-client git less vim msmtp-mta \
	&& apt-get -y install rsync

# set the timezone appropriatly
ENV TZ=UTC
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Specify home
ENV APP_HOME /drupal-library
WORKDIR $APP_HOME

# Move in necessary assets
COPY package/data/container_bash_profile /root/.profile
COPY package/data/etc/msmtprc /etc/msmtprc
COPY package/data/opt/drupal/composer.json /opt/drupal/composer.json
COPY package/data/opt/drupal/composer.lock /opt/drupal/composer.lock
COPY package/data/opt/drupal/web/sites/default/settings.php /opt/drupal/web/sites/default/settings.php

# REBUILD DRUPAL
# 1. checkout the github managed parts:
#	a. custom theme
#	b. custom modules
#	c. /opt/drupal/config
# 2. composer install -- we can experiment with special flags here (production vs development build e.g.)

RUN git clone https://github.com/uvalib/uvalib-drupal-theme.git /opt/drupal/web/themes/uvalib-drupal-theme
RUN git clone https://github.com/uvalib/drupal_jsonapi_search_api_extension.git /opt/drupal/web/modules/custom/drupal_jsonapi_search_api_extension
RUN git clone https://github.com/uvalib/drupal-uvaldap-module.git /opt/drupal/web/modules/uvaldap
RUN git clone https://github.com/uvalib/drupal-library.git /opt/drupal/util/drupal-library
RUN ln -s /opt/drupal/util/drupal-library/package/data/opt/drupal/config /opt/drupal/config
RUN ln -s /opt/drupal/util/drupal-library/local/ddev/patches /opt/drupal/patches
RUN ln -s /opt/drupal/util/drupal-library/package/data/opt/drupal/vendor-archive /opt/drupal/vendor-archive

RUN cd /opt/drupal && ls -l
RUN cd /opt/drupal && composer install --no-dev --optimize-autoloader

COPY package/data/opt/drupal/scripts/pull-uvalib-drupal-theme /opt/drupal/scripts/pull-uvalib-drupal-theme
RUN chmod +x /opt/drupal/scripts/pull-uvalib-drupal-theme

# Eventually we will want to make this configurable outside the container
COPY package/data/usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# install other dependancies
RUN pecl install apcu-5.1.22 && docker-php-ext-enable apcu

# Add the build tag
ARG BUILD_TAG
RUN test -n "$BUILD_TAG" && touch $APP_HOME/buildtag.build-$BUILD_TAG || touch $APP_HOME/buildtag.build-0

#
# end of file
#
