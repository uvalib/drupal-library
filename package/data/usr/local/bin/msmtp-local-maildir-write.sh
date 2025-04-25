#!/bin/bash
OUT_DIR="/opt/drupal/mail"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
RAND=$RANDOM
OUTFILE="$OUT_DIR/email_${TIMESTAMP}_$RAND.eml"

mkdir -p "$OUT_DIR"
cat > "$OUTFILE"
