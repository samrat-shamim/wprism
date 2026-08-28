<?php
/**
 * WP-3.1: a capture-time lint finding is ADVISORY for state a reviewed adapter
 * declared and BLOCKING for state an uncertified out-of-tree adapter declared.
 *
 * WHAT THIS PINS, AND WHY THE PAIRING IS THE WHOLE POINT. Every case below is
 * run TWICE over the same state bytes, the same finding set, and manifests that
 * differ in exactly one thing: whether the declaring adapter is shipped or
 * installed out-of-tree. `CapturePublicationWorkflow.php:466-473` used to turn
 * every finding into one counted warning and commit; that is the state the
 * SHIPPED half of each pair still asserts, byte for byte (AGENTS.md rule 8),
 * while the out-of-tree half now refuses. A gate that moved both halves would
 * be a behaviour change for the digest-bound library nobody asked for; a gate
 * that moved neither would be the sentence-shaped guarantee this replaces.
 *
 * THE THREE UNCERTIFIED STATES ARE ONE STATE. `is_uncertified_out_of_tree()`
 * answers true for a never-signed adapter, for one whose certification was
 * WITHDRAWN (WP-1.1), and for one whose valid signature the repository has not
 * pinned. The withdrawal case is asserted here against a record minted by the
 * engine's OWN `provenance_record()` — reached by reflection precisely so the
 * fixture cannot be a hand-typed paraphrase of it — because that is the record
 * `AdapterSources::from_snapshot()` substitutes on withdrawal
 * (`AdapterSources.php:3956-3963`), and it differs from a never-signed record
 * in exactly one field: `reason`. That is why the gate must not try to tell
 * them apart, and why the refusal quotes that field instead of composing its
 * own sentence — a withdrawn operator needs "re-sign", not "get it signed".
 *
 * A PROPOSED EXEMPTION IS NOT A REVIEWED ONE. WP-2.4 re-classes a `bare_id` on
 * a BIT(1) column as `proposed_lint_ok`. The pair below asserts that finding
 * still REFUSES at the risk tier. The reasoning is the proposal's own words —
 * `Lint::proposal_note()` writes "This is a proposal carrying its premise, not
 * a verdict and not a silence" and then names the declaration a reviewer would
 * have to write. A gate cleared by a proposal would be cleared by a fact about
 * the third party's own database, which is not review; the reviewed escape
 * stays `lint_ok: true`, and the third pair asserts that escape suppresses at
 * BOTH tiers (it suppresses inside `Lint` itself, so there is no finding left
 * for any tier to judge).
 *
 * WP-2.4 IS A HARD DEPENDENCY, NOT A COINCIDENCE OF ORDERING. Blocking on a
 * type-blind scanner aims a false-refusal generator at the authors this
 * protects: 6 of Ninja Forms' 8 hand-reviewed `lint_ok` columns are decidable
 * from column type alone (`manifests/ninja-forms.json:24-25`, split asserted in
 * `sandbox/tests/offline/policy/regress_lint_type_exemptions.php`). The
 * `proposed_lint_ok` pair here is what keeps that dependency observable.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

$root = dirname(__DIR__, 4);

// The engine's two version constants come from agent/duo.php's own source, the
// way every offline harness resolves them — never a literal here.
$agentSource = (string) file_get_contents($root . '/agent/duo.php');
if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $agentSource, $m) !== 1) {
    fwrite(STDERR, "FAIL: could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_SPEC_VERSION', (int) $m[1]);
if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $agentSource, $m) !== 1) {
    fwrite(STDERR, "FAIL: could not resolve DUO_AGENT_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_AGENT_VERSION', $m[1]);

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Review/Lint.php';
require_once $root . '/agent/src/Review/LintTrustGate.php';
require_once $root . '/agent/src/Adapter/AdapterProbe.php';
// The capture path itself, for the advisory half's byte-identity assertion.
// It loads offline over the shared WordPress stubs above; nothing here runs a
// capture, and `lintWarning()` is the pure string builder the workflow calls.
require_once $root . '/agent/src/Capture/CapturePublicationWorkflow.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use Duo\AdapterProbe;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\CapturePublicationWorkflow;
use Duo\CommandRefusalException;
use Duo\Lint;
use Duo\LintEnvironment;
use Duo\LintTrustGate;
use Duo\Policy;
use DuoTest\FakeWpdb;
use DuoTest\FrozenPolicy;
use DuoTest\WpStore;

// ---------------------------------------------------------------- fixtures

$tmp = sys_get_temp_dir() . '/duo_lint_trust_tier_' . bin2hex(random_bytes(6));
mkdir($tmp . '/state/tables/acme_rows', 0777, true);
mkdir($tmp . '/state/posts/post', 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walk as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($tmp);
});
$state = $tmp . '/state';

/**
 * The out-of-tree adapter under test: data only, which is all an out-of-tree
 * manifest may ever be (`AdapterSources::assert_out_of_tree_contract()` refuses
 * every executable channel), and therefore exactly the population WP-3.1 is
 * about. `related_id` has no ref declared — the under-declaration a stranger's
 * manifest produces — and `is_featured` is the BIT(1) column WP-2.4 proposes on.
 *
 * @param bool $lintOk write the reviewed exemption on `related_id`
 * @return array<string,mixed>
 */
function acme_manifest(bool $lintOk = false): array {
    $related = ['class' => 'authored'];
    if ($lintOk) {
        $related['lint_ok'] = true;
    }
    return [
        'name' => 'acme-tables',
        'post_meta' => [
            // Deliberately a PREFIX of the key below it: the gate resolves a
            // locator against the file's own key set, longest match wins, and
            // the two keys are split across the two manifests so a wrong match
            // is observable as a wrong ADAPTER rather than as nothing at all.
            'acme_ref_map' => ['class' => 'authored'],
        ],
        'spec_version' => DUO_SPEC_VERSION,
        'tables' => [
            'acme_rows' => [
                'class' => 'authored_snapshot',
                'columns' => [
                    'is_featured' => ['class' => 'authored'],
                    'related_id' => $related,
                    'title' => ['class' => 'authored'],
                ],
                'id_kind' => 'acme_row',
                'pk' => 'id',
                'refs' => [],
                'slug_column' => 'title',
            ],
        ],
    ];
}

/**
 * A second, always-SHIPPED adapter loaded beside the first in every case. It
 * owns `acme_ref` — the shorter key — so the post-meta pair proves the locator
 * resolves to the manifest that actually declared it.
 *
 * @return array<string,mixed>
 */
function bystander_manifest(): array {
    return [
        'name' => 'acme-bystander',
        'post_meta' => ['acme_ref' => ['class' => 'authored']],
        'spec_version' => DUO_SPEC_VERSION,
    ];
}

file_put_contents($state . '/tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json', Canon::encode([
    'columns' => ['is_featured' => 1, 'related_id' => 3, 'title' => 'text<Row>'],
    'meta' => (object) [],
    'table' => 'acme_rows',
]));
file_put_contents(
    $state . '/posts/post/22222222-2222-5222-8222-222222222222--hello.md',
    Canon::post_file([
        'meta' => ['acme_ref' => 'none', 'acme_ref_map' => [1]],
        'slug' => 'hello',
        'status' => 'publish',
        'title' => 'Hello',
        'type' => 'post',
        'uuid' => '22222222-2222-5222-8222-222222222222',
    ], 'Body.')
);

// `1` and `3` are real post ids on any small site — which is what makes both
// columns a genuine collision, exactly as manifests/ninja-forms.json:24-25
// records happening live. `meta.acme_ref` holds no number at all, so the
// shipped bystander contributes no finding of its own and the post-meta
// attribution below cannot pass by accident.
WpStore::reset()->seedOptions(['home' => 'https://acme.test']);
$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello world!', 'post_status' => 'publish'],
    ['ID' => 3, 'post_type' => 'page', 'post_title' => 'Sample Page', 'post_status' => 'publish'],
]);

/**
 * A `duo-adapter-probe/v1` document naming `acme_rows.is_featured` BIT(1) —
 * the type WP-2.4 turns into a proposal. Self-hashed with the emitter's own
 * function, never a re-spelling of it.
 *
 * @return array<string,mixed>
 */
function acme_probe(): array {
    $document = [
        'authority' => false,
        'format' => AdapterProbe::FORMAT,
        'redaction' => AdapterProbe::REDACTION,
        'tables' => [
            'acme_rows' => [
                'columns' => [
                    'is_featured' => ['nullable' => false, 'type' => 'bit(1)'],
                    'related_id' => ['nullable' => false, 'type' => 'int(11)'],
                    'title' => ['nullable' => false, 'type' => 'varchar(200)'],
                ],
                'present' => true,
            ],
        ],
        'target' => ['agent_version' => DUO_AGENT_VERSION, 'spec_version' => DUO_SPEC_VERSION],
    ];
    $document['probe_hash'] = AdapterProbe::hash_document($document);
    return $document;
}

/**
 * The out-of-tree provenance record the ENGINE mints, not a paraphrase of it.
 *
 * `provenance_record()` is private because nothing outside the class may author
 * a provenance claim — which is exactly why a fixture that hand-typed one would
 * be asserting against its own prose. Reflection reaches the same factory
 * `scan_site_source()` uses live (`AdapterSources.php:1155-1170`) and
 * `from_snapshot()` substitutes on withdrawal (`:3956-3963`), so the `reason`
 * these two fixtures carry is the engine's sentence, including the withdrawal
 * clause, whatever that clause is today.
 *
 * @param array<string,mixed> $manifest
 * @return array<string,mixed>
 */
function acme_record(array $manifest, ?string $withdrawn): array {
    static $factory = null;
    $factory ??= new ReflectionMethod(AdapterSources::class, 'provenance_record');
    return (array) $factory->invoke(
        null,
        (string) $manifest['name'],
        AdapterSources::SITE_DIR . '/' . $manifest['name'] . '.json',
        $manifest,
        AdapterSources::SITE,
        null,
        $withdrawn
    );
}

/**
 * A policy over `$manifest` plus the shipped bystander. `$withdrawn` is null for
 * the shipped tier (no out-of-tree record at all) and otherwise selects which
 * uncertified state the out-of-tree record carries.
 */
function acme_policy(array $manifest, bool $outOfTree, ?string $withdrawn = null): Policy {
    $manifests = [$manifest, bystander_manifest()];
    $envelope = FrozenPolicy::envelope($manifests, FrozenPolicy::site($manifests));
    if ($outOfTree) {
        $envelope['adapter_sources']['out_of_tree'][(string) $manifest['name']]
            = acme_record($manifest, $withdrawn);
    }
    return FrozenPolicy::fromEnvelope($envelope);
}

/** @return list<string> "class path locator" per finding, in scan order. */
function finding_keys(array $findings): array {
    return array_map(
        static fn(array $f): string => $f['class'] . ' ' . $f['path'] . ' ' . $f['locator'],
        $findings
    );
}

function refusal_of(callable $fn): ?CommandRefusalException {
    try {
        $fn();
    } catch (CommandRefusalException $refusal) {
        return $refusal;
    }
    return null;
}

$plainRecord = acme_record(acme_manifest(), null);
$withdrawnRecord = acme_record(acme_manifest(), AdapterSources::WITHDRAWN_STALE_PLATFORM);

// ------------------------------------------- 0. the two records are one state

duo_check_same(
    ['reason'],
    array_keys(array_diff_assoc(
        array_map(static fn($v): string => Canon::encode($v), $withdrawnRecord),
        array_map(static fn($v): string => Canon::encode($v), $plainRecord)
    )),
    'a WITHDRAWN out-of-tree record differs from a never-certified one in `reason` and nothing else — so no '
    . 'predicate can tell them apart, and this gate must not pretend to'
);
duo_check(
    str_contains((string) $withdrawnRecord['reason'], 're-signed'),
    'the withdrawal record carries the engine\'s own re-signature clause, which is the remedy an operator whose '
    . 'certificate went stale actually needs'
);

// ------------------------------------------- 1. bare_id: the advisory/blocking pair

$shipped = acme_policy(acme_manifest(), false);
$shippedFindings = Lint::scan_tree($state, $shipped, LintEnvironment::live());
duo_check_same(
    [
        'bare_id posts/post/22222222-2222-5222-8222-222222222222--hello.md meta.acme_ref_map[0]',
        'bare_id tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json columns.is_featured',
        'bare_id tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json columns.related_id',
    ],
    finding_keys($shippedFindings),
    'the fixture produces three bare_id findings: two undeclared table columns and one undeclared post-meta list '
    . 'element'
);
duo_check_same(
    [],
    LintTrustGate::blocking($shippedFindings, $shipped, $state),
    'under a SHIPPED adapter not one of them blocks — a digest-bound, reviewed declaration is the tier this gate '
    . 'deliberately does not move (AGENTS.md rule 2)'
);
duo_check_same(
    null,
    refusal_of(static fn() => LintTrustGate::assert($shippedFindings, $shipped, $state)),
    'and the capture-path assertion returns, so the advisory warning below is still what capture appends'
);
duo_check_same(
    '3 suspicious unrewritten ref(s) in captured state — run directly on the target: '
    . '`wp duo lint --repo=/srv/site`',
    CapturePublicationWorkflow::lintWarning(count($shippedFindings), '/srv/site', null, true),
    'the advisory warning is byte-identical to the text that stood before this gate (AGENTS.md rule 8) — the '
    . 'shipped path captures exactly as it did'
);

$site = acme_policy(acme_manifest(), true);
$siteFindings = Lint::scan_tree($state, $site, LintEnvironment::live());
duo_check_same(
    finding_keys($shippedFindings),
    finding_keys($siteFindings),
    'the SCAN is identical across the tiers: the same bytes produce the same findings, so the only thing that '
    . 'moved is what the capture path does with them'
);
duo_check_same(
    ['class', 'path', 'locator', 'value', 'note', 'matches'],
    array_keys($siteFindings[0]),
    'and a finding still carries exactly the six keys `wp duo lint --format=json` emits verbatim (Cli.php:2919) — '
    . 'attribution is resolved in the gate precisely so those wire bytes do not move'
);

$refusal = refusal_of(static fn() => LintTrustGate::assert($siteFindings, $site, $state));
duo_check($refusal !== null, 'the identical findings under an UNCERTIFIED OUT-OF-TREE adapter refuse the capture');
duo_check_same(
    'uncertified_adapter_lint_findings',
    $refusal?->reasonCode,
    'the refusal carries a stable machine-readable reason code, not just prose'
);
duo_check_same(
    [
        'posts/post/22222222-2222-5222-8222-222222222222--hello.md meta.acme_ref_map[0]',
        'tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json columns.is_featured',
        'tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json columns.related_id',
    ],
    array_column($refusal?->diagnostics ?? [], 'surface'),
    'every blocked finding is NAMED by a locator an operator can go open — a refusal that only counted would be '
    . 'the warning it replaces with a worse exit code'
);
duo_check_same(
    ['acme-tables', 'acme-tables', 'acme-tables'],
    array_column($refusal?->diagnostics ?? [], 'adapter'),
    'and each names the adapter that declared the surface, so the operator knows whose manifest to go edit'
);
duo_check(
    str_contains((string) $refusal?->remediation, 'lint_ok'),
    'the public remediation names the reviewed escape, which is the only way out that is not a code change'
);
duo_check(
    str_contains((string) $refusal?->getMessage(), (string) $plainRecord['reason']),
    'the operator message quotes the adapter record\'s OWN reason — the engine\'s sentence about why nothing '
    . 'vouches for it, not a second one composed here'
);

// The post-meta locator is the one that could be mis-attributed: `acme_ref` is
// a prefix of `acme_ref_map`, and the two keys live in different manifests.
$postDiagnostic = array_values(array_filter(
    $refusal?->diagnostics ?? [],
    static fn(array $d): bool => str_contains((string) $d['surface'], 'meta.acme_ref_map')
));
duo_check_same(
    'acme-tables',
    $postDiagnostic[0]['adapter'] ?? null,
    'a `meta.acme_ref_map[0]` locator resolves to the manifest declaring `acme_ref_map`, not to the shipped '
    . 'bystander declaring the shorter `acme_ref` — longest declared key wins, against the file\'s own key set'
);

// ------------------------------------------- 2. the withdrawn certification

$withdrawnPolicy = acme_policy(acme_manifest(), true, AdapterSources::WITHDRAWN_STALE_PLATFORM);
duo_check_same(
    'uncertified',
    $withdrawnPolicy->adapter_sources()->certification_word('acme-tables'),
    'a WITHDRAWN certification resolves to the word `uncertified`: the claim is gone, and no projection may keep '
    . 'reporting the signature that no longer confers it'
);
duo_check(
    $withdrawnPolicy->adapter_sources()->is_uncertified_out_of_tree('acme-tables'),
    'so it sits at the risk tier exactly as an adapter nobody ever signed does'
);
$withdrawnFindings = Lint::scan_tree($state, $withdrawnPolicy, LintEnvironment::live());
$withdrawnRefusal = refusal_of(static fn() => LintTrustGate::assert($withdrawnFindings, $withdrawnPolicy, $state));
duo_check_same(
    'uncertified_adapter_lint_findings',
    $withdrawnRefusal?->reasonCode,
    'and its findings refuse the capture with the same reason code — an agent upgrade that withdrew a claim must '
    . 'not leave the gate it was holding open'
);
duo_check(
    str_contains((string) $withdrawnRefusal?->getMessage(), (string) $withdrawnRecord['reason']),
    'while the operator message carries the WITHDRAWAL sentence, so the remedy read is "re-sign against the '
    . 'current boundary" rather than "obtain a first certificate"'
);
duo_check(
    !str_contains((string) $withdrawnRefusal?->getMessage(), 'carries no reviewed certification evidence'),
    'and does NOT also carry the never-signed clause: two reasons printed at once is how an operator picks the '
    . 'wrong remedy'
);

// ------------------------------------------- 3. a proposal is not a review

$probe = acme_probe();
$shippedProposed = Lint::scan_tree($state, $shipped, LintEnvironment::live($probe));
duo_check_same(
    [
        'bare_id posts/post/22222222-2222-5222-8222-222222222222--hello.md meta.acme_ref_map[0]',
        'proposed_lint_ok tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json columns.is_featured',
        'bare_id tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json columns.related_id',
    ],
    finding_keys($shippedProposed),
    'WP-2.4 re-classes the BIT(1) column to `proposed_lint_ok` and leaves the int column and the post meta alone '
    . '— the type awareness this gate depends on is live in the fixture'
);
duo_check_same(
    [],
    LintTrustGate::blocking($shippedProposed, $shipped, $state),
    'under a shipped adapter the proposal is advisory, like everything else on that tier'
);

$siteProposed = Lint::scan_tree($state, $site, LintEnvironment::live($probe));
$proposedRefusal = refusal_of(static fn() => LintTrustGate::assert($siteProposed, $site, $state));
duo_check_same(
    'uncertified_adapter_lint_findings',
    $proposedRefusal?->reasonCode,
    'THE DECISION: a type-decidable BIT(1) column carrying a PROPOSED exemption still refuses at the risk tier. A '
    . 'proposal is evidence for a review, not the review — clearing the gate with one would let a fact about the '
    . 'third party\'s own database stand in for a reviewer'
);
duo_check_same(
    ['bare_id', 'proposed_lint_ok', 'bare_id'],
    array_column($proposedRefusal?->diagnostics ?? [], 'finding_class'),
    'and the refusal keeps each finding\'s class, so the operator can see which one the tool already proposed an '
    . 'exemption for and only has to ratify'
);

// ------------------------------------------- 4. lint_ok suppresses at both tiers

$reviewed = acme_manifest(true);
foreach ([
    'shipped' => acme_policy($reviewed, false),
    'uncertified out-of-tree' => acme_policy($reviewed, true),
] as $tier => $policy) {
    $findings = Lint::scan_tree($state, $policy, LintEnvironment::live($probe));
    duo_check_same(
        [
            'bare_id posts/post/22222222-2222-5222-8222-222222222222--hello.md meta.acme_ref_map[0]',
            'proposed_lint_ok tables/acme_rows/11111111-1111-5111-8111-111111111111--row.json columns.is_featured',
        ],
        finding_keys($findings),
        "a reviewed `lint_ok: true` on `related_id` removes THAT finding at the $tier tier — the exemption is "
        . 'read inside Lint, so the escape is the same act on both tiers'
    );
    $only = array_values(array_filter(
        $findings,
        static fn(array $f): bool => str_contains($f['locator'], 'related_id')
    ));
    duo_check_same([], $only, "and nothing about `related_id` survives to be judged at the $tier tier");
}

// The exemption is per-COLUMN, not per-capture: the siblings still block.
$reviewedSite = acme_policy($reviewed, true);
$reviewedFindings = Lint::scan_tree($state, $reviewedSite, LintEnvironment::live($probe));
duo_check_same(
    'uncertified_adapter_lint_findings',
    refusal_of(static fn() => LintTrustGate::assert($reviewedFindings, $reviewedSite, $state))?->reasonCode,
    'writing one exemption does not clear the capture: the columns nobody reviewed still refuse, which is what '
    . 'keeps `lint_ok` an argument about one declaration rather than a mute button'
);

// ------------------------------------------- 5. the gate is ON the capture path

$workflow = (string) file_get_contents($root . '/agent/src/Capture/CapturePublicationWorkflow.php');
duo_check(
    preg_match(
        '/\$lint = Lint::scan_tree\(.*?\n\s+if \(\$lint\) \{.*?LintTrustGate::assert\(.*?'
        . '\$candidate\[.warnings.\]\[\] = self::lintWarning\(/s',
        $workflow
    ) === 1,
    'capture calls the gate inside the same `if ($lint)` block and BEFORE it appends the advisory warning — a '
    . 'gate reached after the warning would be a gate the advisory bytes depend on'
);
duo_check(
    str_contains($workflow, "require_once __DIR__ . '/../Review/LintTrustGate.php';"),
    'and requires it explicitly, like every other file under agent/src (AGENTS.md rule 1)'
);

duo_check_summary('regress_lint_trust_tier_gate');
