# Environment-bound values

Provision a target-specific intended value with `wprism env-set <env> --name=<name> --stdin`. Keep these values outside Git; this reference explains the bindings and target-local authority.

## Env-bound value provisioning

issue #3232. A manifest can classify an option `class: "env"` — a value that
is genuinely per-environment (a payment gateway API key, `siteurl`, an
`admin_email`) and must therefore **never** be captured or applied like an
ordinary authored value; doing so would let one environment's value
silently overwrite another's the next time someone runs `wprism apply`. Every
`class: "env"` rule also carries a mandatory boolean `required`
(`Policy::validate_env_options()` refuses to load a manifest that omits
it): `true` means an operator must hand-provision this value on every
fresh environment (a genuine secret or site-identity value with no sane
default); `false` means it's plugin-internal bookkeeping that
self-populates the first time its owning plugin runs (a version marker, a
one-shot install-state flag) and is not worth checklisting — see any
shipped manifest's own `options` section for real examples of both.

Env values are never captured into branchable state. `env-set` instead records
the intended value in this target checkout's owner-readable
`.wprism-env-values.json`, so planning can distinguish correct configuration from
an arbitrary non-empty value:

- **`wprism plan` / `wprism status`** surface an `env_missing` bucket: every
  declared `class: "env"` option whose live value is absent or empty. A
  required option also appears when it has no target-local intended binding
  or differs from that binding. Each row carries its `required` flag. A
  *required* miss makes `wprism status` exit non-zero ("not safe to
  promote"); an *optional* miss is listed for visibility only. This is a
  per-environment equality check; raw secret values never enter the plan.
- **`wprism env-set <env> --name=<name> --stdin`**
  provisions one value directly, bypassing capture/apply entirely
  (`Apply::set_env_option()`). It refuses any `--name` the loaded policy
  didn't declare `class: "env"`, refuses an option that declares
  `sub_keys` (a structured, plugin-managed blob — Yoast's `wpseo`,
  Polylang's `polylang` — that a bare string write would corrupt; every
  such option shipped today is `required: false` for exactly this
  reason), and refuses an empty value (which `env_missing` would
  immediately re-flag as still-missing). The terminal LF and its optional CR
  are stdin framing; every other leading/trailing space or tab remains a value
  byte, so a whitespace-only line is non-empty. It atomically publishes the intended
  value with mode `0600` before writing WordPress; a stopped or failed write
  therefore leaves visible drift, never a false-green unbound value.
  Interactive host `--stdin` masks the
  local terminal for one host-side read, restores it, then starts the target
  with detached piped stdin and sends only that line; direct target use masks at the
  agent, and piped input has no terminal echo. The host restores echo before normal,
  exceptional, HUP, INT, QUIT, TERM, and TSTP exits/suspension, re-masks after
  resume, and refuses interactive use without the required signal support.
  After handoff, termination is deferred while the target has a chance to
  report its real outcome. The host bounds the complete detached handoff and
  outcome wait at five minutes; expiry returns temporary-failure exit `75`
  and states that the remote write may still complete. Retry the identical
  stdin value: publishing intent before the WordPress write makes `env-set`
  idempotent, and plan stays red until the live value matches. Ctrl-Z suspends
  the local wrapper and foreground child/transport together, but a non-PTY
  Docker/SSH target may continue; `fg` resumes the local outcome wait.
  The pipe handoff itself is nonblocking so local signal handling remains live
  while a slow target applies backpressure.
  The value is never printed or logged
  (not `--prompt` — see the passthrough section above for why that name
  was unavailable); its own "value for '&lt;name&gt;': " prompt writes to
  STDERR, never STDOUT, so `--stdin --format=json` is still safe to pipe
  into a JSON parser. `--value` is refused because flags land in shell
  history and process listings; scripts also pipe one newline-terminated
  value through `--stdin`.

### `.wprism-env-values.json` (target-local intended-value authority)

`wp wprism env-set` records what each required environment option is intended to
contain without putting secret bytes in branchable state. It publishes a flat
`{"option_name": "value", ...}` `.wprism-env-values.json` living next to
`site.wprism.json` inside **one environment's own checkout** (not on the
orchestrator host — contrast `.wprism-envs.json` above, which is
machine-local to wherever you *run* `wprism` from and covers every
environment at once; this file, if it exists, lives on the target itself
and covers only that one environment). The file is canonical JSON, is written
atomically with mode `0600`, and must be a regular non-symlink file with no
group or world access. It ships in
`sandbox/site-repo.gitignore.template` (every managed site repo's own
`.gitignore`) and is checked by `wprism doctor <env>`'s git-tracked hygiene
check (a tracked secrets file is a blocking failure, not an advisory
one). That check runs *inside* the target environment, so it needs a
`git` binary there to inspect tracked status with — most environments
materializing ordinary `wp wprism` commands have no structural reason to carry
one, while the `wprism init` workflow explicitly requires it. This project's
base sandbox images verifiably omit Git, so outside init the check degrades to an honest
advisory "could not verify" in that case rather than a false-clean PASS
— see `cli/src/Onboarding/Doctor.php`.

Plan reads this file and requires exact equality for every `required: true`
option. A live value that is absent, has no binding, or differs from the
binding remains in `env_missing`; a wrong, stale, or cross-client credential
therefore cannot be green merely because it is non-empty. Optional options
remain presence-only because the shipped `sub_keys` options are plugin-owned,
self-populated structures that scalar `env-set` deliberately refuses.

`env-set` publishes the intended binding before mutating WordPress. If the
process stops between those operations or WordPress rejects the write, plan
shows the mismatch until the operator retries; it never accepts a live value
whose intent was not recorded. `agent/src/Kernel/Secrets.php` does not scan
this file: its job is to stop secret-shaped bytes from entering captured
state, while this file is explicitly outside capture and protected by file
permissions plus the git-tracked hygiene gate.

### Provisionable bindings

`env-set` accepts declared flat env options and canonical post-password or
typed-column input-file bindings. It does not provision arbitrary post/term
meta or a `sub_keys` carve-out's individual keys. Input names have the form
`column_file:<uuid>:<column>.<field>...` and come from Plan's `env_missing`
checklist. Supply one filename under the adapter's declared content directory
through `--stdin`; the private intent store receives it, then ordinary Apply
updates the saved pointer. Source filenames and file contents never travel
with repository state. Provisioning refuses while an authored Apply holds
input intent, and Apply rechecks file availability before commit. A later
plugin job remains responsible for consuming its local file.
