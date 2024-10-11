#!/bin/bash

OUTPUT=./sql/local-backup.`date +%F-%T`.sql.gz
echo "dumping local database"

ddev export-db --file=${OUTPUT}
