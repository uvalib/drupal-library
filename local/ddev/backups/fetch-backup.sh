#!/bin/bash -x

ssh -t library-drupal-dev-0.internal.lib.virginia.edu sudo docker exec -it drupal-0 drush sql-dump --extra-dump=--no-tablespaces | gzip > ./sql/library-drupal-dev-backup.`date +%F-%T`.sql.gz
