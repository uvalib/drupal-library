# Authentication (NetBadge)

Authenticated access uses UVA **NetBadge** (SAML) via SimpleSAMLphp and the
`drupal/simplesamlphp_auth` module.

## Request flow

- HTTPS is terminated at the **upstream load balancer**; the container itself serves
  plain HTTP on port 80.
- Apache (`package/data/etc/apache2/sites-enabled/000-default.conf`) proxies
  `/simplesaml/` → `http://sp:80/simplesaml/`, where `sp` is the `drupal-netbadge`
  SimpleSAMLphp service provider container.
- `SIMPLESAMLPHP_CONFIG_DIR=/var/simplesamlphp/config` is set as an Apache `SetEnv`.

## HTTPS awareness behind the load balancer

Because the container serves HTTP but sits behind an HTTPS-terminating load balancer,
the vhost sets:

```apache
SetEnvIf X-Forwarded-Proto "https" HTTPS=on
```

This makes Drupal/SimpleSAMLphp treat the request as secure so that
`session.cookie.secure` doesn't fail on the HTTP-serving container.

!!! note "Rationale"
    Terminating TLS at the load balancer and serving HTTP in the container is the
    standard pattern for this infrastructure. The `X-Forwarded-Proto` mapping is the
    glue that keeps secure-cookie logic working. See
    [ADR 002](../adr/002-https-at-load-balancer.md). A related failure mode and its fix
    are documented in
    [Troubleshooting → SimpleSAMLphp secure cookie](../troubleshooting/simplesamlphp-secure-cookie.md).

## Related components

- The SimpleSAMLphp SP container lives in the `uvalib/drupal-netbadge` repo.
- The `uvalib/drupal-uvaldap-module` (cloned to `web/modules/uvaldap`) provides LDAP
  integration.
