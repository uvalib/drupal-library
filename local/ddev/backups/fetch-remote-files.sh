#!/bin/bash -x

LOCAL_DIR="../web/sites/default/files"

REMOTE_HOST="library-drupal-develop-0.internal.lib.virginia.edu"
# SSH options

rsync -avz -e ssh ys2n@library-drupal-develop-0.internal.lib.virginia.edu:/mnt/data/drupal-0/sites/default/files/ $LOCAL_DIR

echo "Rsync download completed successfully."
