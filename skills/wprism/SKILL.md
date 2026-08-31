---
name: wprism
description: Operate WPrism directly for WordPress onboarding, assessment, capture, preview, release, verification, reconciliation, and recovery through its public CLI and versioned machine contracts. Use for a WPrism-managed site or a WPrism refusal; do not use for Orbit workflows or internal wp wprism commands.
---

# WPrism

Operate the public, host-agnostic `wprism` orchestrator. Treat this skill as
agent-facing guidance only: it grants no target authority, signing authority,
credentials, or permission to mutate an environment.

## Bind to the installed surface

1. Locate the site repository root containing `site.wprism.json` and the CLI
   selected by the user or project. In a WPrism source checkout, use
   `cli/wprism`; otherwise use the installed `wprism` executable.
2. Run `<cli> --help` before relying on a command or format. The skill can be
   newer than the installed distribution. If a required verb, flag, or format
   is absent, stop and report the compatibility mismatch; do not substitute a
   legacy or internal command.
3. Inspect `git status`, `<cli> envs`, and the target environment named by the
   user. Do not silently choose an environment from a suggestive name such as
   `production` or `stage`.
4. Keep `.wprism-envs.json`, credentials, secret references, signing material,
   and private target-control records out of model output and commits.

## Route the request

- For a disposable introduction, connecting or adopting a site, assessing its
  boundary, or reviewing an application contract, read
  [onboarding and assessment](references/onboarding-and-assessment.md).
- For branch environments, capture, classification, refresh/rebase, planning,
  merge checks, previews, and rehearsal, read
  [change and preview](references/change-and-preview.md).
- For a production release, authorization subject, execution replay, status,
  or verification, read [release](references/release.md).
- For a failed or uncertain release, checkpoint inspection, rollback planning,
  or recovery, read [recovery](references/recovery.md).
- For plugin/theme updates, follow `docs/guides/code-updates.md` from the
  matching WPrism checkout. For missing or incomplete adapters, follow
  `docs/guides/adapter-authoring.md`. Those are specialist authoring workflows,
  not shortcuts around an assessment refusal.

## Hold the public-contract boundary

- Use public `wprism` commands for supported operations. Do not invoke raw
  `wp wprism` commands during an ordinary workflow; they are implementation
  details documented for WPrism maintainers.
- Prefer `--format=json` when a public command offers it. Validate the declared
  `format`, preserve the complete stdout bytes, and keep stderr as diagnostic
  evidence. A human table, prose, skill text, process exit, or inferred state
  is never mutation or release authority.
- Preserve canonical files byte-for-byte when a digest, presentation, subject,
  signature, receipt, or replay binds them. Never reconstruct signed input by
  copying fields into a new JSON document.
- A bounded or paginated view is a display projection. Use the command's
  authoritative complete document for decisions. If command output is
  truncated or exceeds the execution harness's capture limit, admit no
  evidence and choose a complete file/artifact capture path; do not invent a
  portable maximum.
- Record the CLI or source identity and every consumed document format when
  building automation. Broad agent or repository-spec versions alone do not
  prove identical behavior.

## Hold the mutation boundary

- Start with read-only observation. Before mutation, bind the exact
  environment, source revision/tree, target facts, accepted contract, and
  current plan or operation identity required by that command.
- An agent may present an exact subject to the responsible human or external
  authority. It must not approve its own proposal, invent a human review
  reason, mint an authorization, expose a signing key, or treat a user-facing
  confirmation as a cryptographic WPrism authorization.
- Treat `--with-deletes`, `--accept-weaker-recovery`, `--force-*`,
  `--confirm-prune`, `--create`, `--yes`, `--writers-excluded`, and similar
  flags as separate claims. Use one only when the user requested the underlying
  action and the required real-world condition has been established.
- Never assert `--writers-excluded` merely because no WPrism process is
  visible. It means an external maintenance window excludes every writer for
  the entire recovery window.
- On a nonzero exit, refusal envelope, unknown format, uncertain publication,
  or ambiguous consumption/mutation boundary, stop. Preserve the evidence and
  use the documented read-only status or reconciliation path. Never make an
  automatic second mutation attempt.
- `wprism demo` is a disposable local exercise, not a production release or
  recovery contract. Do not promote its receipts or prose into production
  evidence.

## Report the outcome

Name the environment, operation, bound source/target identity, input and output
formats, relevant digests, exit status, and observed terminal state. On a
refusal, retain its exact reason and remediation. Claim success only from the
command's valid terminal result plus the required verification; otherwise say
`refused`, `reconciliation required`, or `outcome unknown`.
