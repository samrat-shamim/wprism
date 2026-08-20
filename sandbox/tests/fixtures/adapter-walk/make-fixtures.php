<?php
declare(strict_types=1);

/**
 * Regenerate the recorded documents `sandbox/tests/grind/grind_adapter_walk.sh
 * --self-check` runs its pure jq/bash helpers against
 * (docs/proposals/round-3-adapter-walk.md).
 *
 * Usage: php sandbox/tests/fixtures/adapter-walk/make-fixtures.php [<out-dir>]
 *        (default out-dir: this directory)
 *
 * ## Why the fixtures are GENERATED, and where that stops
 *
 * `sandbox/tests/fixtures/mup/make-fixtures.php` established the rule and the
 * reason: a hand-written fixture records what its author BELIEVED a shipped
 * document looks like, so a jq path that silently matches nothing keeps
 * passing after the real shape moves. Every fixture here that a shipped
 * builder can produce is therefore produced by it and validated by the
 * shipped validator before it is written:
 *
 *   AssessReport::build()        -> ContractProposal::validateAssessReport()
 *   AssessRenderer::render()     (the human views, from the report above)
 *   Init::render()               (the init proposal views)
 *   ClassificationBatch::template()
 *   AuthorizationPlan::build()   -> AuthorizationPlan::validate()
 *   RecoveryClaim::build()       -> RecoveryClaim::validate()
 *   JourneyOracle::report()
 *   CheckpointCatalog::fromStatus()
 *
 * This walk is different from the MUP grind in one way that matters here: it
 * asserts a CONTRACT that is being built in parallel, so several of its
 * documents are shapes no shipped builder can mint yet — the `site_signed`
 * catalog word, `shadowed_by_site`, `Site-certified` with a named principal,
 * the `certify adapter` gap action, `logical_name` on an undeclared-table row,
 * and the `UNMANAGED PLUGIN` init advisory. Those are constructed here from
 * the contract text, each one carrying a `_provenance` note naming the section
 * it comes from, and — where the shipped validator can be asked at all — this
 * script ASKS IT and prints the refusal rather than hiding it. That printed
 * note is the honest status line: while it appears, the product has not landed
 * that half of the contract yet; when it stops appearing, the fixture became a
 * validated one and nothing else has to change.
 *
 * ## Why the FAIL fixtures are hand-mutated
 *
 * A negative fixture must be a document the shipped builder cannot produce —
 * that is the point of it. Each is a PASS fixture with exactly one edit, named
 * in its filename, so `--self-check` proves each helper is non-vacuous: a
 * helper that never fails proves nothing about the run that trusts it.
 *
 * Two of the FAIL fixtures are special and worth naming: `coverage.
 * fail-no-logical-name.json` and `assess-human.no-undeclared-table-line.txt`
 * are TODAY'S output, byte for byte (round-3-adapter-walk.md §3.7 bugs 1 and
 * 2). They are recorded as failures because the contract says they are.
 *
 * Plugin slugs appear here on purpose. This is evidence for a walk whose
 * subject is WPForms Lite and a walk-owned fixture plugin; the
 * engine-adapter boundary forbids a plugin slug in `cli/src/Assess`,
 * `cli/src/Contract` and `agent/src/Assess`, not in a grind's fixtures.
 *
 * ## Read this before you re-run it
 *
 * This script and the fixtures beside it have DIVERGED, and a wholesale re-run
 * fails `grind_adapter_walk.sh --self-check` (4 checks) rather than refreshing
 * it. Three drifts, none of them about what a fixture records:
 *
 *   - the `$projection` closure emits `principal` / `trust_root`; the committed
 *     fixtures and the grind's own readers use `certification_principal` /
 *     `certification_trust_root`;
 *   - `AssessRenderer` has since grown its own undeclared-table line, so the
 *     hand-insertion below now emits a second one and `assess-human.clean.txt`
 *     ends up with two;
 *   - `Init::render()` reshaped the advisory block, so `init-allow-unmanaged.txt`
 *     comes back in a layout the §3.4 reader does not recognise.
 *
 * Until those are reconciled, apply a targeted delta to the fixture bytes and
 * re-run `--self-check`, which is what the evidence-chain teardown did: it
 * touched the `evidence` block, the digests binding it, and the deferred
 * `AdapterRegistry::report()` row, and left every other byte alone.
 */

$root = dirname(__DIR__, 4);

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/cli/src/Assess/AssessReport.php';
require_once $root . '/cli/src/Assess/AssessRenderer.php';
require_once $root . '/cli/src/Contract/ApplicationContract.php';
require_once $root . '/cli/src/Contract/ContractProposal.php';
require_once $root . '/cli/src/Onboarding/ClassificationBatch.php';
require_once $root . '/cli/src/Onboarding/Init.php';
require_once $root . '/cli/src/Recovery/CheckpointCatalog.php';
require_once $root . '/cli/src/Recovery/RecoveryClaim.php';
require_once $root . '/cli/src/Recovery/RecoveryProfileSelection.php';
require_once $root . '/cli/src/Release/AuthorizationPlan.php';
require_once $root . '/cli/src/Release/JourneyOracle.php';

use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\AssessRenderer;
use Duo\Orchestrator\AssessReport;
use Duo\Orchestrator\AuthorizationPlan;
use Duo\Orchestrator\CheckpointCatalog;
use Duo\Orchestrator\ClassificationBatch;
use Duo\Orchestrator\ContractProposal;
use Duo\Orchestrator\Init;
use Duo\Orchestrator\JourneyOracle;
use Duo\Orchestrator\RecoveryClaim;
use Duo\Orchestrator\RecoveryProfileSelection;

/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
array_shift($argvList);
$out = $argvList[0] ?? __DIR__;
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "make-fixtures: cannot create $out\n");
    exit(2);
}
$out = (string) (realpath($out) ?: $out);

$written = [];
$notes = [];

function walk_write(string $path, string $bytes): void {
    global $written;
    if (file_put_contents($path, $bytes) === false) {
        fwrite(STDERR, "make-fixtures: cannot write $path\n");
        exit(2);
    }
    $written[] = basename($path);
}

function walk_json(string $path, array $document): void {
    walk_write($path, Canon::encode($document));
}

/** @return array<string,mixed> */
function walk_read(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "make-fixtures: fixture is not JSON: $path\n");
        exit(2);
    }

    return $decoded;
}

/**
 * Ask the shipped validator about a contract-new document and RECORD the
 * answer instead of choosing between "skip the check" and "fail the build".
 *
 * A contract this repository is mid-way through building necessarily has
 * documents its own validators refuse; suppressing that would make the
 * fixture look reviewed, and failing on it would make the generator
 * unrunnable until the last product change lands. Printing the exact refusal
 * is the third option, and it is self-clearing: the note disappears the day
 * the product emits the word.
 */
function walk_validate_optional(string $label, callable $validator): void {
    global $notes;
    try {
        $validator();
    } catch (\Throwable $e) {
        $notes[] = "$label is not yet accepted by the shipped validator: " . $e->getMessage();
    }
}

$now = '2026-08-17T09:14:02Z';
$authorityKeyId = 'acme-ops-2026';

// ---------------------------------------------------------------- projections
/**
 * One operation projection in ProjectionVocabulary's own key set, plus the two
 * keys §3.2 adds to a certified claim's projection (`principal`,
 * `trust_root`). The extra keys are legal by construction: the shipped
 * validator checks a REQUIRED FLOOR on a projection and lets assess carry
 * additive display keys beside it (ContractProposal::validateReportSurfaces()
 * says so in its own comment), which is exactly the seam §3.2's two new
 * columns land in.
 */
$projection = static function (
    string $stateClass,
    string $handling,
    string $readiness,
    string $provenance,
    string $containment,
    string $recovery,
    string $meaning,
    string $gapAction,
    ?string $principal = null,
    ?string $trustRoot = null
): array {
    return [
        'certification_provenance' => $provenance,
        'conditions' => [],
        'effect_containment' => $containment,
        'effect_containment_basis' => $containment === 'prevented'
            ? 'no WordPress hooks fire in the apply window'
            : 'unknown — not enforced in this profile',
        'effect_recovery_semantics' => $recovery,
        'gap_action' => $gapAction,
        'handling' => $handling,
        'meaning' => $meaning,
        'principal' => $principal,
        'readiness' => $readiness,
        'remediation' => null,
        'state_class' => $stateClass,
        'trust_root' => $trustRoot,
    ];
};

// ------------------------------------------------------- S1: no adapter at all
// The projection an unmanaged published plugin produces, per §3.6: a
// `plugin:<slug>` surface for every active plugin with no owning adapter, the
// undeclared tables it created, and the two WooCommerce rows that prove the
// rest of the site is still normally managed while all this is true.
$s1Surfaces = [
    [
        'decided_by' => 'platform-default',
        'handling' => 'manage',
        'id' => 'post_type:product',
        'kind' => 'post_type',
        'label' => 'post_type:product',
        'meaning' => 'authored catalog content Duo manages end to end',
        'next_action' => 'nothing — supported',
        'operations' => [
            'release' => $projection(
                'authored',
                'manage',
                'Ready',
                'Platform-certified',
                'prevented',
                'provider-state restorable',
                'authored catalog content Duo manages end to end',
                'nothing — supported'
            ),
        ],
        'state_class' => 'authored',
    ],
    [
        'decided_by' => 'platform-default',
        'handling' => 'preserve local',
        'id' => 'post_type:shop_order',
        'kind' => 'post_type',
        'label' => 'post_type:shop_order',
        'meaning' => 'live operational state is never copied',
        'next_action' => 'nothing — supported',
        'operations' => [
            'release' => $projection(
                'runtime',
                'preserve local',
                'Unsupported',
                'Platform-certified',
                'prevented',
                'not applicable',
                'live operational state is never copied',
                'nothing — supported'
            ),
        ],
        'state_class' => 'runtime',
    ],
    [
        // §3.6's new surface kind. `install adapter` is its next action because
        // the surface IS a plugin: the smallest safe thing an operator can do
        // about an unmanaged plugin is give Duo an adapter for it.
        'decided_by' => 'unresolved',
        'handling' => 'block',
        'id' => 'plugin:wpforms-lite',
        'kind' => 'plugin',
        'label' => 'plugin:wpforms-lite',
        'meaning' => 'no installed adapter declares this active plugin',
        'next_action' => 'install adapter',
        'operations' => [
            'release' => $projection(
                'unclassified',
                'block',
                'Not qualified',
                'Uncertified',
                'unknown',
                'unknown',
                'no installed adapter declares this active plugin',
                'install adapter'
            ),
        ],
        'state_class' => 'unclassified',
    ],
    [
        // §3.6's gap rule for an unclassified surface whose containment is
        // unknown: `install adapter` when the surface has a probable owning
        // plugin (coverage attributes wpforms_* to wpforms-lite), and never
        // `qualify in rehearsal`, which rehearsal itself says it cannot do.
        'decided_by' => 'unresolved',
        'handling' => 'block',
        'id' => 'table:wpforms_tasks_meta',
        'kind' => 'table',
        'label' => 'table:wpforms_tasks_meta',
        'meaning' => 'no installed adapter can see this table',
        'next_action' => 'install adapter',
        'operations' => [
            'release' => $projection(
                'unclassified',
                'block',
                'Not qualified',
                'Uncertified',
                'unknown',
                'unknown',
                'no installed adapter can see this table',
                'install adapter'
            ),
        ],
        'state_class' => 'unclassified',
    ],
    [
        'decided_by' => 'unresolved',
        'handling' => 'block',
        'id' => 'table:wpforms_logs',
        'kind' => 'table',
        'label' => 'table:wpforms_logs',
        'meaning' => 'no installed adapter can see this table',
        'next_action' => 'install adapter',
        'operations' => [
            'release' => $projection(
                'unclassified',
                'block',
                'Not qualified',
                'Uncertified',
                'unknown',
                'unknown',
                'no installed adapter can see this table',
                'install adapter'
            ),
        ],
        'state_class' => 'unclassified',
    ],
    [
        'decided_by' => 'unresolved',
        'handling' => 'block',
        'id' => 'table:wpforms_payments',
        'kind' => 'table',
        'label' => 'table:wpforms_payments',
        'meaning' => 'no installed adapter can see this table',
        'next_action' => 'install adapter',
        'operations' => [
            'release' => $projection(
                'unclassified',
                'block',
                'Not qualified',
                'Uncertified',
                'unknown',
                'unknown',
                'no installed adapter can see this table',
                'install adapter'
            ),
        ],
        'state_class' => 'unclassified',
    ],
];

$target = [
    'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
    'home' => 'http://localhost:9500',
    'php' => '8.3.33',
    'site_mode' => 'single-site',
    'siteurl' => 'http://localhost:9500',
    'wordpress' => '6.8.2',
];
$authority = ['transport' => 'docker', 'read_only' => true, 'repo' => '/siterepo'];
$evidence = [
    'generated_from' => [
        'dispositions_sha256' => 'sha256:' . str_repeat('9', 64),
    ],
    'registry_sha256' => 'sha256:' . str_repeat('8f', 32),
];
$s1Unknown = [
    'invisible_names_count' => 37,
    // Exactly what the coverage document below reports: one invisible option
    // prefix and the same three undeclared tables. A sample naming a table the
    // surface rows do not carry would make the walk's own two readers disagree
    // about one site.
    'names_sample' => [
        'option-prefix:wpforms_',
        'table:wpforms_logs',
        'table:wpforms_payments',
        'table:wpforms_tasks_meta',
    ],
    'pending_count' => 0,
];

$s1 = AssessReport::build('awalk1', $now, $target, $authority, $s1Surfaces, $s1Unknown, $evidence);
walk_json("$out/assess-report.s1.json", $s1);

// One edit: the unmanaged plugin row loses its gap action. §4's S1 row requires
// every unclassified surface to carry one, so the document check must reject it.
$noAction = $s1;
$noAction['surfaces'][2]['next_action'] = 'nothing — supported';
walk_json("$out/assess-report.fail-unclassified-no-next-action.json", $noAction);

// One edit: the option prefix disappears from the unknown sample. §4's S1 row
// requires `option-prefix:wpforms_` to be named, so the names check must fail.
$noPrefix = $s1;
$noPrefix['unknown']['names_sample'] = array_values(array_filter(
    $noPrefix['unknown']['names_sample'],
    static fn(string $name): bool => $name !== 'option-prefix:wpforms_'
));
walk_json("$out/assess-report.fail-no-option-prefix.json", $noPrefix);

// ------------------------------------------- S2/S3/S4: operator-certified
// The projection §3.2/§3.6 describe once a site-signed adapter governs the
// surface: `Site-certified`, with the principal named and the trust root
// `site`. Three surfaces because the walk certifies three different things —
// a published plugin's authored CPT (S2), a custom plugin's (S3), and a
// shipped adapter's own surface after a site override (S4).
$siteCertifiedSurfaces = [
    [
        'decided_by' => 'operator',
        'handling' => 'manage',
        'id' => 'post_type:wpforms',
        'kind' => 'post_type',
        'label' => 'post_type:wpforms',
        'meaning' => 'form definitions the operator authors and Duo manages end to end',
        'next_action' => 'nothing — supported',
        'operations' => [
            'release' => $projection(
                'authored',
                'manage',
                'Ready',
                'Site-certified',
                'prevented',
                'provider-state restorable',
                'form definitions the operator authors and Duo manages end to end',
                'nothing — supported',
                $authorityKeyId,
                'site'
            ),
        ],
        'state_class' => 'authored',
    ],
    [
        'decided_by' => 'operator',
        'handling' => 'manage',
        'id' => 'post_type:acme_item',
        'kind' => 'post_type',
        'label' => 'post_type:acme_item',
        'meaning' => 'catalog items the operator authors and Duo manages end to end',
        'next_action' => 'nothing — supported',
        'operations' => [
            'release' => $projection(
                'authored',
                'manage',
                'Ready',
                'Site-certified',
                'prevented',
                'provider-state restorable',
                'catalog items the operator authors and Duo manages end to end',
                'nothing — supported',
                $authorityKeyId,
                'site'
            ),
        ],
        'state_class' => 'authored',
    ],
    [
        // S4: the SAME surface a shipped adapter used to govern, now governed
        // by the site copy. §3.3's rule is that an overridden name carries the
        // site's words and never the platform's, so this row is the one that
        // proves the override reached the projection rather than only the
        // catalog.
        'decided_by' => 'operator',
        'handling' => 'manage',
        'id' => 'post_type:product',
        'kind' => 'post_type',
        'label' => 'post_type:product',
        'meaning' => 'authored catalog content Duo manages end to end',
        'next_action' => 'nothing — supported',
        'operations' => [
            'release' => $projection(
                'authored',
                'manage',
                'Ready',
                'Site-certified',
                'prevented',
                'provider-state restorable',
                'authored catalog content Duo manages end to end',
                'nothing — supported',
                $authorityKeyId,
                'site'
            ),
        ],
        'state_class' => 'authored',
    ],
];
$siteCertified = AssessReport::build(
    'awalk1',
    $now,
    $target,
    $authority,
    $siteCertifiedSurfaces,
    ['invisible_names_count' => 0, 'names_sample' => [], 'pending_count' => 0],
    $evidence
);
walk_json("$out/assess-report.site-certified.json", $siteCertified);

// One edit: the certified word goes back to the platform's. The walk's
// Site-certified reader must not smooth that into a pass, because
// `Platform-certified` on an operator-signed adapter would be the product
// claiming a Duo endorsement it never made (§2).
$platformCertified = $siteCertified;
$platformCertified['surfaces'][0]['operations']['release']['certification_provenance'] = 'Platform-certified';
$platformCertified['surfaces'][0]['operations']['release']['principal'] = null;
$platformCertified['surfaces'][0]['operations']['release']['trust_root'] = 'platform';
walk_json("$out/assess-report.fail-platform-certified.json", $platformCertified);

// ----------------------------------------- the `certify adapter` gap action
// §3.6's new closed-set word: readiness `Not qualified` caused by
// `adapter_source_uncertified` (the adapter EXISTS; sign or re-pin it) must
// not read `install adapter`, which is the thing the operator just did.
$certifyRow = [
    'decided_by' => 'unresolved',
    'handling' => 'block',
    'id' => 'post_type:wpforms',
    'kind' => 'post_type',
    'label' => 'post_type:wpforms',
    'meaning' => 'an installed but uncertified adapter governs this surface',
    'next_action' => 'certify adapter',
    'operations' => [
        'release' => $projection(
            'authored',
            'block',
            'Not qualified',
            'Uncertified',
            'unknown',
            'unknown',
            'an installed but uncertified adapter governs this surface',
            'certify adapter'
        ),
    ],
    'state_class' => 'authored',
];
$certifyReport = $siteCertified;
$certifyReport['surfaces'] = [$certifyRow];
$certifyReport['assess_digest'] = ContractProposal::assessDigest($certifyReport);
walk_validate_optional(
    'the `certify adapter` gap action (§3.6)',
    static fn() => ContractProposal::validateAssessReport($certifyReport)
);
walk_json("$out/assess-report.certify-adapter.json", $certifyReport);

// One edit: the same blocked row falls back to `install adapter`. That is the
// word §3.6 exists to stop being emitted here, so the reader must tell them
// apart rather than accepting any non-empty action.
$certifyFallback = $certifyReport;
$certifyFallback['surfaces'][0]['next_action'] = 'install adapter';
$certifyFallback['surfaces'][0]['operations']['release']['gap_action'] = 'install adapter';
$certifyFallback['assess_digest'] = ContractProposal::assessDigest($certifyFallback);
walk_json("$out/assess-report.fail-install-adapter-for-uncertified.json", $certifyFallback);

// ---------------------------------------------------------------- coverage
// §3.7 bug 1: `Coverage::tables_report()` drops `logical_name` from the
// published rows, so assess's `table:` identities — which are built from
// `logical_name` (cli/src/Assess/SurfaceCatalog.php:326) — can never appear on
// a live site. The PASS fixture is the fixed shape; the FAIL fixture is
// today's, byte for byte.
$coverage = [
    'format' => 'duo-coverage-report/v1',
    'options' => [
        'captured' => 214,
        'invisible_groups' => [
            ['count' => 37, 'prefix' => 'wpforms_', 'probable_owner' => 'wpforms-lite'],
        ],
        'invisible_other' => 37,
        'invisible_total' => 41,
        'invisible_transient' => 4,
        'pending' => 0,
        'total' => 255,
    ],
    'tables' => [
        'core_total' => 12,
        'declared_total' => 31,
        'live_total' => 48,
        'undeclared' => [
            [
                'logical_name' => 'wpforms_logs',
                'probable_owner' => 'wpforms-lite',
                'row_count' => 0,
                'table' => 'wp_wpforms_logs',
            ],
            [
                'logical_name' => 'wpforms_payments',
                'probable_owner' => 'wpforms-lite',
                'row_count' => 0,
                'table' => 'wp_wpforms_payments',
            ],
            [
                'logical_name' => 'wpforms_tasks_meta',
                'probable_owner' => 'wpforms-lite',
                'row_count' => 3,
                'table' => 'wp_wpforms_tasks_meta',
            ],
        ],
        'undeclared_total' => 3,
    ],
];
walk_json("$out/coverage.pass.json", $coverage);

$coverageToday = $coverage;
foreach ($coverageToday['tables']['undeclared'] as $index => $row) {
    unset($row['logical_name']);
    $coverageToday['tables']['undeclared'][$index] = $row;
}
walk_json("$out/coverage.fail-no-logical-name.json", $coverageToday);

// -------------------------------------------------------- adapter catalog
// `duo-adapter-catalog/v2` rows as §3.2 leaves them: the existing keys plus
// `trust_root` and `principal` on every row, and the new `site_signed`
// certification word. Hand-authored from the contract — the catalog is
// produced by a live survey (AdapterSources::survey()), which needs a target,
// so no host-side builder can mint one here.
$catalogDeferred = [[
    'check' => 'AdapterRegistry::report()',
    'status' => 'deferred',
    'surface' => 'dispositions.json against a target',
    'why' => 'certification is a reviewed claim evaluated against one target: whether the plugin the claim is '
        . 'authored for is installed, active, and inside the reviewed version window',
]];
$catalogSources = [
    [
        'note' => 'the agent manifest library that deploys with the agent',
        'path' => '/duo-manifests',
        'scanned' => true,
        'source' => 'shipped',
    ],
    [
        'note' => "the site repository's own adapters/ directory",
        'path' => '/siterepo/adapters',
        'scanned' => true,
        'source' => 'site',
    ],
    [
        'note' => 'an active plugin\'s bundled duo-adapter.json; not reachable from a WordPress-free host process',
        'path' => null,
        'scanned' => false,
        'source' => 'plugin',
    ],
];
$catalogRow = static function (
    string $name,
    string $source,
    string $certification,
    ?string $trustRoot,
    ?string $principal,
    string $path
): array {
    return [
        'certification' => $certification,
        'disposition_status' => $source === 'shipped' ? 'certified' : null,
        'grammar' => ['message' => null, 'status' => 'ok'],
        'name' => $name,
        'path' => $path,
        'principal' => $principal,
        'sha256' => hash('sha256', $name),
        'source' => $source,
        'tier_basis' => 'declarative-only: no interpreter, regenerator or manifest-sourced provider',
        'trust_root' => $trustRoot,
        'trust_tier' => 'declarative',
    ];
};

$siteSignedCatalog = [
    '_provenance' => 'round-3-adapter-walk.md §3.2: `site_signed` is a valid certificate under a key in the '
        . "site's adapters/authorities.json with an exact {name,source,digest} pin; every catalog row "
        . 'additionally exposes trust_root and principal. Hand-authored because the catalog is produced by '
        . 'AdapterSources::survey() against a live repository and no host-side builder can mint one offline.',
    'adapters' => [
        $catalogRow('core', 'shipped', 'registry', 'platform', null, '/duo-manifests/core.json'),
        $catalogRow('woocommerce', 'shipped', 'registry', 'platform', null, '/duo-manifests/woocommerce.json'),
        $catalogRow('wpforms', 'site', 'site_signed', 'site', $authorityKeyId, '/siterepo/adapters/wpforms.json'),
    ],
    'command' => 'list',
    'deferred' => $catalogDeferred,
    'format' => 'duo-adapter-catalog/v2',
    'manifests_dir' => '/duo-manifests',
    'not_installed' => [],
    'refusals' => [],
    'repo' => '/siterepo',
    'sources' => $catalogSources,
    'spec_version' => 2,
    'status' => 'ok',
];
walk_json("$out/adapter-catalog.site-signed.json", $siteSignedCatalog);

// One edit: the certificate is gone. `uncertified` is the word §1 says an
// installed-but-unsigned site adapter carries, and the reader must report it
// rather than smoothing an unsigned adapter into a signed one.
$uncertifiedCatalog = $siteSignedCatalog;
$uncertifiedCatalog['adapters'][2]['certification'] = 'uncertified';
$uncertifiedCatalog['adapters'][2]['principal'] = null;
$uncertifiedCatalog['adapters'][2]['trust_root'] = null;
walk_json("$out/adapter-catalog.uncertified.json", $uncertifiedCatalog);

// S4: the shipped copy is shadowed by an explicit site pin (§3.3). The site
// copy answers to the name and carries the SITE's certification words; the
// shipped copy becomes an installed-but-not-loaded row whose winner is the
// site source. `shadowed_by_site` is the new reason code — today's vocabulary
// has only `shadowed` (agent/src/Adapter/AdapterSources.php:241), and it means
// the opposite direction of precedence.
$shadowedCatalog = $siteSignedCatalog;
$shadowedCatalog['_provenance'] = 'round-3-adapter-walk.md §3.3: an explicit {name,source:"site",digest} pin '
    . 'selects the site copy for a shipped name; the shipped copy is reported as `shadowed_by_site` on every '
    . 'catalog row and in `duo adapter list`, and the site copy carries the site certification words.';
$shadowedCatalog['adapters'] = [
    $catalogRow('core', 'shipped', 'registry', 'platform', null, '/duo-manifests/core.json'),
    $catalogRow('woocommerce', 'site', 'site_signed', 'site', $authorityKeyId, '/siterepo/adapters/woocommerce.json'),
];
$shadowedCatalog['not_installed'] = [[
    'message' => "adapter 'woocommerce' is shipped at /duo-manifests/woocommerce.json, and the site repository "
        . 'carries an explicit {name,source:"site",digest} pin for that name, so the site copy answers to it '
        . 'and the shipped copy is not loaded',
    'name' => 'woocommerce',
    'path' => '/duo-manifests/woocommerce.json',
    'reason_code' => 'shadowed_by_site',
    'source' => 'shipped',
    'winner' => ['path' => '/siterepo/adapters/woocommerce.json', 'source' => 'site'],
]];
walk_json("$out/adapter-catalog.shadowed.json", $shadowedCatalog);

// --------------------------------------------------------- adapter survey
// `wp duo adapter-survey` is the same document produced ON the target, which
// is the only place the plugin source exists (a WordPress-free host process
// cannot read WP_PLUGIN_DIR). S3 reads the bundled adapter's row here.
$bundledSurvey = [
    '_provenance' => 'agent/src/Command/Cli.php::adapter_survey() builds duo-adapter-catalog/v2 from '
        . "AdapterSources::survey(\$repo) on the target. A bundled adapter's certification word is "
        . '`uncertified` ALWAYS (certification binds source "site" and adapters/<name>.json inside the signed '
        . 'statement, so no certificate can name a bundled one); the promotion path is the remediation '
        . 'AdapterSources::diagnostics() carries for the plugin source.',
    'adapters' => [
        $catalogRow('core', 'shipped', 'registry', 'platform', null, '/duo-manifests/core.json'),
        $catalogRow('woocommerce', 'shipped', 'registry', 'platform', null, '/duo-manifests/woocommerce.json'),
        [
            'certification' => 'uncertified',
            'disposition_status' => null,
            'grammar' => ['message' => null, 'status' => 'ok'],
            'name' => 'acme-catalog',
            'path' => '/var/www/html/wp-content/plugins/acme-catalog/duo-adapter.json',
            'principal' => null,
            'remediation' => 'install this adapter as a repository package at adapters/acme-catalog.json, '
                . 'obtain a certificate signed by an authority this agent trusts at '
                . 'adapters/certifications/acme-catalog.json, then run `wp duo manifest-pin --repo=... '
                . '--name=acme-catalog` and commit the emitted {name,source:"site",digest} pin. The site copy '
                . 'wins by precedence and the bundled copy reports as not installed; the plugin stays active '
                . 'throughout',
            'sha256' => hash('sha256', 'acme-catalog'),
            'source' => 'plugin',
            'tier_basis' => 'declarative-only: no interpreter, regenerator or manifest-sourced provider',
            'trust_root' => null,
            'trust_tier' => 'declarative',
        ],
    ],
    'command' => 'survey',
    'deferred' => $catalogDeferred,
    'format' => 'duo-adapter-catalog/v2',
    'manifests_dir' => '/duo-manifests',
    'not_installed' => [],
    'refusals' => [],
    'repo' => '/siterepo',
    'sources' => [
        $catalogSources[0],
        $catalogSources[1],
        [
            'note' => "each active plugin's own duo-adapter.json",
            'path' => '/var/www/html/wp-content/plugins',
            'scanned' => true,
            'source' => 'plugin',
        ],
    ],
    'spec_version' => 2,
    'status' => 'ok',
    'summary' => [
        'adapters' => 3,
        'grammar_error' => 0,
        'grammar_unjudged' => 0,
        'not_installed' => 0,
        'plugin' => 1,
        'refusals' => 0,
        'shipped' => 2,
        'site' => 0,
    ],
];
walk_json("$out/adapter-survey.bundled.json", $bundledSurvey);

// One edit: the bundled row claims a signed word. §"Adapters a plugin bundles"
// makes that structurally impossible, so a survey reporting it is a defect the
// walk must catch rather than accept as good news.
$bundledSigned = $bundledSurvey;
$bundledSigned['adapters'][2]['certification'] = 'site_signed';
walk_json("$out/adapter-survey.fail-bundled-signed.json", $bundledSigned);

// ------------------------------------------------------ classification batch
// S1's queue: the wpforms CPT is out of policy scope, so capture refuses
// `incomplete_policy_scope` and the queue offers the `scope:post_type:wpforms`
// decision. Built by the shipped template so the walk's jq edit is made
// against the real artifact shape, digest binding included.
$pendingItems = [
    [
        'evidence' => ['entities' => 2, 'journal' => ['n' => 0]],
        'key' => 'post_type:wpforms',
        'proposal' => null,
        'section' => 'scope',
    ],
];
$batch = ClassificationBatch::template('awalk1', $pendingItems);
walk_write("$out/classification-batch.pending.json", ClassificationBatch::encode($batch));

$decided = $batch;
$decided['decisions'][0]['class'] = 'runtime';
walk_write("$out/classification-batch.decided.json", ClassificationBatch::encode($decided));

// One edit: the decision names a key the queue does not carry. The batch is
// digest-bound to its queue for exactly this reason, and the walk's own reader
// must not report a decision the product would refuse as applied.
$wrongKey = $decided;
$wrongKey['decisions'][0]['key'] = 'post_type:wpforms_log';
walk_write("$out/classification-batch.fail-unbound-key.json", ClassificationBatch::encode($wrongKey));

// ------------------------------------------------------------ recovery claim
$declaredEffects = [[
    'containment' => 'live',
    'effect_recovery_semantics' => 'provider-state restorable',
    'id' => 'code-lifecycle-window',
    'restored_by' => 'code release',
]];
$operatorClaim = RecoveryClaim::build([
    'additional_does_not_restore' => [],
    'covered_resources' => ['database checkpoint /siterepo/.duo/checkpoints/promote-1.sql'],
    'declared_external_effects' => $declaredEffects,
    'profile' => RecoveryClaim::OPERATOR_DIRECTED,
]);
RecoveryClaim::validate($operatorClaim);
walk_json("$out/recovery-claim.operator-directed.json", $operatorClaim);

$noneClaim = RecoveryClaim::build([
    'additional_does_not_restore' => [],
    'covered_resources' => [],
    'declared_external_effects' => $declaredEffects,
    'profile' => RecoveryClaim::NONE,
]);
RecoveryClaim::validate($noneClaim);
walk_json("$out/recovery-claim.none.json", $noneClaim);

$emptyClaim = $operatorClaim;
$emptyClaim['does_not_restore'] = [];
walk_json("$out/recovery-claim.fail-empty-does-not-restore.json", $emptyClaim);

// -------------------------------------------------------- authorization plan
$release = dirname(__DIR__) . '/release';
$contract = ApplicationContract::withDigest(walk_read("$release/contract-declared-unbound.json"));
ApplicationContract::validate($contract);
$selection = RecoveryProfileSelection::decide(
    [
        'automatic' => false,
        'profile' => RecoveryClaim::OPERATOR_DIRECTED,
        'reason' => 'this transport carries no rollback authority runtime, so promote uses the operator-directed '
            . 'artifact-bound lease and database checkpoint',
        'scoped' => false,
        'status' => [],
    ],
    [
        'checkpoint_at' => $now,
        'covered_resources' => ['database checkpoint'],
        'declared_external_effects' => $contract['declarations']['external_effects'],
    ]
);
if (($selection['refusal'] ?? null) !== null) {
    fwrite(STDERR, "make-fixtures: the operator-directed selection refused unexpectedly\n");
    exit(2);
}
$plan = AuthorizationPlan::build([
    'authority' => [['kind' => 'business_owner', 'reason' => 'forms visible to customers change']],
    'capabilities' => [[
        'certification_provenance' => 'Site-certified',
        'conditions' => [],
        'name' => 'wpforms',
        'operation' => 'promote',
        'readiness' => 'Ready',
    ]],
    'contract' => $contract,
    'deletion_semantics' => [],
    'environment' => 'awalk2',
    'flags' => ['plan_only' => true, 'with_deletes' => false],
    'frozen_at' => $now,
    'plan' => walk_read("$release/plan-clean.json"),
    'projection' => walk_read("$release/projection-ready.json"),
    'recovery' => $selection,
    'scope' => [
        'code' => ['lifecycle_phases' => ['retire', 'activate', 'verify'], 'plugins_changed' => 0, 'themes_changed' => 0],
        'surfaces' => ['post_type:wpforms', 'post_type:page'],
    ],
    'target' => walk_read("$release/target-facts.json"),
]);
AuthorizationPlan::validate($plan);
walk_json("$out/authorization-plan.pass.json", $plan);

$noContract = $plan;
$noContract['contract_digest'] = null;
walk_json("$out/authorization-plan.fail-no-contract-digest.json", $noContract);

$noReason = $plan;
$noReason['recovery_profile']['selected_because'] = '';
walk_json("$out/authorization-plan.fail-no-recovery-reason.json", $noReason);

// ---------------------------------------------------------------- verify
$journeys = [
    [
        'affected_surfaces' => ['post_type:wpforms'],
        'expect_contains' => 'Duo walk contact form',
        'expect_status' => 200,
        'id' => 'forms-index',
        'url' => '/?post_type=wpforms',
    ],
    [
        'affected_surfaces' => ['post_type:page'],
        'expect_contains' => 'Duo walk landing page',
        'expect_status' => 200,
        'id' => 'landing-page',
        'url' => '/?page_id=1',
    ],
];
JourneyOracle::validateJourneys($journeys);
$rows = [
    ['detail' => '', 'expect_contains' => 'Duo walk contact form', 'expect_status' => 200,
        'http_status' => 200, 'id' => 'forms-index', 'ok' => true,
        'status' => JourneyOracle::PASS, 'url' => '/?post_type=wpforms'],
    ['detail' => '', 'expect_contains' => 'Duo walk landing page', 'expect_status' => 200,
        'http_status' => 200, 'id' => 'landing-page', 'ok' => true,
        'status' => JourneyOracle::PASS, 'url' => '/?page_id=1'],
];
$verify = JourneyOracle::report(
    JourneyOracle::convergence(['deletions' => 0, 'live_entities' => 118, 'result' => 'pass', 'verifier' => 'plan-reconciliation/v1']),
    $rows,
    $journeys,
    ['post_type:wpforms', 'post_type:page'],
    'awalk2',
    (string) $plan['plan_digest']
);
if (($verify['verdict'] ?? null) !== JourneyOracle::PASS) {
    fwrite(STDERR, "make-fixtures: the pass verify fixture did not come out as a pass\n");
    exit(2);
}
walk_json("$out/verify-report.pass.json", $verify);

$failRows = $rows;
$failRows[0]['detail'] = 'expected HTTP 200, got 404';
$failRows[0]['http_status'] = 404;
$failRows[0]['ok'] = false;
$failRows[0]['status'] = JourneyOracle::FAIL;
walk_json("$out/verify-report.fail-journey.json", JourneyOracle::report(
    JourneyOracle::convergence(['deletions' => 0, 'live_entities' => 118, 'result' => 'pass', 'verifier' => 'plan-reconciliation/v1']),
    $failRows,
    $journeys,
    ['post_type:wpforms', 'post_type:page'],
    'awalk2',
    (string) $plan['plan_digest']
));

// ------------------------------------------------------- checkpoint catalog
$catalog = CheckpointCatalog::fromStatus(
    [
        'active' => true,
        'artifact_hash' => str_repeat('a1', 32),
        'available' => true,
        'checkpoint_sha256' => str_repeat('c3', 32),
        'created_at' => '2026-08-17T09:13:00Z',
        'generation' => 1,
        'ok' => true,
        'owner' => 'direct-0000000000000000',
        'receipt_format' => 'duo-scoped-promotion-receipt/v1',
        'receipt_id' => 'scoped-20260817-091300-0001',
        'state' => 'committed',
        'terminal' => true,
    ],
    null,
    $now
);
walk_json("$out/checkpoint-catalog.json", $catalog);
walk_json("$out/checkpoint-catalog.empty.json", CheckpointCatalog::fromStatus(null, null, $now));

// ---------------------------------------------------------- text: release
walk_write(
    "$out/plan-only.stdout.txt",
    "environment: awalk2\n"
    . "recovery profile: operator-directed\n"
    . "  because: this transport carries no rollback authority runtime\n"
    . "release to awalk2? [y/N]\n"
    . Canon::encode($plan)
);
walk_write(
    "$out/release-phases.ordered.txt",
    "promote phase: compile\n"
    . "promote phase: promotion-begin\n"
    . "promote phase: checkpoint\n"
    . "promote phase: code-stage\n"
    . "promote phase: lifecycle-retire\n"
    . "promote phase: lifecycle-activate\n"
    . "promote phase: code-finalize\n"
    . "promote phase: apply\n"
    . "released to awalk2\n"
);
walk_write(
    "$out/release-phases.apply-first.txt",
    "promote phase: compile\n"
    . "promote phase: promotion-begin\n"
    . "promote phase: checkpoint\n"
    . "promote phase: apply\n"
    . "promote phase: lifecycle-retire\n"
    . "promote phase: lifecycle-activate\n"
    . "released to awalk2\n"
);

// ----------------------------------------------------------- text: assess
// Rendered by the SHIPPED renderer from the S1 report above, so the leak gate
// and the unknown/next-action readers run against real layout rather than a
// remembered approximation.
$humanLines = AssessRenderer::render($s1, 50, [
    'contract_present' => false,
    'operation' => 'release',
    'proposal_path' => '.duo/contract/proposed.json',
]);
$humanToday = implode("\n", $humanLines) . "\n";

// §3.7 bug 2: `AssessRenderer::gapSection()` never counts undeclared tables
// and the `unknown:` block has no table line, so today's render is the FAIL
// fixture and the contract's is the PASS one. The inserted line is §3.6's
// literal `N undeclared table(s)`, placed in the unknown block beside the two
// counts that are already there.
walk_write("$out/assess-human.no-undeclared-table-line.txt", $humanToday);

$withTableLine = [];
foreach ($humanLines as $line) {
    $withTableLine[] = $line;
    if (str_starts_with($line, 'unknown: ')) {
        $withTableLine[] = '         3 undeclared table(s)';
    }
}
$clean = implode("\n", $withTableLine) . "\n";
walk_write("$out/assess-human.clean.txt", $clean);

// §5.2's leak rule as the walk mechanises it: the human view may print an
// internal identifier only when a documented command consumes one, and nothing
// consumes an operation id or an artifact hash from `duo assess`.
walk_write("$out/assess-human.leaks-uuid.txt", $clean . "operation: 7b1c9a02-4f6d-4c3e-9b21-0a5d3e8c7f14\n");
walk_write("$out/assess-human.leaks-artifact-hash.txt", $clean . 'artifact: ' . str_repeat('a1', 32) . "\n");

// ------------------------------------------------------------- text: init
// Init::render() is the shipped renderer; the two proposals below are the
// exact documents §3.4 describes on either side of --allow-unmanaged-plugins.
$initProposal = static function (bool $ready, array $unsupported, array $advisories): array {
    return [
        'advisories' => $advisories,
        'code' => [
            'active_theme' => ['stylesheet' => 'twentytwentyone', 'template' => 'twentytwentyone'],
            'files' => 4218,
            'management' => 'managed-baseline-proposed',
            'payload_bytes' => 51219884,
            'source_revision' => str_repeat('7', 64),
        ],
        'config' => ['manifests' => ['core', 'woocommerce']],
        'digest' => 'sha256:' . str_repeat('5', 64),
        'format' => 'duo-init-proposal/v1',
        'media' => ['attachments' => 0, 'strategy' => 'inline', 'unavailable' => 0],
        'ready' => $ready,
        'state' => [
            'adapters' => [['name' => 'core'], ['name' => 'woocommerce']],
            'git' => ['mode' => 'available', 'version' => '2.47.2'],
            'repository' => '/siterepo',
            'risk_surfaces' => ['options' => [], 'user_meta' => []],
        ],
        'target' => ['environment' => 'awalk1'],
        'unsupported' => $unsupported,
    ];
};
$unmanagedRow = [
    'code' => 'active_plugin_without_adapter',
    'extension' => 'wpforms-lite/wpforms.php',
    'kind' => 'plugin',
    'reason' => 'no installed manifest declares this active plugin identity',
    'remediation' => 'install or review one versioned adapter, then rerun duo init, or proceed with '
        . '--allow-unmanaged-plugins and record the decision in the contract',
];
$blockedLines = Init::render($initProposal(false, [$unmanagedRow], []));
walk_write("$out/init-blocked.txt", implode("\n", $blockedLines) . "\n");

// §3.4's --allow-unmanaged-plugins form: the same finding moves out of
// `unsupported` and into `advisories`, and its line reads `UNMANAGED PLUGIN`.
// `UNMANAGED` is a contract-new word — today's advisory renderer prints
// `ADVISORY` — so the heading word is substituted here and only here, and the
// rest of the line is the shipped renderer's.
$allowLines = Init::render($initProposal(true, [], [$unmanagedRow]));
$allowText = implode("\n", $allowLines) . "\n";
$allowText = str_replace('  ADVISORY PLUGIN wpforms-lite', '  UNMANAGED PLUGIN wpforms-lite', $allowText);
walk_write("$out/init-allow-unmanaged.txt", $allowText);

// An uncertified SITE adapter still blocks init (§1, §3.4), and §3.4 moves its
// remediation to name the new verb. This is the S2 pre-certification step.
$uncertifiedRow = [
    'code' => 'adapter_source_uncertified',
    'extension' => 'wpforms',
    'kind' => 'adapter',
    'reason' => "'wpforms' is installed from the site adapter source (/siterepo/adapters/wpforms.json) and is "
        . 'uncertified by construction: out-of-tree adapters carry no reviewed certification evidence',
    'remediation' => 'certify it with duo adapter certify <site-repo> --name=wpforms, or remove it, then rerun '
        . 'duo init',
    'source' => 'site',
    'trust_tier' => 'declarative',
];
walk_write(
    "$out/init-uncertified-adapter.txt",
    implode("\n", Init::render($initProposal(false, [$uncertifiedRow], []))) . "\n"
);

// ---------------------------------------------------------- text: refusals
// The refusal envelope every `--format=json` command emits
// (agent/src/Command/Cli.php::REFUSAL_FORMAT). The walk reads `reason_code`
// out of it so an unexpected stop can be reported BY NAME instead of as "a
// command failed".
$refusal = static function (string $command, string $code, string $message, string $remediation, array $diagnostics): string {
    $payload = [
        'format' => 'duo-command-refusal/v1',
        'ok' => false,
        'command' => $command,
        'error' => $code,
        'reason_code' => $code,
        'message' => $message,
        'remediation' => $remediation,
    ];
    if ($diagnostics !== []) {
        $payload['diagnostics'] = $diagnostics;
    }

    return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
};
walk_write("$out/refusal.incomplete-policy-scope.json", $refusal(
    'capture',
    'incomplete_policy_scope',
    'capture found authored state outside reviewed policy scope',
    'follow each diagnostic to expand policy scope or record an explicit non-authored classification',
    [[
        'code' => 'policy_scope_gap',
        'entity_count' => 2,
        'message' => 'capturable authored state exists outside reviewed policy scope',
        'remediation' => 'add it to policy.post_types or classify the exact scope as runtime, derived, or environment-owned',
        'surface' => 'scope:post_type:wpforms',
    ]]
));
walk_write("$out/refusal.adapter-source-uncertified.json", $refusal(
    'init',
    'adapter_source_uncertified',
    'an installed adapter carries no reviewed certification evidence',
    'certify it with duo adapter certify <site-repo> --name=wpforms, or remove it, then rerun duo init',
    []
));
// The human half of the same stop: `wp duo capture` without --format=json
// prints the operator message, which names the surface and the remedy but not
// the code. The walk's reader must recognise the stop from either shape, so
// both are recorded.
walk_write(
    "$out/refusal.incomplete-policy-scope.txt",
    "Error: duo: registered or adapter-declared authored state exists outside policy scope (loud-and-blocking gate):\n"
    . "  - post_type 'wpforms' has 2 capturable entities but is absent from policy.post_types; include it there, "
    . "or record a deliberate exclusion with scope:post_type:wpforms=runtime|derived|env\n"
    . "Run: wp duo pending --repo=/siterepo for evidence, then either add the type/taxonomy to policy scope or "
    . "run wp duo classify --repo=/siterepo --set='scope:<kind>:<name>=<class>'.\n"
);

sort($written, SORT_STRING);
echo 'wrote ' . count($written) . " fixture(s) to $out\n";
foreach ($written as $name) {
    echo "  $name\n";
}
if ($notes !== []) {
    echo "\ncontract-new shapes the shipped validators do not accept yet (expected while T6 is being built):\n";
    foreach ($notes as $note) {
        echo "  - $note\n";
    }
}
