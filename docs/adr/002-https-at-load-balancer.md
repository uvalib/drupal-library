# ADR 002: Terminate HTTPS at the load balancer; serve HTTP in the container

**Status:** Accepted
**Date:** 2026-06-18 (documented retroactively)
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

The site runs as a container behind an upstream load balancer. TLS can be terminated at
the load balancer or inside the container.

## Decision

HTTPS is terminated at the **upstream load balancer**; the container serves plain HTTP
on port 80.

## Consequences

- The Apache vhost (`package/data/etc/apache2/sites-enabled/000-default.conf`) must make
  Drupal/SimpleSAMLphp aware that the original request was secure:
  ```apache
  SetEnvIf X-Forwarded-Proto "https" HTTPS=on
  ```
  Without this, SimpleSAMLphp's `session.cookie.secure` fails on the HTTP container.
- The `/simplesaml/` path is proxied to the `sp` (drupal-netbadge) container over HTTP.
- See [Authentication (NetBadge)](../architecture/authentication-netbadge.md) and
  [Troubleshooting → SimpleSAMLphp secure cookie](../troubleshooting/simplesamlphp-secure-cookie.md).
