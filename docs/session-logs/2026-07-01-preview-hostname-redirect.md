# Session Log: `preview.library.virginia.edu` Default-Redirect Mechanism

**Date:** 2026-07-01
**Participants:** Yuji Shinozaki, Claude Sonnet 5
**Repo:** `terraform-infrastructure` (`library.virginia.edu/production/`)
**Outcome:** Changed `preview.library.virginia.edu` so it redirects to `library.virginia.edu` by default, while keeping it available as an on-demand utility URL pinned to production node 0. Applied to production and verified live. Discovered and resolved unrelated local-checkout drift along the way.

---

## 1. What `preview.library.virginia.edu` actually was

Traced the hostname through `terraform-infrastructure/library.virginia.edu/production/alb-routing.tf`. It's an ALB host-header listener rule (`internal-1`) forwarding unconditionally to a second target group (`target-1`) containing only production node 0 — i.e. a way to hit one specific backend node directly, bypassing the normal two-node load balancing. No dedicated Route53 record exists for it in this Terraform config; the actual DNS entry lives outside this repo, pointed at the same public ALB as the main site.

## 2. Design: redirect by default, keep the pin as a toggle

Decided against tearing down `target-1`/the node-0 attachment, since the pinned-node view is meant to stay available as a utility. Instead added a boolean variable `preview_passthrough_enabled` (default `false`) and made the `internal-1` rule's action conditional: `false` → `redirect` (302, path-preserving) to `library.virginia.edu`; `true` → `forward` to `target-1` as before. Flipping it back for QA is a one-line var change + apply, no resource churn.

## 3. Mechanism for applying it — no CI path

Checked `pipeline/deployspec.yml` (in this repo, `drupal-library`) — the only automated Terraform pipeline is staging (`terraform-infrastructure/library.virginia.edu/staging`). Production Terraform has no CI trigger; changes there are applied manually, from a local checkout, using the `tf` alias (`aws-vault exec staging -- terraform`, where `staging` is the name of the local aws-vault credential profile, not tied to the staging *environment*).

## 4. Unrelated blockers hit and resolved along the way

- **Provider/state version mismatch:** `terraform plan` failed with "Resource instance managed by newer provider version" — the locked `hashicorp/aws` v5.90.0 was older than what last wrote state. Ran `terraform init -upgrade`, which pulled v6.53.0 and resolved it (lockfile change is part of the pushed commit).
- **Stale local checkout:** even after the provider fix, `plan` showed 14 unrelated CloudWatch alarm changes (`evaluation_periods`/`period` drift) plus 3 `local_file` resources wanting to be (re)created. `git fetch` showed local `master` was 22 commits behind `origin/master`; several of those commits updated defaults in the shared `modules/cloudwatch-alarms/ec2_*` modules. Fast-forwarded (`git merge --ff-only`, no conflicts with the uncommitted preview change), which cleared all the alarm drift — confirming it was a stale-checkout artifact, not a config problem.
- **Deprecated attribute pattern:** initial apply used `target_group_arn = condition ? arn : null` inside the `action` block, which triggered a provider warning ("cannot be specified when type is redirect... will be an error in a future release"). Fixed by switching to `dynamic "forward"`/`dynamic "redirect"` blocks so the unused attribute is never present in config, not just null. Re-plan came back clean with no warnings and no diff against already-applied state.

## 5. Applied and verified

Applied scoped to the two affected listener rules (`aws_alb_listener_rule.internal`, `.internal-1`) — the former had pre-existing tag-name drift (`Name` tag said `-public`, code said `-internal`; cosmetic only, bundled in at the user's direction rather than excluded via `-target`). `Apply complete! Resources: 0 added, 2 changed, 0 destroyed.`

Verified live: `curl -I https://preview.library.virginia.edu/` → `HTTP/2 302`, `location: https://library.virginia.edu:443/`.

User committed and pushed the change: `7c68d7a4a` on `terraform-infrastructure` `master`.

---

## Open items (carried forward)

- None specific to this change — `preview_passthrough_enabled` is available for whoever needs the node-0 pinned view next; toggle + `terraform apply -target=aws_alb_listener_rule.internal-1` (or a full apply once other drift is clean).
