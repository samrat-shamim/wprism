# Host CLI command characterization matrix

This is the pre-extraction inventory for Thread 2. It records the command
grammar and the current observable boundary that the extracted root must retain.
The two foundation-quarantined commands remain executable contracts in
`foundation-safety.json`; this matrix adds the rest of the host vocabulary and
points at the focused regression coverage.

The matrix is intentionally based on the current `cli/duo` usage text and
existing regression targets. A row is not permission to introduce a new
command, option, refusal, or output envelope. Exact volatile values (run IDs,
temporary paths, timestamps, and target-specific identifiers) may be normalized
only in the corresponding golden harness; hashes, refusal codes, identifiers,
ordering, and stream ownership are not volatile.

## Shared contract

| Dimension | Current baseline |
| --- | --- |
| Entry point | `cli/duo` |
| Global options | `-h`, `--help`, `--envs-file=<path>` |
| Environment-bound transports | `local`, `docker`, and `ssh`, selected by the trusted environment registry |
| Offline commands | `envs`, `manifest-validate`, `adapter-draft`, `adapter list`, `adapter inspect`, `adapter doctor` |
| Human output | Existing terminal text and tables; stdout/stderr ownership is command-specific and must remain byte-compatible |
| JSON output | Only commands documenting `--format=json` emit JSON; the host and agent refusal envelopes remain versioned and unchanged |
| Success status | `0` when the command’s existing readiness/decision rules permit success |
| Failure status | Existing non-zero refusal, usage, transport, or agent status; the extracted root must preserve the exact code and stream bytes |
| Registry discovery | `site.duo.json` at the current Git worktree root plus an adjacent `.duo-envs.json` overlay; an explicit `--envs-file=<path>` is trusted only when supplied |
| Safety boundary | Foundation refusals happen after complete option parsing and before registry/provider/journal construction or target contact |

## Command rows

| Command | Arguments and options (current grammar) | Environment/repository requirement | Transport | Side effects and preconditions | Focused characterization |
| --- | --- | --- | --- | --- | --- |
| `duo envs` | none; global options only | no environment; reads registry sources | none | reads and validates the merged registry; no target contact | `regress-environment-list-command`, `cli_smoke.sh` |
| `duo env materialize <env>` | `--from=<production-env>`, `--branch=<ref>`, optional `--create`, `--ttl=<seconds>` | environment argument is parsed; foundation safety refusal occurs before registry/repository/provider construction | none on the quarantined path | current baseline refuses with `environment_materialization_containment_unproved`; no journal or target mutation on that path | `regress_environment_command.php`, `regress_environment_materializer.php`, `regress_environment_materializer_ssh.php`, `regress_environment_materializer_recovery.php`, `foundation-safety.json` |
| `duo env reap <env>` | no command-specific options | environment registry required | local/docker/ssh | provider-owned detach/destroy and idempotent reap; stale identity refuses | `regress_environment_materializer.php`, `regress_environment_materializer_ssh.php`, `regress_environment_materializer_recovery.php` |
| `duo manifest-validate <manifests-dir>` | optional `--manifest=<name>[,...]`, `--pins=<name>[,...]` or `--all`, `--site=<site-repo>`, `--no-code`, `--format=json`; alternate `--emit-schema` | no environment; manifests directory required | none | WordPress-free validation; no writes; `--no-code` reports the skipped/deferred code checks | `regress-manifest-validate`, `regress-manifest-grammar`, `regress-manifest-dispositions` |
| `duo adapter-draft <site-repo>` | required `--name=<n>`; optional `--match=<regex>`, `--evidence=<live-evidence.json>`, `--format=json`, `--check-proposals` | site repository required; no environment | none | offline proposal generation; never applies, promotes, or emits PHP | `regress-adapter-draft`, `regress_adapter_catalog.php` |
| `duo adapter list` | optional `--repo=<site-repo>`, `--format=json` | no environment; optional repository | none | reads shipped and repository adapter sources; reports deferred live checks | `regress-adapter-catalog`, `regress_adapter_catalog.php`, `regress_adapter_registry.php` |
| `duo adapter inspect <name>` | optional `--repo=<site-repo>`, `--format=json` | no environment; optional repository | none | reports one adapter’s declaration, disposition, providers, and evidence facts | `regress-adapter-catalog`, `regress_adapter_catalog.php` |
| `duo adapter doctor` | optional `--repo=<site-repo>`, `--format=json` | no environment; optional repository | none | reports load blockers as rows; does not turn precedence/shadowing into a fatal exit by itself | `regress-adapter-catalog`, `regress_adapter_catalog.php` |
| `duo adapter-observe <env>` | optional `--out=<local-file>` or `--format=json` | environment registry required; target `repo_path` is authoritative | local/docker/ssh | one target observation; `--out` is atomic and must not overwrite; output is non-authorizing evidence | `regress-adapter-observation`, `regress_adapter_authoring_live.sh` |
| `duo doctor <env>` | no command-specific options | environment registry required | local/docker/ssh | reachability, WordPress, agent, repository, and `site.duo.json` checks; no mutation | `regress-doctor-command`, `regress_doctor_env_values.php` |
| `duo driver-capabilities <env>` | optional `--operation=<workflow>`, `--format=json` | environment registry required | local/docker/ssh | closed capability negotiation; unsupported requirements refuse and are never emulated | `regress-driver-capabilities-command`, `regress_environment_driver.php` |
| `duo adopt <env>` | no command-specific options | environment registry required; checkout and target topology are validated | local/docker/ssh | verified bootstrap/install with atomic rollback; target paths and version proof are retained | `regress-adopt-command`, `regress_adopt_rollback.php`, `regress_ssh_adopt.sh` |
| `duo init <env>` | optional `--yes` | environment registry required; repository is created only after confirmation and safety gates | local/docker/ssh | proposal/read-only discovery first; confirmed path writes content-addressed baselines and proves clean scope | `regress-init-command`, `regress-init-contract`, `regress_local_bootstrap_live.sh` |
| `duo status <env>` | optional `--category=<ids>`, `--action=<buckets>`, `--entity=<kinds>`, `--limit=<1..200>` | environment and target repository required | local/docker/ssh | strict plan observation; reports readiness/conflicts and returns non-zero when existing safety rules say not clean | `regress-status-command`, `regress_plan_view.php`, `regress_plan_contract_trust.php` |
| `duo capabilities <env>` | optional `--operation=<op>`, `--surface=<surface>`, `--revision=<sha>`, `--format=json` | environment registry and target revision required | local/docker/ssh | reports evidence-bound target capability/readiness; no mutation | `regress-capability-registry`, `regress_provider_contract.php` |
| `duo capture <env>` | optional `--scope-contract=<local-path>` plus extra agent flags | environment and target repository required | local/docker/ssh | target capture with existing safety quarantine and scope-contract validation | `regress-capture-command`, `regress_capture_publish.php`, `regress-capture-safety-gates`, `regress-cli-json-refusals` |
| `duo lint <env>` | extra agent flags | environment and target repository required | local/docker/ssh | read-only target lint; streams agent output and preserves its exit code | `regress-cli-json-refusals`, `regress-command-output` |
| `duo plan <env>` | optional `--scope-contract=<local-path>` plus extra agent flags | environment and target repository required | local/docker/ssh | strict observation and planning only; no repair/write | `regress-plan-view`, `regress-plan-contract-trust`, `regress-plan-explain` |
| `duo explain <env> <bucket>:<entity-key>` | optional `--format=json` plus plan flags | environment and target repository required | local/docker/ssh | value-free explanation of one plan row; no mutation | `regress-plan-explain`, `regress-plan-view` |
| `duo apply <env>` | optional `--scope-contract=<local-path>` plus extra agent flags | environment and target repository required | local/docker/ssh | existing Apply/session/lease/recovery rules; no host-side decision recomputation | `regress-apply-planner`, `regress-scoped-apply-session`, `regress-scoped-apply-recovery`, `regress-cli-json-refusals` |
| `duo deploy <env>` | optional `--force-code-mismatch`, `--force-code-drift` | environment and target repository required | local/docker/ssh | lifecycle/code staging and activation under the existing target lease | `regress-code-release`, `regress-lifecycle-executor`, `regress-cli-json-refusals` |
| `duo env-set <env>` | required `--name=<name>` and exactly one of `--value=<value>` or `--stdin` | environment and target repository required | local/docker/ssh | writes one policy-declared environment value; stdin masking/logging rules remain unchanged | `regress-env-options-policy`, `regress-cli-json-refusals`, `regress-status-command` |
| `duo promote <env>` | optional `--scope-contract=<local-path>`, `--with-deletes`, `--format=json` | environment and target repository required; recovery decision binding is a precondition | local/docker/ssh | existing plan/checkpoint/lease/lifecycle/apply/recovery phases and receipts | `regress-promotion`, `regress-promotion-lock`, `regress-promotion-unit`, `regress-rollback-authority`, `regress-cli-json-refusals`, `foundation-safety.json` |
| `duo pending <env>` | no command-specific options; agent JSON precedent is selected by `--format=json` | environment and target repository required | local/docker/ssh | read-only review queue; empty queue succeeds with the existing message | `regress-pending-command`, `regress-cli-json-refusals` |
| `duo classify <env>` | optional `--accept-proposals`, `--export-batch=<path>`, `--apply-batch=<path>` | environment and target repository required | local/docker/ssh | interactive or batch-bound review decisions; stale/partial batches refuse before policy write | `regress-classify-command`, `regress-cli-json-refusals` |
| `duo coverage <env>` | optional `--format=json` | environment and target repository required | local/docker/ssh | additive visibility only; never a readiness or mutation gate | `regress-coverage-offline`, `regress-cli-json-refusals` |
| `duo scope <env>` | required `--roots=<selectors>`; optional `--contract`, `--format=json` | environment and target repository required | local/docker/ssh | bounded read-only scope resolution; unresolved roots refuse and nothing is captured/promoted/deleted | `regress-scope-command`, `regress-scope-contract`, `regress-scope-wire`, `regress-scope-closure` |
| `duo refresh <production-env>` | required `--production-ref=<ref>`; optional `--scope-contract=<local-path>`, `--field-diff`, `--format=json` | production environment and local repository/worktree required | local/docker/ssh for observation; local Git for planning | read-only production export plus immutable local semantic plan; cancellation and field-diff bytes are stable | `regress-refresh-command`, `regress-refresh-orchestration`, `regress-refresh-compile-refs`, `regress-refresh-field-diff`, `regress-refresh-export-unit` |
| `duo rebase <production-env>` | required `--production-ref=<ref>`, `--new-branch=<name>`; optional `--scope-contract=<local-path>`, `--field-resolution=<local-path>` or `--interactive`; alternate `--abort=<run-id>` | production environment and clean local worktree required; abort requires a retained run journal | local/docker/ssh for observation; local Git/worktree for candidate | disposable candidate worktree, atomic branch creation, or narrowly scoped abort cleanup; source branch remains untouched | `regress-rebase-command`, `regress-refresh-rebase`, `regress-refresh-field-diff`, `regress-refresh-export-unit` |
| `duo -h` / `duo --help` | no command-specific options | none | none | prints the current usage text and returns success | `cli_smoke.sh`, `check_guide_commands.sh` |

## Migration comparison requirements

For every row above, the legacy and extracted roots must be invocable through a
test-only selector during migration. The comparison harness must record and
compare:

1. process exit status;
2. stdout bytes;
3. stderr bytes;
4. parsed JSON only as an additional diagnostic, never as a substitute for the
   byte comparison; and
5. side-effect/refusal assertions for the command’s declared boundary.

The extracted root may share result objects between human and JSON presenters,
but presenters cannot calculate a new readiness or authorization decision. A
legacy fallback may exist only in the test selector while the compatibility
fixtures are being migrated; no production option may select it.
