# Progressive adoption — the ten-situation grind

Driver: [`sandbox/tests/grind_adoption.sh`](../../sandbox/tests/grind_adoption.sh).
Substrate: [`sandbox/tests/lib/grind_lib.sh`](../../sandbox/tests/lib/grind_lib.sh)
(shared with the adapter walk). Plan: [round-3 T7](../proposals/round-3-adoption-situations.md).

The adapter walk proves that an operator can author and override adapters. This
grind proves the thing every real user does before that: **adopting Duo
progressively on a site that already exists** — a brochure site, a shop with
orders, a multilingual shop, a builder site — starting with the smallest thing
Duo can do (`duo doctor`, a read-only `duo assess` on the adoption seed) and
adding management one decision at a time, never a big-bang init. Every
situation ends with the full loop where the site allows it (contract →
rehearse → edit → capture → merge → release → verify → recover) or with the
typed refusal that says why not.

Ten situations, each on a fresh `pair.sh reset` of one dedicated pair:

| # | situation | what it proves / where it stops |
|---|---|---|
| A1 | brochure site: classic theme, core only, pages + menu + widget | the smallest honest loop; every readiness `Ready / Platform-certified` |
| A2 | block theme (FSE): a customised template part | the FSE profile end to end; site-editor customisations round-trip |
| A3 | shop with live orders | orders `runtime / preserve local`; an order placed on the target after the release checkpoint survives the release and is lost only on the explicit recover — the printed boundary, literally |
| A4 | shop + Yoast + CF7, all certified | three adapters composed through one contract and one release |
| A5 | multilingual shop (WooCommerce + Polylang) | translations travel; the non-public `polylang_mo` type is named, never silent |
| A6 | builder site (Elementor + ACF + block theme) | `elementor_library` opted in as an operator would; design edits round-trip |
| A7 | WPForms Lite on A4's shop: kept unmanaged, then adopted with a site adapter AFTER init | the late-adapter path: certify → pin → capture, no second init |
| A8 | acme-catalog: bundled adapter promoted and certified, then plugin AND adapter change in one code release | code and state evolve together; the re-certified digest is the operator's explicit act |
| A9 | version edges: WooCommerce and Yoast below their windows, upgraded mid-way | refused by name; the loop completes once inside the windows |
| A10 | edge cases on one site: second init, secret-shaped option, plugin deactivated after adoption, theme switch, override installed then removed | each stops or proceeds by its documented rule; the loop still completes |

## Running it

```sh
bash sandbox/tests/grind_adoption.sh --self-check      # helpers vs fixtures, no docker
bash sandbox/tests/grind_adoption.sh --dry-run         # every argv, nothing executed
DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) bash sandbox/tests/grind_adoption.sh
ADOPT_SITUATIONS=A1,A3 ADOPT_KEEP=1 … bash sandbox/tests/grind_adoption.sh
```

Knobs: `ADOPT_PAIR` (default `adopt`), `ADOPT_PORT1/2` (9600/9601),
`ADOPT_SITUATIONS`, `ADOPT_KEEP=1`, `ADOPT_KEY_ID`, and the pinned versions
`ADOPT_WOO_VERSION`, `ADOPT_WOO_OLD_VERSION`, `ADOPT_YOAST_VERSION`,
`ADOPT_YOAST_OLD_VERSION`, `ADOPT_CF7_VERSION`, `ADOPT_POLYLANG_VERSION`,
`ADOPT_ELEMENTOR_VERSION`, `ADOPT_ACF_VERSION`, `ADOPT_WPFORMS_VERSION` (all
must be pins in `sandbox/conformance/artifacts.lock.json`). From a linked
worktree add `DUO_SOURCE_ROOT=$(pwd -P)`. Evidence lands under
`sandbox/tmp/grind-adoption.*/evidence/<situation>/`; the exact PASS string is
`✔ GRIND_ADOPTION PASSED (<situations>)`.

## Rounds

Round 1 builds and runs A1–A5, fixing product gaps as they are found; round 2
adds A6–A10; round 3 reruns all ten on the final certified tip as the
acceptance. Each product gap a situation finds is fixed at the root with
offline regression coverage, then re-certified (core + the pinned subjects)
before the next live run.

## Findings log

Kept as the grind runs; each entry names the situation, the stop, and the fix.

- A1 (first look): `duo doctor` before a site repository exists reports a
  `[FAIL] repo path has site.duo.json` row — honest, so the grind seeds the
  adoption repository first, then runs doctor and assess.
- A1 (first look): `duo assess` on an adoption seed refused
  `adapter_observation_pending_unreadable` on every fresh site — the strict
  pending observer read the journal table nothing had created yet. Fixed:
  `Pending::journal_installed()` proves presence with a clean `SHOW TABLES`;
  an absent journal is a known state and the queue is the live gate walk's
  findings (regress_adapter_observation).
- A1 (release): the lib's verify assertion required two declared journeys
  (the walk's shop pair); a brochure site declares one — relaxed to at least
  one.
- A2 (init): a block theme's site-editor customisations live in core's
  non-public `_builtin` FSE types, which the scope gate never names; init now
  proposes the certified core FSE profile's scope for a block theme and says
  so (`[fse_profile_scope_selected]`), or names the gap
  (`fse_profile_not_certified`).
- A6 (anticipated from A2/S1): every plugin-registered rowful type outside
  the proposed scope that no selected adapter declares is left local (runtime)
  and printed — for adapter-owned plugins too (Elementor's `elementor_library`
  is a type the adapter deliberately leaves to the site).
- A3 (first look): on an adoption seed the seed's pin set is `core` alone, so
  the first `duo assess` read `plugin:woocommerce — install adapter` and every
  WooCommerce table as unclassified on a shop the library certifies. The
  assessment now projects a seed against the policy `duo init` would propose
  and says so (`adoption:` line; `authority.adoption` in JSON).
- All grinds: `tools/reference-env-provider.php` (reusable preview slot,
  merged during T6) requires every configured environment to use pair.sh's
  canonical logical name — the `preview` alias for side 2 is gone from
  `grind_mup.sh`, the walk and this grind; side 2 is `<pair>2` for rehearsal
  and release alike.
- A3 (loop): a product deleted from the target makes the next capture refuse
  the deletion intent — `manifests/woocommerce.json` keeps product deletion
  fail-closed by design — so the situation authors its catalog change on the
  source instead of preview-then-delete.
- A4 (first look, host side): `duo assess`'s per-operation `wp duo
  capabilities` reads answered the seed's core-only pin set while the
  inventory had been projected against the init proposal, so the catalog
  joined preview surfaces to missing claims (`missing_registry_entry`). The
  assess composition now passes `--adoption-preview`, and the agent answers
  those reads against the same policy.
- A4 (init): Yoast SEO ships a JOSE bundle whose format check carries the
  bare string `-----BEGIN PRIVATE KEY-----` and an OIDC software statement
  (a complete, public JWT) as a PHP constant; init refused
  `credential_bearing_code_file` twice on every Yoast site. A private key is
  now the PEM marker followed by key material, and a JWT inside shipped code
  is an advisory (`jwt_in_code_file`, named and redacted), never a blocker;
  private keys, cloud/API tokens and environment-owned config files stay
  blocking.

