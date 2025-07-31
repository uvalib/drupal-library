## #ddev-generated
## You can copy this Dockerfile.example to Dockerfile to add configuration
## or packages or anything else to your webimage
## These additions will be appended last to DDEV's own Dockerfile
# RUN echo "Built on $(date)" > /build-date.txt

# Create symlink for the smartmenus zip file for local builds where the web URL is not available
RUN mkdir -p /var/www/html/local/ddev && ln -s /var/www/html/package/data/opt/drupal/vendor-archive /var/www/html/local/ddev/vendor-archive
