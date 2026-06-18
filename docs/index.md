# library.virginia.edu Drupal Site

Operations and architecture documentation for the University of Virginia Library's
Drupal 10 site, **library.virginia.edu**.

The running site is a Docker container built from `package/Dockerfile` and deployed to
AWS via CodeBuild. This repository manages the site's Composer dependencies, exported
Drupal configuration, Apache configuration, container definition, and CI/CD pipeline.
For the codebase, see the [uvalib/drupal-library](https://github.com/uvalib/drupal-library)
repository.

## Sections

- **[Architecture](architecture/README.md)** — how the site is built: container,
  externally-cloned themes/modules, configuration management, authentication, search.
- **[Operations](operations/README.md)** — running and maintaining the site: local
  development with DDEV, syncing data from remote environments, the deployment pipeline.
- **[Maintenance](maintenance/README.md)** — ongoing upkeep: module updates, the
  CKEditor 4 → 5 migration, the Drupal 11 upgrade.
- **[Architecture Decision Records](adr/README.md)** — significant, durable decisions
  about how the site is structured, with rationale.
- **[Troubleshooting](troubleshooting/README.md)** — known issues and their fixes.

## Conventions

Architecture pages explain not just *what* but *why* (inline **Rationale** notes).
Durable structural decisions are captured as immutable [ADRs](adr/README.md).
Operational procedures live in [Operations](operations/README.md); recurring problems
and their resolutions live in [Troubleshooting](troubleshooting/README.md).
