# SimpleSAMLphp Secure Cookie Error on the HTTP Container

## Symptom

SimpleSAMLphp errors on `session.cookie.secure` — it wants a secure (HTTPS) cookie, but
the container is serving plain HTTP, so the check fails and authentication breaks.

## Cause

[HTTPS is terminated at the load balancer](../adr/002-https-at-load-balancer.md); the
container serves HTTP on port 80. Without being told the original request was HTTPS,
SimpleSAMLphp refuses to set a secure cookie over what it sees as an insecure connection.

## Fix

In the Apache vhost (`package/data/etc/apache2/sites-enabled/000-default.conf`), mark the
request as secure when the load balancer forwarded an HTTPS request:

```apache
SetEnvIf X-Forwarded-Proto "https" HTTPS=on
```

Also ensure `SIMPLESAMLPHP_CONFIG_DIR=/var/simplesamlphp/config` is set (Apache `SetEnv`)
and that `/simplesaml/` is proxied to the `sp` container.

See [Authentication (NetBadge)](../architecture/authentication-netbadge.md).
