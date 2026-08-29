<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
// Every declaring-source question below goes through Policy, so this file
// requires it like every other file under agent/src (AGENTS.md rule 1) —
// `regress_agent_src_requires.php` refuses the pair otherwise. Policy.php
// requires nothing from Review, so the edge is one-way.
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/StateTreeWalker.php';

/**
 * WP-3.1: a capture-time lint finding is ADVISORY for state a reviewed adapter
 * declared, and BLOCKING for state an uncertified out-of-tree adapter declared.
 *
 * WHAT WAS ACTUALLY THERE BEFORE. `CapturePublicationWorkflow` ran
 * `Lint::scan_tree()` and, for ANY finding, appended one counted line to
 * `$candidate['warnings']` (:466-473). Capture then committed. So the guarantee
 * usually described as "a source-local id can never be shipped to a divergent
 * target" was, on this path, a sentence in a warning list — and reference
 * under-declaration is precisely the defect an out-of-tree author produces,
 * because the declaration that would have tokenized the value is the one they
 * did not write. The finding was already correct; nothing consumed it.
 *
 * WHY THE TIER, AND NOT SIMPLY "BLOCK". Blocking every finding would move the
 * SHIPPED library's behaviour, which AGENTS.md rule 8 pins, and would do it for
 * adapters whose declarations are digest-bound and reviewed with the agent
 * (rule 2) — the population that least needs the protection. So the gate keys
 * on `AdapterSources::is_uncertified_out_of_tree()`, whose three answering
 * states (never signed, WITHDRAWN, signed-but-unpinned) that method states one
 * by one. A shipped adapter's identical finding still warns, byte for byte.
 *
 * WHY A PROPOSED EXEMPTION DOES NOT SUPPRESS. WP-2.4 re-classes a `bare_id` on
 * a column whose live MySQL type bounds it to {0,1} as `proposed_lint_ok`. That
 * is a PROPOSAL carrying its premise — `Lint::proposal_note()` says so in the
 * finding itself ("This is a proposal carrying its premise, not a verdict and
 * not a silence: the finding is still reported and still counted"). A proposal
 * is evidence FOR a review, not the review; treating it as one would let the
 * gate be cleared by a fact about the third party's own database. So
 * `proposed_lint_ok` blocks at the risk tier exactly as `bare_id` does, and the
 * reviewed escape stays what it has always been: a `lint_ok: true` an author
 * writes into the manifest and a reviewer reads, which suppresses the finding
 * inside `Lint` itself (`Lint.php:816-818`) and therefore at BOTH tiers.
 *
 * WHY WP-2.4 IS A HARD DEPENDENCY. Blocking on a type-blind scanner would aim a
 * false-refusal generator at the very authors this protects: measured on the
 * shipped Ninja Forms manifest, 6 of its 8 hand-reviewed `lint_ok` columns are
 * decidable from column type alone (`manifests/ninja-forms.json:24-25`;
 * `sandbox/tests/offline/policy/regress_lint_type_exemptions.php` pins the
 * 6-of-8 split). With type awareness in, the residual set an author must argue
 * is both smaller and genuinely arguable.
 *
 * ATTRIBUTION, AND ITS HONEST EDGE. A finding carries `{class, path, locator}`
 * and no owner — deliberately, because `wp wprism lint --format=json` emits the
 * finding array verbatim (`Cli.php:2919`) and rule 8 pins those bytes. So the
 * owner is resolved HERE, from the same two inputs the scan had: the state file
 * and the Policy. Each surface is resolved through the `_details()` accessor
 * that already publishes the winning declaration's source — `declared_table_
 * details()` for a custom table, `meta_rule_details_for_post/term/user()` for a
 * meta key, `option_rule_details_for_option()` for an option name — so this
 * names the manifest the CAPTURE followed, not a second precedence walk.
 * A locator no declaration answers for (a block attribute, a post body, a menu)
 * is left UNATTRIBUTED and stays advisory: refusing there would charge a
 * stranger for core's surfaces, and a gate that blames the wrong adapter is
 * worse than one that stays quiet. `site.wprism.json` is likewise not an adapter —
 * the operator declaring their own site IS the reviewer this gate defers to.
 */
final class LintTrustGate {
    /**
     * Stable reason code. New refusal text is in scope for this work package
     * (rule 8 pins what exists); the SHIPPED path's warning is untouched.
     */
    public const REASON = 'uncertified_adapter_lint_findings';

    public const PUBLIC_MESSAGE = 'capture found suspicious unrewritten references in state declared by an '
        . 'adapter with no reviewed certification';

    public const REMEDIATION = 'for each named locator either declare the reference so capture tokenizes it, or '
        . 'record the reviewed exemption `lint_ok: true` on that declaration in the owning manifest; certifying '
        . 'the adapter returns its findings to advisory';

    /**
     * Refuse the capture when any finding belongs to a risk-tier adapter.
     *
     * Called only when `Lint::scan_tree()` returned findings, so a clean tree
     * costs nothing, and called BEFORE the advisory warning is appended so the
     * advisory path's bytes cannot depend on this having run.
     *
     * @param list<array<string,mixed>> $findings `Lint::scan_tree()`'s result
     */
    public static function assert(array $findings, Policy $policy, string $stateDir): void {
        $blocking = self::blocking($findings, $policy, $stateDir);
        if ($blocking === []) {
            return;
        }
        $sources = $policy->adapter_sources();
        $lines = [];
        $diagnostics = [];
        $reasons = [];
        foreach ($blocking as $row) {
            $adapter = $row['adapter'];
            $finding = $row['finding'];
            $locator = (string) $finding['path'] . ' ' . (string) $finding['locator'];
            $lines[] = $locator . ' (' . (string) $finding['class'] . ") — declared by adapter '$adapter'";
            $diagnostics[] = [
                'code' => 'uncertified_adapter_reference',
                // The same `surface` spelling CaptureSafetyGates uses for its
                // own refusals (`CaptureSafetyGates.php:33-38`): one string a
                // reader can search the state tree for.
                'surface' => $locator,
                'adapter' => $adapter,
                'finding_class' => (string) $finding['class'],
                'message' => 'a suspicious unrewritten reference sits in state declared by an uncertified '
                    . 'out-of-tree adapter',
                'remediation' => self::REMEDIATION,
            ];
            // The record's OWN sentence, minted by provenance_record(), which
            // is where a WITHDRAWN certification differs from one that never
            // existed: the withdrawal clause names the moved agent-owned
            // document and says the remedy is a re-signature, not a first
            // signature (`AdapterSources.php:3129-3138`). Deriving a second
            // sentence here would strand the withdrawn operator on advice for
            // a state they are not in.
            $record = $sources->provenance($adapter);
            $reason = is_string($record['reason'] ?? null) ? (string) $record['reason'] : null;
            if ($reason !== null) {
                $reasons[$adapter] = "  - $adapter: $reason";
            }
        }
        sort($reasons, SORT_STRING);
        $operatorMessage = 'wprism: suspicious unrewritten ref(s) in state declared by an uncertified out-of-tree '
            . "adapter (loud-and-blocking gate):\n  - " . implode("\n  - ", $lines) . "\n"
            . ($reasons === [] ? '' : ("Why these adapters are uncertified:\n" . implode("\n", $reasons) . "\n"))
            . 'The identical finding under a shipped or certified adapter is advisory, because those declarations '
            . 'are reviewed and digest-bound. Here nothing has reviewed them, and an under-declared reference is '
            . "the defect that survives a byte-identical round trip.\n"
            . 'Fix the declaration, or write the reviewed `lint_ok: true` exemption on it, then re-run capture. '
            . 'Run `wp wprism lint --repo=<repo>` for the full finding text.';
        throw new CommandRefusalException(
            self::REASON,
            self::PUBLIC_MESSAGE,
            self::REMEDIATION,
            $diagnostics,
            $operatorMessage
        );
    }

    /**
     * Every finding whose declaring adapter is at the risk tier, in scan order.
     *
     * @param list<array<string,mixed>> $findings
     * @return list<array{finding:array<string,mixed>, adapter:string}>
     */
    public static function blocking(array $findings, Policy $policy, string $stateDir): array {
        if ($findings === []) {
            return [];
        }
        $sources = $policy->adapter_sources();
        $risk = [];
        $out = [];
        foreach (self::attribute($findings, $policy, $stateDir) as $row) {
            $adapter = $row['adapter'];
            if ($adapter === null) {
                continue;
            }
            $risk[$adapter] ??= $sources->is_uncertified_out_of_tree($adapter);
            if ($risk[$adapter]) {
                $out[] = ['adapter' => $adapter, 'finding' => $row['finding']];
            }
        }
        return $out;
    }

    /**
     * One pass over the findings, grouped by state file so each file is read
     * once however many findings it produced.
     *
     * @param list<array<string,mixed>> $findings
     * @return list<array{finding:array<string,mixed>, adapter:?string}>
     */
    private static function attribute(array $findings, Policy $policy, string $stateDir): array {
        $stateDir = rtrim($stateDir, '/');
        // The scan's own surface classification, from the walker the scan used
        // — not a second glob here, which would be a second answer to "what
        // kind of file is this" the moment either moved.
        $surfaces = [];
        foreach (StateTreeWalker::files($stateDir) as $file) {
            $surfaces[$file['path']] = $file['surface'];
        }
        $fronts = [];
        $out = [];
        foreach ($findings as $finding) {
            $path = (string) ($finding['path'] ?? '');
            $surface = $surfaces[$path] ?? null;
            if ($surface === null) {
                // A finding on a file the walker no longer reports cannot be
                // attributed, and inventing an owner for it is exactly the
                // wrong direction for a blocking gate.
                $out[] = ['adapter' => null, 'finding' => $finding];
                continue;
            }
            if (!array_key_exists($path, $fronts)) {
                $fronts[$path] = self::read_front($stateDir . '/' . $path, $surface);
            }
            $out[] = [
                'adapter' => self::adapter_name(
                    self::declaring_source($surface, $fronts[$path], (string) ($finding['locator'] ?? ''), $policy)
                ),
                'finding' => $finding,
            ];
        }
        return $out;
    }

    /**
     * The decoded head of one state file, in the shape its own scanner reads
     * it: a post is front-matter plus body (`Lint::scan_post_file()`), an
     * options carrier is `OptionState::values()` (`scan_options_file()`), and
     * everything else is the canonical object.
     *
     * @return array<string,mixed>
     */
    private static function read_front(string $file, string $surface): array {
        try {
            $raw = Canon::read_file($file);
            if ($surface === 'post') {
                [$front] = Canon::parse_post_file($raw);
                return (array) $front;
            }
            $decoded = (array) Canon::decode($raw);
            return $surface === 'options' ? ['options' => OptionState::values($decoded)] : $decoded;
        } catch (\Throwable $unreadable) {
            // Unreachable in practice — Lint just read this same file to
            // produce the finding — and deliberately not fatal: a gate that
            // died on a re-read would convert an advisory finding into a
            // capture crash, which is a worse failure than not attributing it.
            return [];
        }
    }

    /**
     * Which manifest declared the surface this locator sits on, as the
     * `_details()` accessors already report it.
     *
     * @param array<string,mixed> $front
     */
    private static function declaring_source(string $surface, array $front, string $locator, Policy $policy): ?string {
        switch ($surface) {
            case 'table':
                // Whole-file: a table state file carries exactly one table
                // (`Lint::scan_table_file()` reads `$front['table']` once), and
                // both its `columns.*` and its attached-meta `meta.*` locators
                // are that table declaration's.
                return $policy->declared_table_details((string) ($front['table'] ?? ''))['source'];
            case 'post':
                $meta = (array) ($front['meta'] ?? []);
                $key = self::locator_key($locator, 'meta.', array_keys($meta));
                if ($key !== null) {
                    $source = $policy->meta_rule_details_for_post($key, $meta)['source'];
                    if ($source !== null) {
                        return $source;
                    }
                }
                // Falls through to the post type for a block attribute, a body,
                // a terms locator, or a meta key whose winning rule names no
                // source: whoever declared the type owns the row the value
                // lives in.
                return $policy->post_type_rule_details((string) ($front['type'] ?? ''))['source'];
            case 'term':
                $meta = (array) ($front['meta'] ?? []);
                $key = self::locator_key($locator, 'meta.', array_keys($meta));
                if ($key !== null) {
                    $source = $policy->meta_rule_details_for_term($key, $meta)['source'];
                    if ($source !== null) {
                        return $source;
                    }
                }
                return $policy->taxonomy_rule_details((string) ($front['taxonomy'] ?? ''))['source'];
            case 'user_meta':
                $meta = (array) ($front['meta'] ?? []);
                $key = self::locator_key($locator, 'meta.', array_keys($meta));
                return $key === null ? null : $policy->meta_rule_details_for_user($key, $meta)['source'];
            case 'options':
                $options = (array) ($front['options'] ?? []);
                $key = self::locator_key($locator, 'options.', array_keys($options));
                return $key === null ? null : $policy->option_rule_details_for_option($key, $options)['source'];
            default:
                // menus and sidebars: core surfaces with no per-file manifest
                // declaration to attribute to.
                return null;
        }
    }

    /**
     * The declared key a locator addresses, LONGEST match wins.
     *
     * Not a substring parse: a scanner builds `meta.<key>` and then appends
     * `[3]`, `[csv:1]` or a further `.subkey` for a structured walk
     * (`Lint::scan_structured_bare_ids()`), so `meta.a` and `meta.a.b` can both
     * prefix one locator and only the longer of the two is the key that exists.
     * Matching against the file's OWN key set is also what makes a key
     * containing a dot or a bracket resolve correctly rather than plausibly.
     *
     * @param list<array-key> $keys
     */
    private static function locator_key(string $locator, string $prefix, array $keys): ?string {
        $best = null;
        foreach ($keys as $candidate) {
            $key = (string) $candidate;
            $head = $prefix . $key;
            if ($locator !== $head
                && !str_starts_with($locator, $head . '[')
                && !str_starts_with($locator, $head . '.')) {
                continue;
            }
            if ($best === null || strlen($key) > strlen($best)) {
                $best = $key;
            }
        }
        return $best;
    }

    /**
     * The adapter identity inside a `_details()` source string, or null when
     * the declaration belongs to nobody this gate may charge.
     *
     * The two non-manifest spellings are deliberate. `site.wprism.json` is the
     * operator's own declaration — they are the reviewer, so there is no third
     * party to gate. A bare `interpreter <name>` (Policy's fallback when no
     * loaded manifest claims that interpreter, `Policy.php:2216`) names code,
     * not an installed adapter, and an out-of-tree manifest cannot declare an
     * interpreter at all (`AdapterSources::assert_out_of_tree_contract()`), so
     * that spelling can never be a risk-tier row.
     */
    private static function adapter_name(?string $source): ?string {
        if ($source === null || $source === '' || $source === 'site.wprism.json' || $source === 'repo-format') {
            return null;
        }
        if (str_starts_with($source, 'interpreter ')) {
            return null;
        }
        // `<manifest> (interpreter <name>)` — the manifest that shipped the
        // interpreter is the declaring adapter; the parenthetical says which
        // of its hooks answered.
        $hook = strpos($source, ' (interpreter ');
        return $hook === false ? $source : substr($source, 0, $hook);
    }
}
