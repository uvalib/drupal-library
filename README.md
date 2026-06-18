# drupal-library

Drupal 10 site repository for **library.virginia.edu** (University of Virginia Library).

This repo manages the site's Composer dependencies, exported configuration, Apache
config, container definition, and CI/CD pipeline. The running site is a Docker
container built from `package/Dockerfile`.

## Documentation

Project documentation lives in [`docs/`](docs/index.md) and is built as an MkDocs site.

To preview it locally:

```bash
pip install -r mkdocs/requirements.txt
cd mkdocs && mkdocs serve
```

(Publishing to GitHub Pages is intentionally not configured yet — the docs include
internal hostnames and deployment mechanics. See the docs site config in `mkdocs/`.)

Start at [`docs/index.md`](docs/index.md).
