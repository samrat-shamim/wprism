# Can my site use WPrism?

Start with the [disposable demo](try-wprism.md). Evaluating a site, capturing
authored state, and releasing a change have different requirements. Access
sufficient for assessment may still be insufficient for database mutation or
verified recovery.

## Check hosting before adoption

| Requirement | What to establish |
|---|---|
| WordPress topology | A single-site installation with the supported content and MU-plugin layout. Multisite and unsupported layouts refuse. |
| Runtime versions | Compare WordPress, PHP, and the database engine/version with the current [compatibility data](../compatibility-baseline.json). Do not infer support from “PHP 8” or “MySQL compatible.” |
| Target access | Working WP-CLI, PHP with Sodium and durable filesystem functions, Git, and appropriate filesystem access. Managed Docker tooling can supply WP-CLI and Git for a supported local Compose site. SSH adoption also needs SSH/SCP and tar. |
| Process and filesystem | The declared local POSIX process/filesystem profile, including the required process functions and disabled CLI OPcache. The compatibility data is authoritative. |
| Media | Git LFS on the target and on machines cloning a site repository with attachment bytes. |
| Database mutation | The database account must prove a complete InnoDB foreign-key census, including a direct global `PROCESS` privilege. |
| Plugin and theme scope | Exact supported versions, declared authored surfaces, and no unsupported requested operations. |
| Production release | Configured environment bindings, deployment authority, writer exclusion, effect handling, and the requirements of the selected recovery profile. |

Ask your host whether it can provide these requirements. A missing database
census privilege leaves adoption, capture, and assessment available where their
other checks pass, but transactional database writes refuse before the first
write. Do not weaken the account or platform checks to make a site appear ready.

Render the current library without connecting a site:

```sh
php tools/capability-doc.php render
php tools/adapter-grade.php render
```

The generated output is the exact source projection for that checkout. This
guide deliberately does not maintain a second plugin/version matrix.

## Evaluate a real site in order

1. Review the [adoption prerequisites](../adoption.md) with the site operator.
2. Follow [quickstart.md](quickstart.md) to connect and onboard.
3. On the adopted target, run `wprism doctor <env>`, `wprism assess <env>`,
   and `wprism capabilities <env>`.
4. Read the proposed scope and unsupported boundaries before accepting a
   contract or initializing a baseline.
5. Rehearse the intended change in a disposable environment and review
   [release](release.md) and [recovery](recovery.md) before production use.

The connect probes issue no explicit WordPress mutation, but their bootstrap
can run site startup code. Managed Docker connection also builds helper tooling
and creates a private Compose overlay and repository volume. Onboarding installs
the control plane; explicitly selected Docker database setup grants the census
privilege to the site's database account. These are not purely read-only
assessments. The quickstart names each mutation and confirmation point.

A reviewed adapter does not imply that every feature of its plugin, every
co-installed plugin, or the whole site is supported. Orders, inventory,
sessions, secrets, and other protected runtime state remain local by design.

## Share an eligibility result

A useful report identifies the WPrism commit, runtime and plugin versions,
intended operation, exact refusal code, and a minimal synthetic reproduction
when possible. Remove hostnames, usernames, credentials, customer records,
and site content before sharing logs. Follow [SECURITY.md](../../SECURITY.md)
for security findings.
