# Request Logging, 404 Noise, and Client IP

!!! info "Why this page exists"
    Started as a narrow question — *are these 404s hacking probes?* — asked while auditing
    the SimpleSAMLphp debug flag on production. The answer turned out to be "about half,"
    but the more useful findings were underneath it: log retention on production is
    **4.5 hours**, and until 2026-08-06 Drupal was recording the load balancer's address as
    the client IP for every request.

## What the 404s actually are

Sampled the most recent 900 `page not found` entries in `watchdog` on production
(2026-08-06):

| Category | Share | Character |
|---|---|---|
| Scanner / probe | **49%** | Commodity vulnerability scanning |
| Legacy URLs | **25%** | Real dead links from the pre-Drupal site |
| Other | 26% | API paths, malformed requests, uncategorized |

### The probe half — background radiation

Predominantly **webshell hunting**: `/coffee.php`, `/als.php`, `/for.php`, `/82.php`, and
randomized names like `/uiuvs58l.php`, `/40p9ixjd.php`, `/tkikikoko.php`, `/SDsadqwrf.php`.
These are not attacks against Drupal — they are scans for backdoors *someone else* may have
already planted on the host. It is the cheapest form of reconnaissance and it runs
continuously against every public address.

The remainder probe for software this site does not run: `/wp-explorer.php` (WordPress),
`/adminner.php` (Adminer), `/config.json.php`. A small cluster probes LMS platforms —
`/d2l/api/lp/1.0/enrollments/...` (Brightspace), `/api/v1/courses?per_page=2` (Canvas),
`/login/token.php` (Moodle).

!!! note "What a 404 log can and cannot tell you"
    Every one of these returned 404 against a site that has no `/coffee.php` to find, so the
    scanning is not landing. But a 404 log by construction records only what **failed** —
    it is evidence that these probes missed, not proof the host is clean. Treat it as
    reassuring background, not as a security audit.

**Do not try to block them.** It is whack-a-mole against automated noise, and every rule
becomes something to maintain. The problem they cause here is not risk, it is *log volume*.

### The legacy half — the actionable one

`/area-studies/SouthAsia/SAserials/Dawn/1996/25Ap96.html`,
`/small/exhibits/lewis_clark/exploring/ch2-14.html`, `/speccol/newlib/construction/`,
`/old-press/01-02/artlibrarian.html`, `/mellon/ch4.html`,
`/digital-production-group/files/2013/02/AIMS_final_appF.pdf`.

These are URLs from the **pre-Drupal library site** — 1990s and 2000s static content. Something
still links to them: search engines, citations in published work, old bookmarks, LibGuides.
A quarter of the 404s are real people and crawlers reaching content that moved or vanished,
and redirects would recover that traffic.

## Log retention is 4.5 hours

Measured on production, 2026-08-06:

| | |
|---|---|
| `dblog.settings` `row_limit` | 1000 |
| Rows retained | 1022 |
| Of which `page not found` | 872 (~194/hour) |
| **Retained window** | **4.5 hours** (06:07 → 10:40) |

!!! warning "Under five hours of forensic history"
    Scanning noise consumes the log budget, so `watchdog` holds less than a shift's worth of
    history. During an incident, anything that happened the previous day is already gone.
    This is a quiet operational limitation that only shows up when you need it.

Two ways to buy the history back, not mutually exclusive:

- **Raise `row_limit`** well above 1000. It is DB-backed, so weigh it against
  `watchdog` table growth on the shared RDS instance.
- **Stop logging 404s** (or route them elsewhere) so the budget is spent on events that
  carry signal.

## Client IP was the load balancer

Every 404 above was attributed to one of **five internal `10.130.x.x` addresses** — the ALB's
network interfaces, not clients. The cause:

```php
settings.php:388   # $settings['reverse_proxy'] = TRUE;
settings.php:394   # $settings['reverse_proxy_addresses'] = ['a.b.c.d', ...];
```

Both were still commented out. `trusted_host_patterns` was configured, but `reverse_proxy`
was not, so Drupal never read `X-Forwarded-For` and treated the load balancer as the client.

!!! danger "What that broke"
    - **`watchdog` hostname was meaningless** — no way to distinguish a scanner from a
      patron from the uptime monitor.
    - **Every IP-based mechanism was blind** — flood control, rate limiting, and IP blocking
      all keyed on the proxy address, so they applied collectively to everyone behind the
      ALB rather than to individuals.
    - **Incident forensics started from nothing.**

This is distinct from the `X-Forwarded-Proto` handling already in the Apache vhost
(see [ADR 002](../adr/002-https-at-load-balancer.md)), which fixes the request *scheme* so
SimpleSAMLphp's secure cookie works. Client IP is a separate setting and had never been
enabled.

### The fix

Configured in `package/data/opt/drupal/web/sites/default/settings.php`:

```php
$settings['reverse_proxy'] = TRUE;

$settings['reverse_proxy_addresses'] = [
  // production
  '10.130.110.0/24',
  '10.130.113.0/24',
  // staging + develop (shared VPC)
  '10.130.109.0/24',
  '10.130.112.0/24',
];

$settings['reverse_proxy_trusted_headers'] =
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_AWS_ELB;
```

**Why VPC CIDRs rather than ALB IPs.** ALB ENI addresses are dynamic and rotate within their
subnets, so a list of individual IPs would rot. The ranges above are the authoritative VPC
extents, read from EC2 instance metadata rather than inferred:

```bash
TOK=$(curl -s -X PUT "http://169.254.169.254/latest/api/token" \
      -H "X-aws-ec2-metadata-token-ttl-seconds: 60")
MAC=$(curl -s -H "X-aws-ec2-metadata-token: $TOK" \
      http://169.254.169.254/latest/meta-data/network/interfaces/macs/ | head -1)
curl -s -H "X-aws-ec2-metadata-token: $TOK" \
  "http://169.254.169.254/latest/meta-data/network/interfaces/macs/${MAC}vpc-ipv4-cidr-blocks"
```

**Why all environments are listed.** `settings.php` is baked into the image and shared, and a
CIDR belonging to another VPC can never appear as `REMOTE_ADDR` in this one — so listing them
together is safe.

**Why not simply `10.0.0.0/8`.** The container is reachable directly on `:8080` from the UVA
VPN, bypassing the ALB. A broad range would let a VPN client forge `X-Forwarded-For` and
choose its own logged identity. The ranges above exclude VPN clients, so their forged header
is ignored.

!!! danger "`HEADER_X_FORWARDED_ALL` is a fatal error here — do not uncomment the example"
    The Drupal core comment block still lists
    `\Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_ALL` as a "common value".
    That constant was **removed in Symfony 6**, which this site runs. Referencing it is a
    fatal error, not a deprecation — uncommenting the boilerplate verbatim takes the site
    down. Verified against the running container: the constant does not exist.

    `HEADER_X_FORWARDED_AWS_ELB` (= `FOR|PROTO|PORT`, value 26) is Symfony's purpose-built
    mask for this topology. It omits `X-Forwarded-Host`, which the ALB does not set and which
    would otherwise widen host-header injection surface.

### Verified behaviour

Tested against the real Symfony in the production container before shipping (read-only — the
trusted-proxy list was applied to a constructed `Request`, not to the site):

| `REMOTE_ADDR` | claims `X-Forwarded-For` | resolved client IP | trusted |
|---|---|---|---|
| `10.130.110.27` (ALB ENI) | `199.111.42.7` | `199.111.42.7` | yes |
| `10.130.113.40` (ALB, other block) | `66.249.66.1` | `66.249.66.1` | yes |
| `10.130.109.5` (staging/dev ALB) | `128.143.1.20` | `128.143.1.20` | yes |
| `172.27.4.9` (VPN client on `:8080`) | `1.2.3.4` | **`172.27.4.9`** | **no** |

The last row is the one that matters: a forged header from outside the trusted ranges is
correctly discarded.

!!! note "Takes effect on next deploy"
    `settings.php` ships inside the image, so this is inert until an image built from `main`
    is deployed to each environment. Confirm afterwards with:
    ```bash
    ssh <host> 'sudo docker exec -i drupal-0 /opt/drupal/vendor/bin/drush php:eval \
      "print_r(\Symfony\Component\HttpFoundation\Request::getTrustedProxies());"'
    ```
    Then check that new `watchdog` rows carry public client addresses rather than `10.130.x.x`.

## Apache logs are a separate layer — still unfixed

`$settings['reverse_proxy']` is a PHP/Symfony setting. It changes what
`$request->getClientIp()` returns *inside Drupal* — which is what feeds `watchdog`, flood
control, and rate limiting — and nothing else. **Apache's access log is untouched.**

`mod_remoteip` is not loaded, and the vhost uses the stock `combined` format whose `%h` is
the TCP peer, so every line records the load balancer:

```
10.130.109.39 - - [06/Aug/2026:12:43:37 -0400] "GET / HTTP/1.1" 200 14987 "-" "ELB-HealthChecker/2.0"
```

Two ways to fix it, if wanted:

- **`%{X-Forwarded-For}i` in `LogFormat`** — trivial, but logs the raw header with no trust
  check, so it is spoofable and may hold a comma-separated chain.
- **`mod_remoteip`** (`RemoteIPHeader X-Forwarded-For` + `RemoteIPTrustedProxy` with the same
  CIDRs as above) — the proper fix. It rewrites `%h` *and* `REMOTE_ADDR` before PHP runs.
  The two layers then agree rather than conflict: `REMOTE_ADDR` becomes the real client,
  which falls outside the trusted ranges, so Drupal uses it directly.

Lower priority than `watchdog` — Apache logs here are ephemeral container stdout, while
`watchdog` is what you actually query during an incident.

!!! tip "The health-check lines double as CIDR confirmation"
    Those `ELB-HealthChecker/2.0` entries arrive from `10.130.109.x` and `10.130.112.x` —
    inside the staging ranges configured in `reverse_proxy_addresses`, derived independently
    from instance metadata. Two unrelated sources agreeing is a useful sanity check on the
    trusted-proxy list.

    They also reveal that **the ALB health-checks `/`**, a Drupal-served path. That matters
    well beyond logging: under maintenance mode Drupal returns 503 on `/`, so the health
    check would fail unless the target group's matcher tolerates it. Recorded as a hard gate
    in the [CKEditor 4 removal runbook](../operations/ckeditor4-prod-removal-runbook.md).

## Follow-ups

- **Raise `dblog.settings row_limit`** (or stop logging 404s) so retention is measured in days
  rather than hours.
- **Mine the legacy 404s for redirects** — the one category with user value on the other side.
  The `redirect` module is already available; the top offenders are `/area-studies/*`,
  `/small/exhibits/*`, `/speccol/*`, and `/old-press/*`.
- **Revisit IP-based protections once real client IPs are flowing.** Flood control and rate
  limiting were effectively inert before this change; they are worth re-tuning now that they
  can distinguish one client from another.
