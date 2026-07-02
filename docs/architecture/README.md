# Architecture

How the library.virginia.edu Drupal site is built and assembled.

The running site is a Docker container (`package/Dockerfile`, `FROM drupal:10.x`) with
Drupal core and contrib installed via Composer, several external themes/modules cloned
in at build time, and configuration imported from a separate repository.

| Topic | Page |
|-------|------|
| Browser URLs, ALB aliases, backend/SSH hosts per env | [Hostnames & URLs](hostnames-and-urls.md) |
| Container image & repos cloned at build time | [Container & build](container-and-build.md) |
| Where exported Drupal config lives | [Configuration management](config-management.md) |
| NetBadge / SimpleSAMLphp authentication | [Authentication (NetBadge)](authentication-netbadge.md) |
| Solr-backed search | [Search (Solr)](search-solr.md) |

Each page includes a **Rationale** note explaining *why* the design is the way it is —
the durable, structural decisions are also recorded as [ADRs](../adr/README.md).
