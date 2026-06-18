# Search (Solr)

Site search is powered by Apache Solr via the `drupal/search_api_solr` module
(with `solarium/solarium`).

## Components

- **`drupal/search_api_solr`** — Search API backend for Solr.
- **`drupal/facets`** — faceted search.
- **`drupal/jsonapi_search_api`** + **`uvalib/drupal_jsonapi_search_api_extension`**
  (cloned to `web/modules/custom/drupal_jsonapi_search_api_extension`) — expose
  Search API queries over JSON:API.

## Why it matters operationally

Solr is an **external dependency**: if the Solr endpoint is unreachable or an index is
stale, search results and any JSON:API search endpoints degrade even though the rest of
the site is up. This makes the search path a high-value target for the
[validation/smoke tests](../maintenance/README.md) — exercising a search query and a
JSON:API search endpoint catches Solr connectivity problems that a homepage check
would miss.

!!! note "Rationale"
    Search runs against existing Solr infrastructure rather than an in-container search
    engine. Treat the Solr service as a managed external dependency when planning
    deploys, smoke tests, and incident response.
