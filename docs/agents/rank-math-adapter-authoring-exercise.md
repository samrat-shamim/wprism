# Rank Math adapter-authoring exercise — 2026-08-27

This is the interaction record for a real user-and-coding-agent rehearsal of
Duo's decentralized adapter workflow. It records observable prompts, commands,
refusals and conclusions; it does not contain private reasoning. The reusable
artifact is the deliberately site-local
[`rank-math.json`](../../sandbox/fixtures/rank-math/adapters/rank-math.json),
not a shipped capability claim.

## Actual user interactions

### U1 — exercise the authoring system

**User**

> now lets test and harden the adapter authoring and management system through
> real life exercises. select one popular plugin. author the adapter for it,
> refine the guide/machinary along the way. imagine that a user of the platform
> will use their coding agents for this. you play the role of the user and the
> agent. record all user interactions along the way.

**Coding agent**

Selected Rank Math SEO because the WordPress.org plugin API reported version
1.0.277 as current with 4,000,000 active installations. Downloaded official
1.0.277 and adjacent 1.0.276 archives and recorded their local SHA-256 digests
in [`rank-math.releases.json`](../../sandbox/fixtures/rank-math/rank-math.releases.json).
Kept the adapter out of `manifests/`: this exercise creates a reusable site
adapter fixture and evidence, not a Duo product claim.

### U2 — delegate according to cost

**User**

> use terra/luna appropriately.

**Coding agent**

Used Luna agents for fast candidate and toolchain reconnaissance, and a Terra
agent for the plugin-state architecture and later the target duplicate-identity
analysis. The primary agent retained manifest decisions, live mutations and
integration testing.

### U3 — question the digest gate

**User**

> can we relax the digest requirement?

**Coding agent**

Kept the digest requirement. It binds organizational approval to the exact
adapter bytes; weakening it would let edited behavior retain an older approval.
The exercise had exposed an inspection bug, not a digest-design problem:
certification and pinning were correct, but `adapter inspect` rebuilt policy
without the repository's exact site pin and falsely reported
`adapter_certification_unpinned`. The catalog now retains an exact matching
site pin, while `duo adapter certify … --pin` remains the one-step authoring
path.

## Simulated platform-user / coding-agent transcript

The “platform user” lines below are the tasks an ordinary customer would give
their coding agent. The “coding agent” lines record the public operation or
decision that followed.

### 1. Establish a real source, not a synthetic fixture

**Platform user**

> Configure Rank Math the way a small-business site would use it, including SEO
> metadata and a redirection. Do it through the plugin UI and APIs.

**Coding agent**

- Opened the real WordPress administration UI and completed Rank Math's setup
  wizard as a disconnected Small Business Site: site identity, organization,
  default robots/schema choices, link behavior, enabled 404/redirections, and
  intentionally disabled auto-update and role-manager.
- Created a destination page, a Unicode post, a category and an attachment.
  Used Rank Math's authenticated REST writer for title, description, focus
  keyword, robots, canonical/social fields, attachment references, primary
  category and custom schema.
- Created a native 302 redirection and exercised it through HTTP so authored
  rule fields could be distinguished from hits and timestamps.

The UI step mattered. It exposed `rank_math_registration_skip` as executable
setup state: without the disconnected user's skip choice, Rank Math returns
before constructing its module manager, so copied module settings are inert.

### 2. Ask Duo what it does not know

**Platform user**

> Start from the public authoring machinery. Show me unknown surfaces before
> making classifications.

**Coding agent**

Ran init with the explicit unmanaged-plugin posture, then survey, journal
report, pending, coverage, observer, probe and draft. The first unscoped draft
was 223,185 bytes and retained 78 unrelated stale machine proposals even after
`--match='^rank[-_]math'`; it also failed initially because the documented
output directory did not exist.

The guide now creates `<site-repo>/adapters` and scopes coverage seeds. Draft
refresh now removes only stale, unratified, untouched generated proposals;
edited, ratified and legacy fragments remain. The same scoped rerun produced a
21,736-byte draft with 15 relevant proposals.

### 3. Resolve ambiguous references from real evidence

**Platform user**

> Classify every local ID as a reference, counter, runtime value or unsupported
> state. Do not guess from the number alone.

**Coding agent**

The pending queue described `rank_math_primary_category=2` as post/page 2
because a post and the intended term shared that local ID. The key and native
writer proved it was a category term. Pending now checks terms first for
term/category/tag-shaped keys and preserves post-first behavior for other
ambiguous names. The adapter declares this dynamic family as `ref: term` with
`cast: string`.

Attachment image IDs are post references. App-store IDs, scores and counters
are not. Runtime analysis/link metadata is excluded. Sitemap and Instant
Indexing option objects remain target-owned because their current storage mixes
local-ID lists or generated credentials with otherwise portable fields.

### 4. Make unsupported schema fail loudly

**Platform user**

> Prove the adapter does not silently lose a Rank Math custom schema.

**Coding agent**

Created one custom Article schema through Rank Math's native endpoint. Capture
refused `incomplete_state_discovery` and named both undeclared surfaces:
`rank_math_schema_Article` and its `rank_math_shortcode_schema_*` shortcut. The
shortcut stores the source-local `wp_postmeta.meta_id`, for which this adapter
has no portable identity or provider. Removed the schema through the plugin's
authenticated delete path and kept both families deliberately unsupported.

### 5. Find a portable redirection identity

**Platform user**

> Transfer authored redirection rules, but never copy counters, clocks or cache
> rows. Test it against a target that already has its own rule.

**Coding agent**

An initial mapped-identity declaration refused the populated target because it
correctly lacked the source's Duo identity sidecar. Rank Math 1.0.277 source
showed that its writer strips the domain, canonically serializes `sources`, and
uses that value to update an existing rule. The adapter therefore uses the
opaque serialized `sources` bytes as the natural key, tokenizes `url_to`, keeps
status/header authored, treats hits/timestamps as runtime and invalidates only
the derived cache rows.

A column codec on the natural identity was tried and refused by grammar. That
was the right result: rewriting an identity component would make lookup and
portable UUID derivation disagree. No codec is needed because the native writer
already removes the home domain.

### 6. Certify and pin exact bytes

**Platform user**

> Approve this site adapter and make sure every management view agrees.

**Coding agent**

Generated a site trust-root key, ran `duo adapter certify … --pin`, and verified
doctor and promotion readiness. `duo adapter inspect` alone incorrectly said
the exact certificate was unpinned. The catalog's inspection policy now
preserves the repository's exact `{name, source, digest}` pin; the real command
then reported `site_signed` and `certified`.

### 7. Apply to a hostile target

**Platform user**

> Do not use a clean clone. Pre-populate the target, force different IDs, and
> preserve target-only state.

**Coding agent**

Created target posts 4–15, terms 2–6, another hostile post, divergent Rank Math
options and a target-only 307 redirection. The first plan refused the four
default post/term slug collisions. Re-ran with explicit
`--adopt-by-slug=posts,terms`; apply reported create 5, update 2, adopt 4 and
applied 11 with a clean canary and passing canonical recapture.

Observed source-to-target rebinding:

| Surface | Source | Target |
| --- | ---: | ---: |
| SEO post | 5 | 18 |
| social attachment | 6 | 17 |
| primary category | 2 | 7 |

The target post used its own home URL and remapped IDs. The source 302 was
created beside the preserved target-only 307. Two target HTTP requests returned
the target destination and advanced only the target rule's runtime hit count.

After classifying registration skip as authored, the repository and target had
both changed since base. Apply refused the three-way option conflict; plan
showed the conflict and the explicit choices. `--force-theirs` was used only
after reviewing that repository intent, then a repeat plan showed 11 unchanged
entities and zero writes or blockers.

### 8. Attack compatibility and identity assumptions

**Platform user**

> Test an adjacent unsupported release and corrupt the target's natural key.

**Coding agent**

- Installed exact 1.0.276 on the target. Plan reported both
  `outside_version_range` for `[1.0.277,1.0.278)` and the site-signed adapter's
  `plugin_version_mismatch`. Restored exact 1.0.277; no compatibility was
  inferred from adjacency.
- Inserted a second target redirection with the same `sources` identity but a
  different destination. Capture already refused duplicate natural identity,
  but plan initially collapsed the two live rows behind one observed UUID and
  reported unchanged. The shared typed-table observation path now compares the
  canonical natural-key tuple before a plan indexes entities. The same live
  plan refuses: `natural identity matches local ids 2 and 6; full natural
  identity must be unique before capture, plan, or apply`.

## Result and remaining boundary

The recorded 1.0.277 outcome is green: exact certificate/pin, capture, deploy,
hostile-target apply, ID/URL rebinding, native redirect behavior, canonical
recapture and zero-write repeat plan all passed. The adapter remains a fixture,
not a shipped capability. Connected-account credentials, custom schema,
sitemap ID lists, Instant Indexing credentials, Pro/add-ons and versions other
than 1.0.277 remain unsupported. Derived internal-link projections also need a
bounded provider with a verified postcondition before this adapter should be
considered for the product library.
