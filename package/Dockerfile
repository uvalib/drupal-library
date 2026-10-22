
# build the target container
#
FROM public.ecr.aws/docker/library/php:8.4.27-apache-bookworm

# PHP2Shell (CVE-2026-109618 / CVE-2026-109619, 2026-10-21): the official drupal image was still on
# PHP 8.4.25 (fix is 8.4.27), so for now we build on php:8.4.27 directly and reproduce the drupal
# image's own steps below, copied from docker-library/drupal 10.6/php8.4/apache-bookworm.
# DRUPAL_VERSION matches core in composer.lock; our composer install below replaces the tree anyway.
# Go back to `FROM drupal:10.6-php8.4-apache` once ./scripts/check-php-base-image.sh --verify exits 0.

# install the PHP extensions we need
RUN set -eux; \
	\
	if command -v a2enmod; then \
# https://github.com/drupal/drupal/blob/d91d8d0a6d3ffe5f0b6dde8c2fbe81404843edc5/.htaccess (references both mod_expires and mod_rewrite explicitly)
		a2enmod expires rewrite; \
	fi; \
	\
	savedAptMark="$(apt-mark showmanual)"; \
	\
	apt-get update; \
	apt-get install -y --no-install-recommends \
		libavif-dev \
		libfreetype6-dev \
		libjpeg-dev \
		libpng-dev \
		libpq-dev \
		libwebp-dev \
		libzip-dev \
	; \
	\
	docker-php-ext-configure gd \
		--with-avif \
		--with-freetype \
		--with-jpeg=/usr \
		--with-webp \
	; \
	\
	docker-php-ext-install -j "$(nproc)" \
		gd \
		pdo_mysql \
		pdo_pgsql \
		zip \
	; \
	\
# reset apt-mark's "manual" list so that "purge --auto-remove" will remove all build dependencies
	apt-mark auto '.*' > /dev/null; \
	apt-mark manual $savedAptMark; \
	ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
		| awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
		| sort -u \
		| xargs -r dpkg-query -S \
		| cut -d: -f1 \
		| sort -u \
		| xargs -rt apt-mark manual; \
	\
	apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
	rm -rf /var/lib/apt/lists/*

# set recommended PHP.ini settings
# see https://secure.php.net/manual/en/opcache.installation.php
RUN { \
		echo 'opcache.memory_consumption=128'; \
		echo 'opcache.interned_strings_buffer=8'; \
		echo 'opcache.max_accelerated_files=4000'; \
		echo 'opcache.revalidate_freq=60'; \
	} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# https://www.drupal.org/node/3298550
# Drupal now recommends sites enable PHP output buffering by default, if PHP is run as a server module
# e.g. with Apache's mod_php
RUN { \
		echo 'output_buffering=true'; \
	} > /usr/local/etc/php/conf.d/docker-php-drupal-recommended.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/

ENV DRUPAL_VERSION 10.6.10

# https://github.com/docker-library/drupal/pull/259
# https://github.com/moby/buildkit/issues/4503
# https://github.com/composer/composer/issues/11839
# https://github.com/composer/composer/issues/11854
# https://github.com/composer/composer/blob/94fe2945456df51e122a492b8d14ac4b54c1d2ce/src/Composer/Console/Application.php#L217-L218
ENV COMPOSER_ALLOW_SUPERUSER 1

WORKDIR /opt/drupal
RUN set -eux; \
	export COMPOSER_HOME="$(mktemp -d)"; \
	composer create-project --no-interaction "drupal/recommended-project:$DRUPAL_VERSION" ./; \
# https://github.com/docker-library/drupal/pull/266#issuecomment-2273985526
	composer check-platform-reqs; \
	chown -R www-data:www-data web/sites web/modules web/themes; \
	rmdir /var/www/html; \
	ln -sf /opt/drupal/web /var/www/html; \
	# delete composer cache
	rm -rf "$COMPOSER_HOME"

ENV PATH=${PATH}:/opt/drupal/vendor/bin

# update the packages
RUN apt-get -y update && apt-get -y upgrade && apt-get -y install default-mysql-client git less vim msmtp-mta \
	&& apt-get -y install rsync

# Show the PHP version in every build log, so it is obvious whether the base image
# has picked up a security release.
RUN php -v | head -1

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

# The prod host's `drush cex --commit` cron (every 2h, see root's crontab) commits config
# export changes from inside this container. Without these, git refuses to operate on the
# bind-mounted config/sync checkout ("dubious ownership") and has no author identity to commit
# with -- both errors are silent to the cron's MAILTO, so this broke unnoticed for ~2 months.
RUN git config --system --add safe.directory /opt/drupal/util/drupal-library/package/data/opt/drupal/config/sync
RUN git config --system --add safe.directory /opt/drupal/config/sync
RUN git config --system user.email "ys2n@virginia.edu"
RUN git config --system user.name "ys2n"

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
