# Managed-clone identity boundary

Duplicate Page was evaluated as the next adapter on 2026-09-09. It is not a
shipped adapter and does not increase the production-ready count. The native
investigation below establishes a Capture refusal, not adapter conformance,
successful source-to-target cloning, or a general plugin security audit.

## Exact source and native sequence

Engine source: `791762c60db2989db7b28d940e92424b506bcfdd`.
The official [Duplicate Page download](https://downloads.wordpress.org/plugin/duplicate-page.zip)
contained plugin header/readme version **4.5.9**, basename
`duplicate-page/duplicatepage.php`, SHA-256
`021efd37f011dbee266de4007fa12b4747f0aaa340049179cbad13518ac3e2d1`.
The version-specific 4.5.9, 4.5.8 and 4.5.6 download URLs returned 404;
the directory's advertised version is not proof of a retrievable versioned
archive. The official API advertised 4.5, whose downloaded ZIP had SHA-256
`fe9f4e60683b46eb409b9f782cbdee29e22c345430aafc898f78a1a00d88265c`.
That older artifact was inspected, not executed or certified. No supported
version range follows from these observations.

One disposable MariaDB pair, `dpcopy01` on ports 9550/9551, mounted the exact
clean source above. The native source site ran WordPress 7.1 / PHP 8.3.33,
the ordinary Twenty Twenty-One theme and only Duplicate Page as an active
plugin. WP debug/logging remained enabled with display disabled. The fixture
disabled new WP-Cron spawning; it did not remove any database rows from the
observation.

1. Create an ordinary published post through the WordPress API. Native
   `wp wprism init` proposal/confirmation creates its canonical baseline,
   embedded UUID and ledger mapping. The managed original is post **4**.
2. Install the hash-verified plugin. Log in as the owned administrator through
   WordPress. Save the actual Duplicate Page settings form: Gutenberg,
   draft, list redirect and the suffix `Managed copy 東京`. The retained
   native readback and journal accompany the actual nonce-bearing POST.
3. In the native Posts screen, hover the original row and click its
   **Duplicate This** link. A 302 leads back to the list. The plugin creates
   draft post **6**, with the expected suffixed title and the **same**
   `_wprism_uuid` as post 4.
4. Run ordinary `wp wprism capture --repo=/siterepo --format=json`. It exits
   **1**, reason `capture_failed`, with `details_redacted: true`. A separately
   retained repeat invocation establishes a fresh one-record private cause:
   `Identity::assert_embedded_unique()` names both post owners and refuses
   to choose or replace an identity.
5. Across the refused Capture attempts, complete unfiltered SQL for **16
   tables, 1,326,807 bytes**, and all **seven canonical state files** remain
   byte-identical. The SQL includes the original map/state rows and both
   native posts; the clone itself is not rolled back or deleted by WPrism.

The browser observation retains admin navigation/non-GET request metadata,
selected request bodies (including nonce-bearing settings POST data), and
complete selected response headers, **not response bodies**. It includes
the native settings Save, clone request and background AJAX, rather than
claiming that a successful redirect alone proves a complete copy. The observed
clone window has no diagnostic response headers, browser console errors,
page errors or failed requests; native debug output is absent. This is one
plain-post scenario, not coverage of malformed requests, all metadata shapes,
roles, attachments, custom post types, or page-builder combinations.
The UI sequence is contextual evidence from the private browser records;
the host admission independently checks native state and Capture refusal,
not browser-to-database causality or pair/source binding of those browser events.

## Retained evidence and failed attempts

Private host-only records remain in the owning worktree's `sandbox/tmp/`:

- `dp-native-recon.e9Agrl/`: complete native commands, SQL and state trees;
  `producer-inputs.tar` retains the executed scratch producers (SHA-256
  `4d2dfaa63cd91960d5f5e4dbb857d62050b88fc11cf808ee21a8cc2610ef56cf`).
- `dp-browser.fn5pmi/`: isolated browser commands/snapshots and settings/clone
  observations. `clone-v2-evidence` is the successful native clone window.
- `wprism-conformance-capture.dpcopy01.35x558/`: shared private-command
  snapshot → command → fresh-delta transport. Its diagnostic alone is
  explicitly unverified; `dp-admit-recon.php` checks the exact complete
  private graph with `PrivateRefusalReceipt::verifyDiagnostic()`.
- `dp-admit-v3.{stdout,stderr,exit}`: post-teardown admission, exit 0,
  empty stderr, exact cause plus full SQL/state equality at all three
  `copied-v2`, `duplicate-refused-v2`, and `final-refused` boundaries.
  The original v2 admission skipped the intermediate snapshot; both runs and
  their exact verifier sources remain retained. `admission-v3-source.tar`
  in the native sink pins the revised verifier (SHA-256
  `475ad601f5cae07e0cc6693e2372481a7f8c29ca933a6841d49c19e224ca4e5c`).
  The complete SQL
  digest is `3b685480282cde4d16ee5b31563a0a5b79036d7026e027fb8dc6ef66420402d2`.

These are local diagnostic archives, not shipped records, certificates or
portable reproduction tooling. The first native attempt lacked Git in its
CLI image and stopped at the initialization proposal; its records remain in
`dp-native-recon.axiVyJ/`. The second used the documented Git-enabled CLI
image. An initial browser click could not reach the hidden row action and
made no clone; the subsequent Capture refused a **different** issue—the
newly installed plugin was absent from the earlier code baseline. Both
failures remain recorded. Hovering the native row exposed the action; the
later two-owner identity refusal is independently verified. The first host
admission ran before the final snapshot finished and refused an incomplete
stream; its failed output is retained separately from the completed admission.

After evidence collection, native logout returned to WordPress's login screen,
the owned browser closed, and the shared ownership helper destroyed the pair,
its two disposable databases/site roots, and its lease. The retained admission
is usable after that teardown. No other owner's pair or source checkout was
changed.

## Architectural conclusion and open work

`duplicatepage.php:206-230` enumerates all source post metadata and writes it
to the new post. The plugin does not distinguish WPrism's reserved identity
from ordinary content. Declarative option/meta classification cannot make a
later native clone acquire a new identity safely. A provider is also the
wrong lifecycle: it must not infer a new object's ownership after corruption.
The existing engine refusal is correct; no plugin-name branch, vendor patch,
metadata blacklist workaround or silent identity repair was added.

A future explicit **identity-fork** operation could belong in the generic
Repository/Identity and CLI layers. The operator would select the new object;
the engine would prove the original's mapping, the selected object's absence
of durable history, exact two-owner metadata, and transaction/CAS authority.
It must preserve original map/state/canonical history, and ordinary Capture
must remain the operation that establishes the new object's published state.
Commit uncertainty requires a closed, durable receipt/status protocol before
such a mutation is safe. This is an open product boundary, **not an implemented
repair command** or evidence that embedded identity must be abandoned.

Independent Luna/Terra source review also found native behaviors requiring
separate evidence: same-key metadata collapse, ignored native write failures,
already-deserialized object metadata, capability/request edge cases and
conditional Elementor/Breakdance/Events Calendar effects. Those are not
generic engine machinery. A four-setting-only capsule would leave the
plugin's main managed-cloning workflow unresolved and must not be counted as
production-ready adapter coverage.

The same audit found a second consumer: exact Yoast Duplicate Post 4.7 also
copies the reserved key, and its certified fixture clones only before first
Capture. Its [capsule gap record](../../adapter-packages/yoast-duplicate-post/evidence/managed-clone-gap.md)
therefore withdraws production authorization. The genuine ready count drops
by one; a catalog target is not grounds to retain an overbroad certification.
