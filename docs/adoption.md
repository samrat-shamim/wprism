# Adopting an existing WordPress host

`duo adopt <env>` installs Duo onto an already-running WordPress site reached
through the orchestrator's SSH transport. It is the bootstrap step before the
first `duo pending`, `duo classify`, or `duo capture`; it does not claim that
the site's pre-existing plugin state is already classified.

## Prerequisites

The machine running `duo` needs PHP 8+, `ssh`, `scp`, and `tar`. The target
needs a working `wp` command, `tar`, and a WordPress install. It does **not**
need Git. The SSH account must be able to write:

- WordPress's actual `WPMU_PLUGIN_DIR` (discovered with `wp eval`, never
  guessed from `wp_path`);
- the environment's configured `repo_path`.

Configure the target in `site.duo.json` or the machine-local,
gitignored `.duo-envs.json`:

```json
{
  "envs": {
    "production": {
      "transport": "ssh",
      "host": "deploy@wp.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/home/deploy/site-repo"
    }
  }
}
```

If the environment needs a dedicated SSH configuration, set `ssh_config` to
that file. Relative paths resolve from the registry file that defines the
environment. The same file is passed to both `ssh -F` and `scp -F`, so port,
identity, proxy, and host-key policy stay identical:

```json
{
  "envs": {
    "production": {
      "transport": "ssh",
      "host": "duo-production",
      "ssh_config": ".ssh/production.conf",
      "wp_path": "/var/www/html",
      "repo_path": "/home/deploy/site-repo"
    }
  }
}
```

## Install or update

Run from a Duo source checkout whose `cli/`, `agent/`, and `manifests/`
directories belong to the release you intend to install:

```sh
cli/duo doctor production   # expected to report the agent/repo missing first
cli/duo adopt production
```

Adoption performs these operations:

1. verifies SSH reachability and an installed WordPress;
2. discovers `WPMU_PLUGIN_DIR` through the target's own `wp` command;
3. sends one archive containing this checkout's complete `agent/` and
   `manifests/` trees;
4. stages and rollback-protects the live paths, then installs:
   - `WPMU_PLUGIN_DIR/duo/` (the agent),
   - `WPMU_PLUGIN_DIR/duo-loader.php` (the required top-level loader),
   - `WPMU_PLUGIN_DIR/manifests/` (the manifest library);
5. creates `repo_path/site.duo.json` only when it is absent, initially pinning
   `core` with post/page/attachment and category/post_tag scope;
6. starts fresh wp-cli processes to prove the remote `DUO_AGENT_VERSION`
   exactly matches this checkout and that `Policy::load()` can read the seed
   plus installed manifest library;
7. discards the prior release's rollback copies only after those checks pass;
8. runs the normal `duo doctor` checks. The command is successful only when
   every blocking doctor check passes.

An existing `site.duo.json` is never overwritten. Re-running the command is
the update mechanism: the current agent and manifest trees are replaced by
the exact trees beside the invoking CLI, while the site's policy remains
untouched. Symlink destinations are refused rather than followed, and an
install failure restores the previous agent, loader, and manifests and
removes a seed created by that failed run. A target-side adoption lock refuses
overlapping operators. If the host process is killed so abruptly that
`.duo-adopt-lock` remains, adoption fails loudly and requires operator
inspection rather than guessing whether the interrupted release should be
committed or restored.

## Why the manifest directory is installed beside the agent

`DUO_MANIFESTS_DIR` is a process environment variable. A value injected into
a container, service manager, or interactive shell is not generally present
in a later SSH login, so it cannot be the installation contract for an SSH
transport. The earlier hand-run adoption proof worked around that by copying
manifests to the agent's literal `/duo-manifests` fallback, which also assumes
the SSH account can modify a root-level path.

`duo adopt` instead uses the other existing `Policy::manifests_dir()`
fallback: `manifests/` beside the installed `duo/` directory. This layout is
stable across fresh SSH sessions, stays inside the operator-writable
mu-plugin directory, and requires neither an environment variable nor root
filesystem access. Adoption verifies that exact resolved path before it
reports success.

## Version visibility and remaining boundary

Every adoption prints and verifies the exact `DUO_AGENT_VERSION` it installed;
rerun the same release's `duo adopt` command to repair or update a stale copy.
`duo doctor` independently confirms that an agent is present, but it does not
know which source checkout or release an operator intended and therefore
cannot compare against an expected version when run alone. Packaging a signed
release descriptor that gives standalone doctor such an expectation remains
a named future distribution concern; presence is not presented as a
standalone freshness guarantee.

After installation, follow the classification funnel. `duo adopt` deliberately
does not auto-classify an old site's unknown/plugin-owned state:

```sh
cli/duo pending production
cli/duo classify production
cli/duo capture production
```

Those commands retain Duo's normal loud, blocking posture for anything the
seeded core policy does not yet explain.
