# Try WPrism on disposable sites

This demo moves one page between two local WordPress sites and verifies that
a comment created only on the target survives. It creates disposable Docker
resources and Git repositories; no existing WordPress site is connected.

## Prerequisites

Use a full Git clone of WPrism, PHP 8.3, Docker with Compose and a running
daemon, Git, and `jq`. Keep the source checkout clean. The first run requires
network access to download pinned images and can take longer than later runs.
Docker supplies WordPress and its database; Composer is not required.

```sh
git clone https://github.com/duotronic-ai/wprism
cd wprism
cli/wprism --help
cli/wprism demo start
```

Use a Git checkout rather than GitHub's source ZIP: the sandbox verifies the
candidate revision. After releases are published, select the desired release
tag in that checkout before starting the demo.

## Review, edit, and compare

Setup prints the source and target URLs and the local demo login, then stops
at `review_required`. Inspect the printed page-only contract proposal.
Accepting it grants only the demo's declared page scope:

```sh
cli/wprism demo review --accept-page-only
```

Open the **source** wp-admin URL. Find **WPrism Demo Page**, edit its title or
body, and save it. Keep the example to that page.

```sh
cli/wprism demo capture
git -C sandbox/siterepo/wprismdemo1 diff
```

The diff should show the page change. WPrism represents the authored state as
reviewable files; a complete database replacement is not part of this journey.

## Apply and check the boundary

```sh
cli/wprism demo apply
cli/wprism demo refusal
cli/wprism demo status
```

Apply first runs the real read-only release authorization preview. It then uses
a bounded evaluation apply and checks the target page against the captured
artifact and the target-only comment against its prior bytes. Inspect the
target page in the browser too.

The refusal step deliberately supplies an untrusted target binding and checks
that it is rejected. A successful refusal demonstration means the attempted
operation was blocked.

This demo does not authorize production release. The production sequence uses
source staging, release preparation, external signed authorization, execution,
and verification; see [release.md](release.md).

## Clean up

```sh
cli/wprism demo stop
```

Stop removes the demo's sites and repositories. Copy anything you want to keep
before running it. If a phase fails, preserve its error and run `cli/wprism
demo status`, then use the same stop command for cleanup.

## Common setup problems

| Symptom | Next step |
|---|---|
| Docker is unavailable | Start Docker and check `docker info` and `docker compose version`. |
| An HTTP port is in use | Choose a named demo with alternate ports using the example below. |
| The candidate source is dirty | Use a separate clean checkout of the candidate revision. Preserve your edits. |
| A pinned image download fails | Check network and registry access, then retry the same revision. Do not substitute an arbitrary image. |
| Assessment refuses a surface | Keep the refusal and exact revision for a bug report. Do not bypass it to reach apply. |

For alternate ports:

```sh
cli/wprism demo start --name=mydemo --source-port=8791 --target-port=8792
cli/wprism demo review --name=mydemo --accept-page-only
cli/wprism demo status --name=mydemo
cli/wprism demo stop --name=mydemo
```

Use the same `--name=mydemo` on every command in that session. The corresponding
source repository is `sandbox/siterepo/mydemo1`.

An advanced catalog/order scenario is available through `cli/wprism demo start
--scenario=woocommerce`; it has its own plugin-specific eligibility and
assessment boundaries. Start with the page demo.

Next: [site eligibility](site-eligibility.md), then the
[existing-site quickstart](quickstart.md).
