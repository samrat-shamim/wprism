<?php
/**
 * WP-4.10 — the namespace grammar for the three flat identity spaces, and the
 * CLOSED grandfather list under it (spec/repo-format.md § v3.9).
 *
 * WHAT THIS SUITE HOLDS
 * ---------------------
 * Adapter names, `tables.<t>.id_kind` and `providers[].id` share exactly one
 * grammar (`AdapterSources::assert_name()`, a lowercase ASCII slug) and have no
 * namespace at all, so two independently-authored adapters that both pick
 * `cache` are two adapters answering to one name — and two tables that both
 * pick one `id_kind` resolve each other's `wprism_map` rows. At `spec_version 3`
 * an out-of-tree adapter name is `<vendor>-<name>` and every provider id it
 * declares sits in that same vendor namespace, which is what gives an
 * authority's `adapter_names: ["<vendor>-*"]` scope something to bind: squatting
 * then requires HOLDING A KEY rather than being first.
 *
 * The four claims, each asserted below rather than argued:
 *
 *   1. THE LIST IS CLOSED, AND IT IS IN THE RIGHT PLACE. 18 adapter names and
 *      21 `id_kind`s, enumerated in `agent/src` rather than any adapter
 *      package, where AGENTS.md rule 2 would fold them into every adapter's
 *      digest and make a nineteenth adapter invalidate the other eighteen's
 *      pins and certificates. `php tools/wire-surface.php --check` (a `make
 *      release-gate` step, register row R-27) asserts both halves; §7 below
 *      drives that gate against a fixture library carrying a NINETEENTH
 *      unprefixed name and asserts it refuses by name.
 *   2. IT ENUMERATES RATHER THAN TESTING SHAPE, and the measurement is the
 *      reason: 7 of the 18 shipped names carry no hyphen at all, and 11 more
 *      are hyphen-shaped without being vendor-prefixed — `the-events-calendar`
 *      is not vendor `the`. A shape test admits exactly the wrong ones.
 *   3. THE RULE IS v3-GATED AND LIVE NOW. `WPRISM_SPEC_VERSION` is 3 after
 *      WP-4.12, so the identical fixtures at `spec_version 2`, and with no
 *      `spec_version` at all, take no new refusal — asserted before any v3
 *      case, so a rule that leaked out of its gate fails there first.
 *   4. WHAT ALREADY SHIPPED IS ASSERTED, NOT REBUILT. WP-4.8's `<vendor>-*`
 *      scope patterns are live: §5 drives `assertAuthorityScope()` and shows an
 *      authority scoped `acme-*` certifying `acme-cache` and refusing
 *      `zeta-foo`. This rider adds the other end of that binding, not a second
 *      copy of it.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT ASSERT: any refusal for an unprefixed
 * `id_kind`. The irreversibility register rules that out permanently at R-17 —
 * captured state and `wprism_map` rows embed the BARE kind, so a prefix rule
 * introduced later would have to rewrite every token in every branch of every
 * site, which is the customer's data. The plan's acceptance evidence for this
 * work package asked for exactly that refusal ("an unprefixed out-of-tree
 * id_kind under a delegated authority refuses by name"); the spec text and R-17
 * refuse it, the spec text wins, and §6 asserts the opposite on purpose: a v3
 * out-of-tree manifest declaring an unprefixed `id_kind` LOADS, and the only
 * thing with teeth in that space is the unchanged uniqueness refusal.
 */
declare(strict_types=1);

// From offline/guards/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$repo = dirname(__DIR__, 4);

// The engine's own defines, parsed rather than invented: a fixture stamped with
// anything else would fail for a reason about this file.
$wprismSource = (string) file_get_contents($repo . '/agent/wprism.php');
if (preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $wprismSource, $m) !== 1) {
    fwrite(STDERR, "could not resolve WPRISM_SPEC_VERSION from agent/wprism.php\n");
    exit(1);
}
define('WPRISM_SPEC_VERSION', (int) $m[1]);
if (preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $wprismSource, $m) !== 1) {
    fwrite(STDERR, "could not resolve WPRISM_AGENT_VERSION from agent/wprism.php\n");
    exit(1);
}
define('WPRISM_AGENT_VERSION', $m[1]);

// Policy::assert_single_site() is function_exists()-guarded so the offline
// validators run outside WordPress; the scan below reaches it, so the real gate
// stays live rather than skipped.
function is_multisite(): bool {
    return false;
}

require_once $repo . '/agent/src/Kernel/Canon.php';
require_once $repo . '/agent/src/Kernel/OptionState.php';
require_once $repo . '/agent/src/Policy/Policy.php';
require_once $repo . '/agent/src/Policy/CrossManifestGuards.php';
require_once $repo . '/agent/src/Policy/AdapterLibrary.php';
require_once $repo . '/agent/src/Adapter/AdapterSources.php';
require_once $repo . '/agent/src/Adapter/AdapterCertification.php';
require_once $repo . '/agent/src/Adapter/IdentityNamespaces.php';

use WPrism\AdapterCertification;
use WPrism\AdapterLibrary;
use WPrism\AdapterSources;
use WPrism\Canon;
use WPrism\CrossManifestGuards;
use WPrism\IdentityNamespaces;
use WPrism\Policy;

$sourceLibrary = AdapterLibrary::fromSourceTree($repo);

$scratch = $repo . '/sandbox/tmp/identity-namespaces-' . getmypid();

function ins_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        ins_remove_tree($item->getPathname());
    }
    rmdir($path);
}

ins_remove_tree($scratch);
if (!mkdir($scratch, 0777, true)) {
    fwrite(STDERR, "cannot create scratch root $scratch\n");
    exit(1);
}
register_shutdown_function(static fn() => ins_remove_tree($scratch));

/** The refusal message of $fn, or null when it accepted. */
$refusal = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

/**
 * A minimal, purely declarative out-of-tree manifest — the shape a site adapter
 * actually carries, so every assertion below runs against the real contract
 * rather than a stub the engine would never see.
 *
 * @return array<string,mixed>
 */
$manifest = static function (string $name, ?int $spec, array $extra = []): array {
    $body = $extra + [
        'name' => $name,
        'option_autoload' => 'preserve',
        'options' => [str_replace('-', '_', $name) . '_layout' => ['class' => 'authored']],
    ];
    if ($spec !== null) {
        $body['spec_version'] = $spec;
    }
    ksort($body, SORT_STRING);

    return $body;
};

/** The § v3.9 rule, driven exactly as the out-of-tree boundary drives it. */
$namespaceVerdict = static function (array $body, string $name) use ($refusal): ?string {
    return $refusal(static fn() => IdentityNamespaces::assert_out_of_tree_identity(
        $body,
        $name,
        'site adapter',
        "'adapters/$name.json'"
    ));
};

echo "\n== 1. the closed list: membership against the shipped library, and its location ==\n";

$shippedNames = [];
$shippedKinds = [];
foreach ($sourceLibrary->packages() as $package) {
    $file = $package->manifestPath();
    $base = $package->name();
    $decoded = Canon::decode(Canon::read_file($file));
    $shippedNames[] = (string) ($decoded['name'] ?? $base);
    foreach ((array) ($decoded['tables'] ?? []) as $table) {
        if (is_array($table) && is_string($table['id_kind'] ?? null)) {
            $shippedKinds[$table['id_kind']] = true;
        }
    }
}
sort($shippedNames, SORT_STRING);
$shippedKinds = array_map('strval', array_keys($shippedKinds));
sort($shippedKinds, SORT_STRING);

wprism_check_same(
    $shippedNames,
    IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES,
    'the grandfather list carries exactly the ' . count($shippedNames)
    . ' adapter names the shipped library declares — in both directions, which is what makes it CLOSED'
);
wprism_check_same(
    $shippedKinds,
    IdentityNamespaces::GRANDFATHERED_ID_KINDS,
    'and exactly the ' . count($shippedKinds) . ' `id_kind`s it declares — R-17\'s permanent floor, recorded'
);

// LOCATION (AGENTS.md rule 2). Reflection rather than a path literal: what has
// to be true is where the constants a refusal READS live, not where a file was
// once put.
$listFile = (new ReflectionClass(IdentityNamespaces::class))->getFileName();
wprism_check(
    is_string($listFile) && str_starts_with((string) realpath((string) $listFile), (string) realpath($repo . '/agent/src')),
    'the list is declared under agent/src, where it moves no adapter digest — under an adapter package it would be an '
    . 'identity input on every adapter row, so a nineteenth adapter would invalidate the other eighteen\'s pins'
);
$underManifests = [];
foreach ($sourceLibrary->packages() as $package) {
    $file = $package->manifestPath();
    if (str_contains((string) file_get_contents($file), 'GRANDFATHERED')) {
        $underManifests[] = basename($file);
    }
}
wprism_check_same([], $underManifests, 'and no shipped manifest carries a second copy of it');

echo "\n== 2. it ENUMERATES rather than testing shape, and the shipped library is why ==\n";

$hyphenShaped = static fn(string $value): bool => preg_match('/^[a-z0-9]+-[a-z0-9-]+$/D', $value) === 1;
$noHyphen = array_values(array_filter($shippedNames, static fn(string $n): bool => !$hyphenShaped($n)));
wprism_check_same(
    ['acf', 'core', 'elementor', 'polylang', 'redirection', 'woocommerce', 'yoast'],
    $noHyphen,
    count($noHyphen) . ' shipped names carry no hyphen at all, so a bare shape rule would refuse them outright'
);
wprism_check_same(
    'the',
    IdentityNamespaces::vendor('the-events-calendar'),
    'and `the-events-calendar` reads as vendor `the` under any shape test — shape reports FORM, never ownership'
);
wprism_check(
    IdentityNamespaces::is_grandfathered_name('the-events-calendar')
        && IdentityNamespaces::is_grandfathered_name('core'),
    'which is exactly why the list decides and the shape does not: both the hyphen-shaped and the bare names '
    . 'are admitted by enumeration'
);
wprism_check(
    !IdentityNamespaces::is_grandfathered_name('the-events-calendar-pro')
        && !IdentityNamespaces::is_grandfathered_name('cores'),
    'and a name that merely LOOKS like a shipped one is not on it — the membership test is exact, never a prefix'
);
wprism_check_same(
    null,
    IdentityNamespaces::vendor('wc_zone'),
    'no shipped `id_kind` is in `<vendor>-<name>` form at all: every one is underscore-separated (R-17)'
);

echo "\n== 3. the rule is INERT below spec_version 3 — the flag-day invariant ==\n";

// WP-4.12: the third probe used to be WPRISM_SPEC_VERSION, which read as "the
// version this engine runs at" and happened to be below the gate. The flip
// crossed the gate and turned that probe into a v3 declaration the rule
// correctly refuses — so the loop now says what section 3 is actually about:
// every version BELOW the gate, expressed from the gate itself.
foreach ([null, 1, IdentityNamespaces::NAMESPACED_SINCE - 1] as $spec) {
    $label = $spec === null ? 'no spec_version' : "spec_version $spec";
    wprism_check_same(
        null,
        $namespaceVerdict($manifest('cache', $spec), 'cache'),
        "an unprefixed out-of-tree name with $label takes no new refusal — the channel is inert exactly as "
        . '§ v3.2\'s feature declaration is'
    );
    wprism_check_same(
        null,
        $namespaceVerdict($manifest('acme-cache', $spec, [
            'providers' => [['id' => 'zeta-thing', 'source' => 'plugin', 'plugin' => 'zeta/zeta.php']],
        ]), 'acme-cache'),
        "and a provider id outside the adapter's namespace with $label takes none either"
    );
}
// WP-4.12 flipped this. WP-4.10's assertion was "WPRISM_SPEC_VERSION is still 2,
// so every manifest that exists is below the gate" — true then, and the reason
// the rider moved no shipped byte. After the flip the engine is AT the gate,
// and what carries that same guarantee forward is the OTHER half of the
// no-bulk-restamp rule: the gate only reads a manifest's OWN declared version.
// Later feature consumers opt in deliberately and their grandfathered names
// pass; seven unrelated manifests remain below the gate.
wprism_check_same(
    IdentityNamespaces::NAMESPACED_SINCE,
    WPRISM_SPEC_VERSION,
    'WPRISM_SPEC_VERSION is 3 — the engine now sits AT the namespace gate (the flip, WP-4.12)'
);
$stampedAtGate = [];
foreach ($sourceLibrary->packages() as $package) {
    $shipped = $package->manifestPath();
    $declared = json_decode((string) file_get_contents($shipped), true);
    if (is_array($declared) && ($declared['spec_version'] ?? null) >= IdentityNamespaces::NAMESPACED_SINCE) {
        $stampedAtGate[] = $package->name();
    }
}
usort($stampedAtGate, static fn(string $left, string $right): int => strcmp($left . '.json', $right . '.json'));
wprism_check_same(
    [
        'code-snippets',
        'download-manager',
        'elementor',
        'ninja-forms',
        'paid-memberships-pro',
        'polylang',
        'rank-math',
        'redirection',
        'the-events-calendar',
        'woocommerce',
        'wordpress-popup',
        'wpforms-lite',
        'yoast-duplicate-post',
        'yoast',
    ],
    $stampedAtGate,
    'and exactly the feature-consuming manifests declare a version at the gate while seven unrelated manifests '
        . 'retain the no-bulk-restamp boundary (§ v3.12)'
);
wprism_check(
    IdentityNamespaces::is_grandfathered_name('redirection'),
    'the newly authored v3 subject is explicitly present in the closed grandfather list rather than bypassing the namespace rule'
);

echo "\n== 4. at spec_version 3 the reserved form is a RULE ==\n";

wprism_check_same(
    null,
    $namespaceVerdict($manifest('acme-cache', 3), 'acme-cache'),
    'a `<vendor>-<name>` out-of-tree name is accepted'
);
wprism_check_same(
    null,
    $namespaceVerdict($manifest('zeta-cache', 3), 'zeta-cache'),
    'and so is the same functional adapter under a second vendor — two namespaces, one grammar, no collision'
);

$unprefixed = $namespaceVerdict($manifest('cache', 3), 'cache');
wprism_check(
    is_string($unprefixed) && str_contains($unprefixed, "the unprefixed name 'cache'")
        && str_contains($unprefixed, 'closed reserved list of 21 names')
        && str_contains($unprefixed, '§ v3.9'),
    'an unprefixed out-of-tree name refuses BY NAME, naming the closed list and the section that decided it'
);
wprism_check(
    is_string($unprefixed) && str_contains($unprefixed, "adapter_names carries '<vendor>-*'"),
    'and the refusal points at the authority scope that would grant the namespace, which is the actual remedy — '
    . 'the prefix is bound to a key, not to registration order'
);

$grandfathered = $namespaceVerdict($manifest('woocommerce', 3), 'woocommerce');
wprism_check_same(
    null,
    $grandfathered,
    'a grandfathered name is accepted at v3 — out of tree that is the reviewed {name, source: "site"} override '
    . 'of a shipped adapter (T6 §3.3), which the closed list exists to keep loading'
);

// M2 (G2 review) — THE GRANDFATHER EXEMPTION IS THE NAME'S ALONE.
//
// The early return used to sit AHEAD of the provider loop, so a grandfathered
// name skipped the provider rule entirely: 11 of the 18 shipped names have a
// vendor half, and an out-of-tree manifest answering one of them could declare
// provider ids in any vendor's namespace at all — the one door left open in the
// binding § v3.9 exists to make transitive. Both arms are pinned, because the
// fix has a deliberate second arm and an untested one is an accident.
$grandfatheredForeignProvider = $namespaceVerdict($manifest('ninja-forms', 3, [
    'providers' => [['id' => 'zeta-thing', 'source' => 'plugin', 'plugin' => 'zeta/zeta.php']],
]), 'ninja-forms');
wprism_check(
    is_string($grandfatheredForeignProvider)
        && str_contains($grandfatheredForeignProvider, "providers[0].id 'zeta-thing'")
        && str_contains($grandfatheredForeignProvider, "outside the 'ninja-' namespace"),
    'a GRANDFATHERED name that HAS a vendor half is still held to the provider rule: the exemption was argued '
    . 'for the name, and it now covers exactly the name (' . $grandfatheredForeignProvider . ')'
);
wprism_check_same(
    null,
    $namespaceVerdict($manifest('ninja-forms', 3, [
        'providers' => [['id' => 'ninja-forms-cache-table', 'source' => 'plugin', 'plugin' => 'ninja/ninja.php']],
    ]), 'ninja-forms'),
    'and the shipped adapter\'s OWN provider id passes that rule, so the reviewed override of it still loads — '
    . 'the case the closed list exists to keep working is not broken by closing the door beside it'
);
wprism_check_same(
    null,
    $namespaceVerdict($manifest('woocommerce', 3, [
        'providers' => [['id' => 'zeta-thing', 'source' => 'plugin', 'plugin' => 'zeta/zeta.php']],
    ]), 'woocommerce'),
    'while a grandfathered name with NO vendor half skips the provider rule — `woocommerce` has no `<vendor>-` '
    . 'for a provider id to be bound to, so there is no rule to apply rather than a rule being waived'
);
// The measurement that keeps the second arm honest as the library grows: every
// shipped provider id is inside its declaring adapter's vendor namespace, or
// that adapter has no vendor half at all. A nineteenth adapter that broke this
// would make its own reviewed override unloadable at v3 — a reviewed edit, and
// this is where it is noticed.
$providerNamespaceMisfits = [];
foreach ($sourceLibrary->packages() as $package) {
    $shippedFile = $package->manifestPath();
    $shippedManifest = json_decode((string) file_get_contents($shippedFile), true);
    if (!is_array($shippedManifest)) {
        continue;
    }
    $shippedName = (string) ($shippedManifest['name'] ?? basename($shippedFile, '.json'));
    $shippedVendor = IdentityNamespaces::vendor($shippedName);
    if ($shippedVendor === null) {
        continue;
    }
    foreach ((array) ($shippedManifest['providers'] ?? []) as $shippedProvider) {
        $shippedId = is_array($shippedProvider) ? ($shippedProvider['id'] ?? null) : null;
        if (is_string($shippedId) && IdentityNamespaces::vendor($shippedId) !== $shippedVendor) {
            $providerNamespaceMisfits[] = "$shippedName:$shippedId";
        }
    }
}
wprism_check_same(
    [],
    $providerNamespaceMisfits,
    'and the shipped library already satisfies that rule, measured rather than assumed: every provider id an '
    . 'adapter with a vendor half declares sits inside that vendor namespace, so the reviewed override of any '
    . 'of the 18 loads at v3'
);

$providerRefusal = $namespaceVerdict($manifest('acme-cache', 3, [
    'providers' => [
        ['id' => 'acme-cache-state', 'source' => 'plugin', 'plugin' => 'acme/acme.php'],
        ['id' => 'zeta-thing', 'source' => 'plugin', 'plugin' => 'acme/acme.php'],
    ],
]), 'acme-cache');
wprism_check(
    is_string($providerRefusal) && str_contains($providerRefusal, "providers[1].id 'zeta-thing'")
        && str_contains($providerRefusal, "outside the 'acme-' namespace"),
    'a provider id outside the declaring adapter\'s own vendor namespace refuses, naming the INDEX and the '
    . 'namespace — without this half an adapter certified under `acme-*` could still squat `zeta-`'
);
wprism_check_same(
    null,
    $namespaceVerdict($manifest('acme-cache', 3, [
        'providers' => [['id' => 'acme-cache-state', 'source' => 'plugin', 'plugin' => 'acme/acme.php']],
    ]), 'acme-cache'),
    'and a provider id inside it is accepted, so the whole identity set one adapter contributes is bound to the '
    . 'one authority scope its name was certified against'
);

echo "\n== 5. the authority scope half already shipped (WP-4.8) — asserted, not rebuilt ==\n";

$scoped = static function (array $names, string $adapter) use ($refusal): ?string {
    return $refusal(static fn() => (new ReflectionMethod(AdapterCertification::class, 'assertAuthorityScope'))
        ->invoke(null, [
            'adapter_names' => $names,
            'status' => 'trusted',
            'trust_tiers' => [AdapterSources::TIER_DECLARATIVE],
        ], 'acme-000000000000', $adapter, AdapterSources::TIER_DECLARATIVE));
};

wprism_check_same(
    null,
    $scoped(['acme-*'], 'acme-cache'),
    'an authority scoped `acme-*` certifies `acme-cache`'
);
$outside = $scoped(['acme-*'], 'zeta-foo');
wprism_check_same(
    "wprism: authority key 'acme-000000000000' is not scoped to site adapter 'zeta-foo'",
    $outside,
    'and refuses `zeta-foo` by name — the binding this rider gives out-of-tree names something to attach to'
);
wprism_check(
    is_string($scoped(['acme-*'], 'acme')),
    'the namespace does not cover the bare vendor word itself: the hyphen is INSIDE the pattern'
);
wprism_check_same(
    null,
    $scoped(['zeta-cache'], 'zeta-cache'),
    'and an exact-name scope still works unchanged beside the pattern'
);

echo "\n== 6. through the real scan: coexistence, the shipped 18, and the unchanged refusals ==\n";

$siteRepo = static function (string $suffix, array $adapters, array $pins) use ($scratch, $manifest): string {
    $root = $scratch . '/' . $suffix;
    mkdir($root . '/adapters', 0777, true);
    foreach ($adapters as $name => $body) {
        Canon::write_file($root . '/adapters/' . $name . '.json', Canon::encode($body));
    }
    Canon::write_file($root . '/site.wprism.json', Canon::encode([
        'manifests' => $pins,
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));

    return $root;
};

$coexist = $siteRepo('coexist', [
    'acme-cache' => $manifest('acme-cache', WPRISM_SPEC_VERSION),
    'zeta-cache' => $manifest('zeta-cache', WPRISM_SPEC_VERSION),
], ['core', 'acme-cache', 'zeta-cache']);
$sources = AdapterSources::discover_library($sourceLibrary, $coexist);
$loaded = $sources->names();
sort($loaded, SORT_STRING);
wprism_check(
    in_array('acme-cache', $loaded, true) && in_array('zeta-cache', $loaded, true),
    '`acme-cache` and `zeta-cache` coexist in one pin set — the vendor prefix is what makes two vendors\' '
    . 'answer to one concept two identities instead of a collision'
);
$shippedStillLoaded = array_values(array_intersect($loaded, $shippedNames));
wprism_check_same(
    $shippedNames,
    $shippedStillLoaded,
    'and all ' . count($shippedNames) . ' shipped names load unchanged beside them'
);

// The two identity refusals this rider must not have moved. Both messages are
// compared against the engine's own sentence rather than paraphrased.
//
// CASE FOLDING is asserted against `assert_name()` directly rather than by
// writing two files that differ only by case: this suite has to give the same
// verdict on a case-insensitive filesystem (macOS, where the second write
// silently lands on the first file) and a case-sensitive one, and the rule
// being pinned is the shared grammar's, not the directory's.
$caseMessage = $refusal(static fn() => AdapterSources::assert_name('acme-Cache', 'site adapter'));
wprism_check(
    is_string($caseMessage) && str_contains($caseMessage, 'canonical lowercase ASCII slugs')
        && str_contains($caseMessage, "site adapter uses 'acme-Cache'"),
    'the case-folding refusal in the one shared identity grammar is unchanged — the same bytes must resolve on '
    . 'case-folding and Unicode-normalizing filesystems'
);
wprism_check_same(
    null,
    IdentityNamespaces::vendor('Acme-cache'),
    'and an uppercase vendor half is not a namespace at all, so `Acme-` can never become a second spelling of '
    . 'the `acme-` namespace or a way around the closed list'
);

$kindMessage = $refusal(static fn() => CrossManifestGuards::validate_unique_table_id_kinds([
    'acme_widget' => ['class' => 'authored_snapshot', 'id_kind' => 'shared_kind'],
    'zeta_widget' => ['class' => 'authored_snapshot', 'id_kind' => 'shared_kind'],
]));
wprism_check_same(
    "wprism: id_kind 'shared_kind' is declared by both 'acme_widget' and 'zeta_widget' — each authored_snapshot "
    . 'table needs its own unique id_kind, because wprism_map is keyed by (id_kind, local_id): two tables sharing '
    . "one kind resolve each other's rows the moment both hold the same local id. An id_kind is the adapter's "
    . 'own namespace to choose; pick a distinct one (typically a short prefix of the owning plugin)',
    $kindMessage,
    'the (id_kind, local_id) uniqueness refusal is byte-identical — it is still the only thing with teeth in '
    . 'that space'
);
wprism_check_same(
    null,
    CrossManifestGuards::validate_unique_table_id_kinds([
        'acme_widget' => ['class' => 'authored_snapshot', 'id_kind' => 'acme_widget'],
        'zeta_widget' => ['class' => 'authored_snapshot', 'id_kind' => 'zeta_widget'],
    ]),
    'and distinct kinds still pass'
);

// R-17's floor, asserted as the ABSENCE of a rule: this is the one identity
// space v3 leaves alone, and a later rider adding a refusal here would be
// overruling the register.
wprism_check_same(
    null,
    $namespaceVerdict($manifest('acme-cache', 3, [
        'tables' => ['acme_widget' => ['class' => 'authored_snapshot', 'id_kind' => 'acme_widget']],
    ]), 'acme-cache'),
    'a v3 out-of-tree manifest declaring an UNPREFIXED `id_kind` loads: R-17 reserves the convention and '
    . 'refuses the rule, because captured state and wprism_map rows embed the bare kind'
);

echo "\n== 7. the release gate bites: a nineteenth unprefixed name cannot be added quietly ==\n";

// A fixture library: the four engine trees are symlinked (nothing in them is
// mutated), while package and platform sources are copied so a new adapter can
// appear without touching the checkout.
$gateRoot = $scratch . '/gate-root';
mkdir($gateRoot, 0777, true);
foreach (['agent', 'cli', 'recovery', 'docs'] as $tree) {
    symlink($repo . '/' . $tree, $gateRoot . '/' . $tree);
}
exec('cp -R ' . escapeshellarg($repo . '/adapter-packages') . ' ' . escapeshellarg($gateRoot . '/adapter-packages'), $_o, $copyPackages);
exec('cp -R ' . escapeshellarg($repo . '/platform') . ' ' . escapeshellarg($gateRoot . '/platform'), $_o, $copyPlatform);
wprism_check_same(0, $copyPackages, 'the fixture library contains a mutable copy of every adapter package');
wprism_check_same(0, $copyPlatform, 'the fixture library contains a mutable copy of the platform adapter library');

$runGate = static function (string $root) use ($repo): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, $repo . '/tools/wire-surface.php', '--check', '--root=' . $root],
        $descriptors,
        $pipes
    );
    if (!is_resource($process)) {
        return ['status' => -1, 'stdout' => '', 'stderr' => 'could not launch tools/wire-surface.php'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$clean = $runGate($gateRoot);
wprism_check_same(
    0,
    $clean['status'],
    'the unmutated fixture passes the gate — without this the mutation below would prove nothing'
);

// The nineteenth. Any shipped manifest copied under a new unprefixed name is
// enough: what the gate refuses is a library the reviewed list does not match.
exec(
    'cp -R ' . escapeshellarg($gateRoot . '/adapter-packages/wprism-agency-cpt') . ' '
    . escapeshellarg($gateRoot . '/adapter-packages/zeta'),
    $_o,
    $copyCandidate
);
wprism_check_same(0, $copyCandidate, 'the candidate starts as one complete package capsule');
$zetaManifest = $gateRoot . '/adapter-packages/zeta/package/manifest.json';
$nineteenth = Canon::decode(Canon::read_file($zetaManifest));
$nineteenth['name'] = 'zeta';
Canon::write_file($zetaManifest, Canon::encode($nineteenth));
$mutated = $runGate($gateRoot);
wprism_check_same(
    1,
    $mutated['status'],
    'a nineteenth unprefixed shipped name fails `php tools/wire-surface.php --check`, which `make '
    . 'release-gate` runs'
);
wprism_check(
    str_contains($mutated['stderr'], 'unlisted [zeta]')
        && str_contains($mutated['stderr'], 'the list is CLOSED (row R-27)')
        && str_contains($mutated['stderr'], 'agent/src/Adapter/ShippedIdentityInventory.php'),
    'and it names the identity, the register row, and the generated inventory that must be refreshed after '
    . 'the reviewed act of adding an unprefixed shipped identity'
);
ins_remove_tree($gateRoot . '/adapter-packages/zeta');

wprism_check_summary('regress_identity_namespaces');
