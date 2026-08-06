<?php
namespace Duo;

use WP_CLI;

/**
 * wp duo <capture|plan|apply|journal-report|journal-reset>
 */
final class Cli {
    private static function halt_json_failure(\Throwable $t, array $assoc): void {
        if (($assoc['format'] ?? '') !== 'json'
            || !($t instanceof RepositoryCompilationException || $t instanceof RepositoryAuthorizationException)) {
            return;
        }
        WP_CLI::line(json_encode($t->payload(), JSON_UNESCAPED_SLASHES));
        WP_CLI::halt(1);
    }

    /**
     * Compile a canonical revision into Duo's immutable, content-addressed
     * apply artifact without reading or mutating the target environment.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--out=<path>] : Write the complete artifact as canonical JSON.
     * [--json]           : Emit the complete artifact as JSON.
     * [--format=<format>] : Output format. Accepts json.
     */
    public function compile($args, $assoc) {
        $repo = $assoc['repo'] ?? WP_CLI::error('--repo required');
        try {
            $artifact = RepositoryCompiler::compile($repo, Policy::load($repo));
            if (!empty($assoc['out'])) {
                $artifact->write((string) $assoc['out']);
            }
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc);
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($artifact->export(), JSON_UNESCAPED_SLASHES));
            return;
        }
        WP_CLI::success(sprintf(
            'compiled artifact %s (revision %s, manifests %s)%s',
            $artifact->artifact_hash(), $artifact->revision_hash(), $artifact->manifest_hash(),
            !empty($assoc['out']) ? ' -> ' . $assoc['out'] : ''
        ));
    }

    /**
     * Capture this environment's authored state into the site repo.
     *
     * ## OPTIONS
     * --repo=<path>    : Site repo root (contains site.duo.json).
     * [--out=<path>]   : Write the state tree elsewhere (determinism checks); skips ledger/media updates.
     * [--force-unresolved-refs] : drop an authored, ref-typed option whose target row exists but is out of
     *   policy scope the same way a dangling (deleted-target) reference is dropped, instead of aborting
     *   (task #73's loud-and-blocking gate; the honest fix is adding the target's post type/taxonomy to
     *   policy scope — this flag is the explicit best-effort escape hatch for when that isn't wanted).
     * [--json]           : JSON summary (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function capture($args, $assoc) {
        try {
            $summary = Capture::run(
                $assoc['repo'] ?? WP_CLI::error('--repo required'),
                $assoc['out'] ?? null,
                isset($assoc['force-unresolved-refs'])
            );
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        // WP-CLI's dispatcher rewrites a bare --json into format=json and unsets
        // 'json' before the command runs (Runner::run_command()) — there is never
        // an $assoc['json'] key to isset() against.
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        WP_CLI::success(sprintf(
            'captured %d posts, %d terms, %d menus, %d options file(s), %d media blob(s) -> %s',
            $summary['counts']['post'] ?? 0,
            $summary['counts']['term'] ?? 0,
            $summary['counts']['menu'] ?? 0,
            $summary['counts']['options'] ?? 0,
            $summary['media'],
            $summary['state_dir']
        ));
    }

    /**
     * Preview what apply would do (terraform-style).
     *
     * ## OPTIONS
     * --repo=<path>
     * [--adopt-by-slug=<kinds>] : e.g. terms,posts,menus
     * [--force-unresolved-refs] : see `duo capture`'s option of the same name — plan's own drift
     *   detection captures the live environment too, so it hits the identical gate.
     * [--compiled=<path>] : Consume a previously emitted compiler artifact; active policy/manifest hashes must match.
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function plan($args, $assoc) {
        try {
            $plan = Apply::plan($assoc['repo'] ?? WP_CLI::error('--repo required'), [
                'adopt_by_slug' => $assoc['adopt-by-slug'] ?? '',
                'force_unresolved_refs' => isset($assoc['force-unresolved-refs']),
                'compiled' => $assoc['compiled'] ?? '',
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc);
            WP_CLI::error($t->getMessage());
        }
        // See capture(): --json arrives here as $assoc['format'] === 'json', never $assoc['json'].
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($plan, JSON_UNESCAPED_SLASHES));
            return;
        }
        $kinds = ['create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision', 'delete'];
        foreach ($kinds as $kind) {
            foreach ($plan[$kind] as $r) {
                $line = strtoupper(str_pad($kind, 9)) . ' ' . ($r['path'] ?? ($r['type'] . ' ' . $r['uuid']));
                if (isset($r['blocked'])) {
                    $line .= '  [BLOCKED: ' . $r['blocked'] . ']';
                }
                WP_CLI::line($line);
            }
        }
        // code_mismatch (docs/proposals/code-half.md §3.2): a different row
        // shape (issue/kind/plugin-or-theme/message, no uuid/path) than the
        // $kinds loop above, so it gets its own rendering rather than being
        // folded into that loop.
        foreach ($plan['code_mismatch'] ?? [] as $r) {
            WP_CLI::line('CODE_MISMATCH ' . strtoupper($r['issue']) . ' ' . ($r['plugin'] ?? $r['theme'] ?? '?'));
            WP_CLI::line('  ' . $r['message']);
        }
        foreach ($plan['warnings'] ?? [] as $w) {
            WP_CLI::warning($w);
        }
        $counts = implode(', ', array_map(fn($k) => count($plan[$k]) . " $k", $kinds));
        $counts .= ', ' . count($plan['code_mismatch'] ?? []) . ' code_mismatch';
        WP_CLI::success("plan: $counts");
        if ($plan['drift']) {
            WP_CLI::warning('environment drift detected — capture-first workflow recommended');
        }
        if (!empty($plan['code_mismatch'])) {
            WP_CLI::warning('code_mismatch findings — duo apply will refuse until resolved (or run with --force-code-mismatch)');
        }
    }

    /**
     * Materialize the repo state into this environment.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--adopt-by-slug=<kinds>]
     * [--with-deletes]
     * [--force-delete-referenced] : override referential delete guards.
     * [--force-theirs]
     * [--force-code-mismatch] : override the cross-partition invariant's missing_in_code/outside_version_range block.
     * [--force-unresolved-refs] : see `duo capture`'s option of the same name — apply's own drift
     *   detection captures the live environment too, so it hits the identical gate.
     * [--default-author=<login>]
     * [--revision=<rev>]
     * [--compiled=<path>] : Consume a previously emitted compiler artifact; active policy/manifest hashes must match.
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function apply($args, $assoc) {
        try {
            $summary = Apply::apply($assoc['repo'] ?? WP_CLI::error('--repo required'), [
                'adopt_by_slug' => $assoc['adopt-by-slug'] ?? '',
                'with_deletes' => isset($assoc['with-deletes']),
                'force_delete_referenced' => isset($assoc['force-delete-referenced']),
                'force_theirs' => isset($assoc['force-theirs']),
                'force_code_mismatch' => isset($assoc['force-code-mismatch']),
                'force_unresolved_refs' => isset($assoc['force-unresolved-refs']),
                'default_author' => $assoc['default-author'] ?? '',
                'revision' => $assoc['revision'] ?? '',
                'compiled' => $assoc['compiled'] ?? '',
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc);
            WP_CLI::error($t->getMessage());
        }
        // See capture(): --json arrives here as $assoc['format'] === 'json', never $assoc['json'].
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        foreach ($summary['drift'] as $d) {
            WP_CLI::warning("drift (env ahead, untouched): $d");
        }
        WP_CLI::success(sprintf(
            'applied %d entities (canary %s) — plan was: %s',
            $summary['applied'],
            $summary['canary'],
            json_encode($summary['plan'])
        ));
    }

    /**
     * Reconcile this environment's active_plugins/template/stylesheet to
     * what state/options/core.json declares — the ONLY place
     * activate_plugin()/deactivate_plugins()/switch_theme() run, and
     * deliberately OUTSIDE `wp duo apply`'s hook-free canary
     * (docs/proposals/code-half.md §3.4): activation hooks MUST fire here
     * (that's how plugins do one-time setup/migrations); apply's canary
     * requires the opposite, so the two can never share a transaction.
     * Refuses loudly, listing every finding, while any currently-desired-
     * active plugin/theme is missing from this environment's code, or a
     * version_range-pinned plugin is outside its declared range —
     * --force-code-mismatch overrides (matching apply's --force-theirs/
     * --force-delete-referenced convention). Idempotent: run again with
     * nothing to reconcile and zero WP APIs get called.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--force-code-mismatch] : proceed despite missing_in_code / outside_version_range findings.
     * [--compiled=<path>] : Consume a previously emitted compiler artifact; active policy/manifest hashes must match.
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function deploy($args, $assoc) {
        try {
            $summary = Deploy::run($assoc['repo'] ?? WP_CLI::error('--repo required'), [
                'force_code_mismatch' => isset($assoc['force-code-mismatch']),
                'compiled' => $assoc['compiled'] ?? '',
            ]);
        } catch (\Throwable $t) {
            self::halt_json_failure($t, $assoc);
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($summary, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($summary['warnings'] as $w) {
            WP_CLI::warning($w);
        }
        foreach ($summary['activated'] as $p) {
            WP_CLI::line("activated: $p");
        }
        foreach ($summary['deactivated'] as $p) {
            WP_CLI::line("deactivated: $p");
        }
        if ($summary['theme_switched'] !== null) {
            WP_CLI::line("theme switched: {$summary['theme_switched']}");
        }
        WP_CLI::success(sprintf(
            '%d activated, %d deactivated%s',
            count($summary['activated']),
            count($summary['deactivated']),
            $summary['theme_switched'] !== null ? ", theme -> {$summary['theme_switched']}" : ''
        ));
    }

    /**
     * Aggregate the provenance journal and score proposals against manifests.
     *
     * ## OPTIONS
     * [--manifests=<names>] : comma-separated, default "core".
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     *
     * @subcommand journal-report
     */
    public function journal_report($args, $assoc) {
        $names = array_filter(explode(',', $assoc['manifests'] ?? 'core'));
        try {
            $report = Journal::report($names);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        // wp-cli rewrites a bare --json into $assoc['format']='json' before
        // this method ever sees it (verified empirically: no 'json' key is
        // ever present) — check both so it's correct regardless of wp-cli
        // version/convention.
        if (isset($assoc['json']) || ($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($report, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($report['rows'] as $r) {
            WP_CLI::line(sprintf(
                '%-24s %-36s %-7s %-15s n=%-4d proposed=%-8s manifest=%-10s %s',
                $r['table'], $r['item'] !== '' ? $r['item'] : '—', $r['surface'],
                $r['caps'] !== '' ? $r['caps'] : 'anon', $r['n'], $r['proposal'], $r['manifest'], $r['verdict']
            ));
        }
        WP_CLI::line('');
        WP_CLI::success(sprintf(
            'agreement on manifest-classified writes: %s%% (agree %d / disagree %d), abstained %d, unclassified (the review queue) %d',
            $report['agreement_pct'] ?? 'n/a',
            $report['agree'], $report['disagree'], $report['abstain'], $report['unclassified']
        ));
    }

    /**
     * Agent + spec version — the stable probe for external tooling
     * (orchestrators check this instead of internal class names).
     */
    public function version($args, $assoc) {
        WP_CLI::line(json_encode([
            'agent' => DUO_AGENT_VERSION,
            'spec_version' => DUO_SPEC_VERSION,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Truncate the provenance journal.
     *
     * @subcommand journal-reset
     */
    public function journal_reset($args, $assoc) {
        global $wpdb;
        Ledger::ensure();
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}duo_journal");
        WP_CLI::success('journal truncated');
    }

    /**
     * The core loop's review queue (DESIGN.md 3.1.5): unclassified post_meta
     * /term_meta on in-scope entities (the same gate `duo capture` aborts
     * on) plus journal-observed unclassified options (options are
     * whitelist-only at capture, so an unlisted option is only visible via
     * the journal). Each item carries whatever evidence exists — entity
     * counts, journal surfaces/caps/proposal, a post/term ref-hint, a
     * secret flag — never a guessed classification.
     *
     * ## OPTIONS
     * --repo=<path>
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function pending($args, $assoc) {
        try {
            $items = Pending::scan($assoc['repo'] ?? WP_CLI::error('--repo required'));
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($items, JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$items) {
            WP_CLI::success('no pending unclassified state');
            return;
        }
        foreach ($items as $it) {
            $ev = $it['evidence'];
            $evParts = [];
            if (isset($ev['entities'])) {
                $evParts[] = "entities={$ev['entities']}";
            }
            if (!empty($ev['post_types'])) {
                $evParts[] = 'types=' . implode(',', $ev['post_types']);
            }
            if (isset($ev['journal'])) {
                $j = $ev['journal'];
                $surf = implode(',', array_map(fn($k, $v) => "$k=$v", array_keys($j['surfaces']), $j['surfaces']));
                $evParts[] = "journal.n={$j['n']}($surf)";
            }
            $hint = '';
            if (isset($it['ref_hint'])) {
                $h = $it['ref_hint'];
                $hint = "{$h['kind']}:{$h['id']} \"{$h['title']}\" ({$h['post_type']})";
            }
            WP_CLI::line(sprintf(
                '%-10s %-32s proposal=%-9s %-50s %-45s %s',
                $it['section'], $it['key'], $it['proposal'] ?? '—',
                implode(' ', $evParts), $hint, $it['secret'] ?? ''
            ));
        }
        WP_CLI::line('');
        WP_CLI::success(count($items) . " pending item(s) — classify with: wp duo classify --repo=<repo> --set 'section:key=class'");
    }

    /**
     * Write policy classification rules — the `wp duo pending` -> `wp duo
     * classify` step of the core loop. Rules land in site.duo.json's policy
     * overrides (Policy::set_rule); this command does not itself capture.
     *
     * ## OPTIONS
     * --repo=<path>
     * --set=<spec>         : "section:key=class[,ref=post][,cast=string]"
     *   (section is options|post_meta|term_meta; the spec is split on the
     *   FIRST ':' and the FIRST '='). Two wp-cli parsing quirks verified
     *   empirically against this exact command (both silently swallow the
     *   value otherwise — instrumented with a live var_dump of $args/$assoc,
     *   not assumed):
     *     1. Use the `=` form (--set=foo:bar=baz). The space form
     *        (--set foo:bar=baz) is NOT equivalent — wp-cli parses a
     *        space-separated value as a bare boolean flag ($assoc['set']
     *        becomes `true`) and the intended value lands in positional
     *        $args instead, silently.
     *     2. Repeating the flag (--set=a --set=b) does NOT accumulate: only
     *        the LAST occurrence survives ($assoc['set'] is a plain string,
     *        never an array, in this wp-cli version). So pass multiple
     *        rules as ONE --set value, semicolon-separated:
     *          --set='post_meta:foo=runtime;options:bar=authored,ref=post'
     * [--allow-secret]     : permit class=authored when the key's current
     *                         value hard-matches a secret pattern; sets
     *                         allow_secret:true on the rule written (the
     *                         same escape hatch Capture's guard honors).
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function classify($args, $assoc) {
        $repo = $assoc['repo'] ?? WP_CLI::error('--repo required');
        $raw = $assoc['set'] ?? null;
        if ($raw === null) {
            WP_CLI::error('--set required, e.g. --set "post_meta:foo=runtime"');
        }
        $specs = [];
        foreach ((array) $raw as $chunk) {
            foreach (explode(';', (string) $chunk) as $one) {
                $one = trim($one);
                if ($one !== '') {
                    $specs[] = $one;
                }
            }
        }
        $allowSecret = isset($assoc['allow-secret']);
        $written = [];
        try {
            foreach ($specs as $spec) {
                $written[] = self::parse_and_write_classify_spec($repo, $spec, $allowSecret);
            }
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($written, JSON_UNESCAPED_SLASHES));
            return;
        }
        foreach ($written as $w) {
            $extra = [];
            if (isset($w['rule']['ref'])) {
                $extra[] = "ref={$w['rule']['ref']}";
            }
            if (isset($w['rule']['cast'])) {
                $extra[] = "cast={$w['rule']['cast']}";
            }
            if (!empty($w['rule']['allow_secret'])) {
                $extra[] = 'allow_secret=true';
            }
            WP_CLI::line("set {$w['section']}:{$w['key']} = {$w['rule']['class']}" . ($extra ? ' (' . implode(', ', $extra) . ')' : ''));
        }
        WP_CLI::success(count($written) . " rule(s) written to site.duo.json — run: wp duo capture --repo=$repo");
    }

    /**
     * Parses one "section:key=class[,ref=x][,cast=y]" spec — split on the
     * FIRST ':' and FIRST '=' — and writes it via Policy::set_rule,
     * refusing class=authored over a hard-matched secret unless $allowSecret.
     */
    private static function parse_and_write_classify_spec(string $repo, string $spec, bool $allowSecret): array {
        $colon = strpos($spec, ':');
        $eq = strpos($spec, '=');
        if ($colon === false || $eq === false || $eq < $colon) {
            throw new \RuntimeException("duo: bad --set spec '$spec' (expected section:key=class[,ref=..][,cast=..])");
        }
        $section = substr($spec, 0, $colon);
        $key = substr($spec, $colon + 1, $eq - $colon - 1);
        $tail = substr($spec, $eq + 1);

        $parts = explode(',', $tail);
        $class = array_shift($parts);
        $rule = ['class' => $class];
        foreach ($parts as $p) {
            $kv = explode('=', $p, 2);
            $k = $kv[0] ?? '';
            $v = $kv[1] ?? '';
            if ($k === 'ref') {
                $rule['ref'] = $v;
            } elseif ($k === 'cast') {
                $rule['cast'] = $v;
            } else {
                throw new \RuntimeException("duo: unknown option '$k' in --set spec '$spec' (expected ref=|cast=)");
            }
        }

        if ($class === 'authored') {
            $current = Pending::current_value($section, $key);
            if (is_string($current)) {
                $label = Secrets::hard_match($current);
                if ($label !== null) {
                    if (!$allowSecret) {
                        throw new \RuntimeException(
                            "duo: refusing '$spec' — current value of $section:$key looks like a $label; pass --allow-secret to override"
                        );
                    }
                    $rule['allow_secret'] = true;
                }
            }
        }

        Policy::set_rule($repo, $section, $key, $rule);
        return ['section' => $section, 'key' => $key, 'rule' => $rule];
    }

    /**
     * The generalized suspicious-ref linter (task #11; docs/frontier/{fse,
     * polylang,elementor}.md): scans a CAPTURED state tree for ref-shaped
     * values that reached canonical state WITHOUT ever passing through a
     * declared rewrite path. This is the correctness gate byte-identical
     * round-tripping cannot be: a value the tokenizer never looks at gets
     * captured and re-applied as the exact same wrong bytes on every
     * environment, so `duo capture`'s own determinism check reports
     * "clean" on real corruption — all three frontier explorations
     * independently hit this blind spot and lost real content to it.
     *
     * Four detection classes, each finding tagged accordingly:
     *   bare_id             — a numeric post_meta/option value with no ref
     *                         declared in its rule, where the number
     *                         happens to match an existing post/term id on
     *                         THIS environment (finding #9's original ask).
     *   escaped_home        — this environment's home URL in JSON-escaped
     *                         form (`https:\/\/…`), in a post body or any
     *                         meta/option value — tokenize_text() only
     *                         matches the plain, unescaped form (Elementor's
     *                         _elementor_data shape).
     *   unregistered_block_attr — id/ids/ref(-suffixed) block attributes
     *                         with no block_attrs registry rule for that
     *                         exact path (FSE's core/navigation-link shape
     *                         before it had one), plus plain-form home URLs
     *                         sitting in ANY string block attribute (block
     *                         attrs are never routed through the text
     *                         tokenizer, rule or no rule).
     *   serialized_desc_ids — a term description that unserializes (PHP
     *                         serialize format) to data containing an
     *                         integer matching an existing post/term id
     *                         (Polylang's post_translations/
     *                         term_translations shape).
     *
     * Every finding is a plan-time SIGNAL, not proof of corruption: small
     * ids legitimately coincide with unrelated authored numbers (counts,
     * versions, ordering indexes) — that caveat travels in each finding's
     * own "note", never left implicit. Exits 1 when findings exist (a gate
     * only when a caller scripts it that way — see sandbox/conformance/
     * run.sh, which currently runs this warn-only, pending main wiring it
     * into capture as a hard gate).
     *
     * ## OPTIONS
     * --repo=<path>
     * [--json]           : JSON output (wp-cli rewrites this to --format=json).
     * [--format=<format>] : Output format. Accepts json.
     */
    public function lint($args, $assoc) {
        $repo = $assoc['repo'] ?? WP_CLI::error('--repo required');
        try {
            $policy = Policy::load($repo);
            $findings = Lint::scan_tree(rtrim($repo, '/') . '/state', $policy);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        if (($assoc['format'] ?? '') === 'json') {
            WP_CLI::line(json_encode($findings, JSON_UNESCAPED_SLASHES));
        } elseif (!$findings) {
            WP_CLI::success('no findings — captured state is clean');
        } else {
            foreach ($findings as $f) {
                $val = is_scalar($f['value']) ? (string) $f['value'] : json_encode($f['value'], JSON_UNESCAPED_SLASHES);
                $match = isset($f['matches'])
                    ? sprintf(' matches=%s:%d "%s" (%s)', $f['matches']['kind'], $f['matches']['id'], $f['matches']['title'], $f['matches']['post_type'])
                    : '';
                WP_CLI::line(sprintf('%-24s %-55s %-32s value=%s%s', $f['class'], $f['path'], $f['locator'], $val, $match));
                WP_CLI::line('    ' . $f['note']);
            }
            WP_CLI::line('');
            WP_CLI::warning(count($findings) . ' finding(s) — review before trusting a byte-identical round trip');
        }
        if ($findings) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Draft-manifest export: every site-policy rule (not inherited manifest
     * rules — the human is promoting decisions they made) whose key matches
     * --match, grouped into a manifest-shaped JSON document on stdout.
     * site.duo.json is left untouched; promoting rules into a real manifest
     * file upstream is a deliberate, separate human act.
     *
     * ## OPTIONS
     * --repo=<path>
     * --match=<regex>   : PCRE body (no delimiters), tested against each key.
     * --name=<name>     : the exported manifest's "name" field.
     *
     * @subcommand policy-to-manifest
     */
    public function policy_to_manifest($args, $assoc) {
        $repo = $assoc['repo'] ?? WP_CLI::error('--repo required');
        $match = $assoc['match'] ?? WP_CLI::error('--match required');
        $name = $assoc['name'] ?? WP_CLI::error('--name required');
        try {
            $manifest = Policy::export_manifest($repo, $match, $name);
        } catch (\Throwable $t) {
            WP_CLI::error($t->getMessage());
        }
        WP_CLI::line(rtrim(Canon::encode($manifest)));
    }
}

WP_CLI::add_command('duo', Cli::class);
