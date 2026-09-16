# Native dependency and restoration boundaries

`tests/live/regress_native_dependencies.sh` uses the capsule's
`native-apply/setup.sh` baseline: locked Qi 1.5.2 on two fresh sites, all four
target IDs divergent, the complete standalone corpus, seven compiled entities,
native HTTP/CSS, canonical recapture and zero-write repeat. It does not repeat
unrelated global-control, picker or crop sweeps.
Each live caller retains its canonical first-three-statement package authority;
the shared baseline validates that ownership before requiring or mutating a
pair. A sourced fixture does not substitute for the caller's preamble.

The dependency phase exercises six distinct target premises through public
content-only agent Apply:

- Exact 1.5.2 installed but inactive.
- Canonical plugin code absent after native uninstall.
- Official 1.5.1 installed and active.
- The exclusive upper boundary, 1.5.3, in the otherwise unchanged native header.
- An empty native Version header in the otherwise unchanged readable file.
- Exact 1.5.2 code activated under a different basename in the same directory.

The last three fault injectors keep a SHA-checked backup of the exact native
entry. They change only the declared header or basename and restore its exact
bytes. Observation skips ordinary plugins so the preimage cannot hide writes
caused by loading the plugin during the protected command. Native activation
uses WordPress's administrator-owned plugin lifecycle; no replacement adapter
runtime or plugin-specific production executable is involved.

Every refused Apply retains a complete, independently inventoried SQL dump,
the exact policy/state/media bytes, both plugin/upload hash inventories and the
exact dependency premise before and after the command. No table, transient,
file or row is filtered. Both images survive unexpected command outcomes.
The shared private-command wrapper retains original stdout/stderr and one fresh
private cause graph; public redaction alone cannot prove the dependency cause.
The offline suite binds each expected cause to the actual generic Apply
preparation gate and rejects incomplete or malformed preservation records.

Exact restoration must retain native activation, permit verified zero-write
Apply and repeat, preserve the complete target-native body/options/rows/media,
reopen all HTTP/CSS consumers, and recapture the complete source compiler fixed
point. Official prior-to-current replacement uses `--force` without redundant
activation; an independent observation proves the canonical active basename,
header version and exact main-file hash afterward.

Run with one owned pair and available even/successor ports:

```bash
QI_APPLY_PAIR=<owned-pair> QI_APPLY_PORT1=<even-port> QI_APPLY_PORT2=<next-port> \
QI_APPLY_ZIP=<absolute-path-to-locked-1.5.2-zip> \
QI_DEPENDENCY_PRIOR_ZIP=<absolute-path-to-locked-1.5.1-zip> \
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
bash adapter-packages/qi-blocks/tests/live/regress_native_dependencies.sh
```

Archive hashes are checked before pair mutation. Success requires full
admission and verified owned teardown. This evidence is confined to the public
content-only agent Apply dependency gate and native code restoration. Host
certification, source-payload transport, runtime/platform boundaries, retirement
and promotion/recovery remain separate qualification work.
