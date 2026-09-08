# Native QR destination reconnaissance — not a readiness gate

WPForms Lite remains experimental/unready with all twelve readiness families
open. This is a source-discovery record and offline body-codec checkpoint,
not admitted browser/Capture/Apply or complete physical-preservation evidence.

## Retained source observations

The exploratory runtime used clean source
`ff26c680e462ee4953b41ccae4139e2b1e1f9ffd`, locked Lite **2.0.1.1** (SHA-256
`6245074790df01a6e24a42587e024132b4a28fac499d1a8fa12ebf5580e4852b`), and
owned MariaDB pair `wpfqr01`, HTTP ports 9544/9545. Playwright CLI **0.1.19**
drove the real source browser. Its setup UI left Lite Connect unchecked and
skipped the external guided setup; no cloud enrollment or entry submission was
performed. The optional Builder challenge was subsequently canceled through
its UI. A native Blank Form plus a Single Line Text field established form
**6**, with independently created destination pages A **4** and B **5**.

The observed page picker offered both named pages; its `data-pages` map bound
their source permalinks. Only the source was browser-authored. Target page IDs
11/12 were observed through native CLI consumers, not target Builder or Apply.

| Saved state | Page ID | Custom URL | Last-generated URL |
| --- | --- | --- | --- |
| Baseline None | 0 | empty | empty |
| Stale Page B | 5 | empty | Page A |
| Stale Custom URL | 5 (inactive) | Page B with `?label=701` | Page A |
| Generated Custom URL | 5 (inactive) | Page B with `?label=701` | same custom URL |
| None | 0 | empty | empty |

The selected page and generated URL are independent values. Generate produced
Page A's snapshot; selecting Page B without regeneration retained it. The
normal Save and a fresh Builder reload retained that stale state and restored
the Regenerate control after asynchronous rendering finished. None's server
save clears both IDs and both URLs; its **complete 3,397-byte body** equals
the first Builder-saved baseline. The browser's still-mounted inactive controls
are not substituted for a fresh native storage read.

All five complete `wp_posts.post_content` values are retained in
`fixtures/native-builder-qr-authoring.json`, with individual original dump and
body hashes. They preserve the whole field, nested theme JSON, notifications,
confirmations, provider controls and local ID types. `regress_native_bodies.php`
replays these through the shipped policy, PostCapture and body codec with
divergent IDs, checking complete canonical recapture. Its **155 assertions**
are offline mechanism evidence, not whole Apply or browser admission.

## Artifact observation and review

The native Page A PNG download is **1024×1024**, SHA-256
`e4b39822e0c8bb59f83de13d87fecb6caafe451112ede7f3718dfd9a814f1ef9`.
The Luna reviewer independently decoded exactly one QR symbol using Apple
Vision `VNDetectBarcodesRequest`, obtaining
`http://localhost:9544/wprism-qr-page-a/`. This confirms that artifact only.
The generated Custom URL also produced an SVG download; its encoded payload
was not independently decoded. Clipboard copy, submission/mail, target
rendering, managed deployment, Apply/retry and compiler-tree convergence are
not established by this run.

## Physical observations and limitations

The retained SQL archive contains **18** complete native exports, each with a
separate **26-table** roster and fresh native form consumers. All **66** native
collection/setup commands exited zero and all 18 consumer diagnostic records
contain no server-log bytes. These counts do not establish quiescence or admit
the complete physical transition.

The inspected stale-page save changed form 6's body/modification timestamps,
inserted revision 9, refreshed the existing Action Scheduler async-runner lock,
and appended five shared journal rows. Its posts `AUTO_INCREMENT` advanced
9→10 and journal counter 182→187. No broad row/table exclusion follows from
those observations. Terra traced the lock acquisition to an admin shutdown
hook that runs independently of WP-Cron; the required phase-local oracle and
no-due-work premise are in the [follow-up design](native-qr-destination-design.md).

The configured HAR file was **not written after browser close**. Request
listings and one header-only request export cannot prove the complete native
Builder POST/response contract. The optional onboarding tour emitted two
`WPFormsBuilder.validateEmailSmartTags()` deprecation warnings; logout emitted
a `Transition was skipped` page error. These diagnostics remain in the
archive. The browser producer, explicit exchange capture, strict full-row
admission, hostile controls and cross-site product run still need implementation
and fresh evidence. This run must not be relabeled as a green E2E gate.

## Archive and cleanup

In the retained QR worktree, native records are under
`sandbox/tmp/wpforms-qr-recon.zs12D6/`, browser observations/downloads under
`sandbox/tmp/qr-browser.ki4Dgc/output/playwright/`, and outer ownership records
in `sandbox/tmp/qr-recon-v1.{stdout,stderr,exit}`. Private full dumps and browser
credentials/nonces are not checked in. Scratch exploration scripts are not a
repeatable capsule producer.

Ordinary browser logout removed the sole owned administrator session row
(one token before, none after). The named browser was closed, and the shared
ownership helper destroyed the pair, both databases, exact site/origin roots,
scratch and lease before the reconnaissance owner exited **0**. Its terminal
message explicitly reports retirement, not an E2E PASS. No shipped package or
platform/engine bytes changed, and WPForms adapter identity remains unchanged.
