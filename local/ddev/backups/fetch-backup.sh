#!/bin/bash

HOST="library-drupal-develop"
OUTPUT=./sql/${HOST}-backup.`date +%F-%T`.sql.gz
SRCHOST=${HOST}-0.internal.lib.virginia.edu 
echo "NOTE: this script assumes you have ssh, sudo and docker privileges on $SRCHOST"
echo "Dumping from $SRCHOST to $OUTPUT"

ssh -t $SRCHOST sudo docker exec -it drupal-0 drush sql-dump --extra-dump=--no-tablespaces | gzip > $OUTPUT

( gzip -t $OUTPUT 2>/dev/null && [ "$(gunzip -c $OUTPUT | wc -c)" -ne 0 ]) \
	&& echo "Successfully wrote $OUTPUT" \
	|| ( echo "ERROR: The gzip file is truncated or corrupted."; mv ${OUTPUT} ${OUTPUT}.corrupted  )
