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
