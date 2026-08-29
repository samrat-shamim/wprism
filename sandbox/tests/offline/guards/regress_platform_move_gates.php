<?php
/**
 * Offline invariant — the register of runtime gates that a platform-boundary
 * or manifest-identity move can trip is COMPLETE against the shipped tree.
 *
 * WHY THIS EXISTS
 * ---------------
 * AGENTS.md rule 2 states the consequence of one byte under `manifests/`: a
 * deployed site refuses with `compiled_artifact_manifest_mismatch`. That is
 * ONE of the sites a move trips. Rule 8 names a second (platform.json against
 * `agent/wprism.php`'s two defines). Nothing enumerated the rest, so the failure
 * mode was "the gate nobody enumerated" — discovered on a customer site rather
 * than at review time. `tools/platform-move-gates.json` is that enumeration;
 * this suite is what keeps it honest, by deriving the candidate set from the
 * tree on every run and refusing a site the tree has and the register does not.
 *
 * THE PREDICATE (named: the identity-comparison predicate)
 * -------------------------------------------------------
 * Over every `*.php` file under the three product roots — `agent/`, `cli/`,
 * `recovery/`; `cli/src/Onboarding/Adopt.php:212-226` embeds the manifest library
 * in agent/ and archives `agent recovery`, while `cli/` produces what they consume — a unit is a
 * CANDIDATE when both arms hold. A unit is one outermost `function` body; a
 * nested closure belongs to the method that contains it, because the reviewer's
 * question is "which entry point can refuse", not "which brace block".
 *
 *   ARM 1, identity. The unit references a value on one of the three identity
 *   axes. Match is on the NORMALIZED token — lowercased, underscores removed —
 *   containing one of 17 stems, so `resolved_adapters`, `$resolvedAdapters` and
 *   `resolved_adapters_sha256` are one concept and not three spellings that a
 *   reviewer has to remember to add. Three carve-outs keep the arm honest — named
 *   that rather than "exclusions", which below means the register's reviewed
 *   drops — and each one paid for itself while this register was built:
 *     - comments and docblocks are stripped first. Rule 10 makes this tree full
 *       of rationale-dense prose naming `manifest_hash`; documentation is not a
 *       gate, and counting it is exactly the noise the register must not become.
 *     - a token immediately followed by `::` is a class NAME, a location rather
 *       than a value. Without this, `class_exists(ManifestDispositions::class)`
 *       in four `boot()` methods reads as disposition identity.
 *     - a string literal that names a file (contains `/`, or ends `.php`) or is
 *       bare class-name shaped is not an identity value either — the two
 *       capability documents are the deliberate exception, since those paths
 *       ARE the identity.
 *
 *   ARM 2, decision. The unit can act on that value: an equality operator
 *   (`===` `!==` `==` `!=`), `hash_equals()`, `in_array()`, a `throw`, or a call
 *   whose name begins assert/validate/verify/refuse/require. The last is not
 *   decoration: `ScopedApplySession::assert_source()` refuses a mutation
 *   authority whose source malforms `manifest_hash` and does it entirely
 *   through `assert_keys()`/`assert_hash()`, with no operator of its own.
 *
 * WHAT THE PREDICATE DELIBERATELY DOES NOT SELECT
 * ----------------------------------------------
 * A digest taken over a DOCUMENT that happens to contain an identity value is
 * not itself one of the three axes, and neither is site identity — a
 * `site.wprism.json` move is a different change class, answered by its own
 * registered refusal (`compiled_artifact_policy_mismatch`). The cost of widening
 * was measured rather than assumed: adding a bare `digest` stem to the 17 below
 * — the single most tempting relaxation — takes the candidate set from 76 to
 * 198, and the 122 it adds are overwhelmingly artifact, revision, bundle and
 * proposal digests that no `manifests/` byte moves. A register nobody can read
 * in one sitting is the risk WP-0.6 was written against, so the arms stay narrow
 * and the drops are written down.
 *
 * The register's `exclusions` block is where they are written: each dropped
 * site a reviewer would expect to find, with its reason and the `arm` that
 * dropped it. Clause 3 keeps all of that true against the tree — the site still
 * exists, still resolves to one unit, is still not selected, is not also
 * registered, and the declared arm is the one re-derived from the walk. So an
 * exclusion is a reviewed line rather than prose: widen an arm until a dropped
 * site is selected and the gate says so, instead of the widening passing
 * unnoticed under a register that still looks complete.
 *
 * WHY THE REGISTER LIVES IN tools/
 * -------------------------------
 * Exact precedent: `tools/layers-exceptions.json` is a reviewed ratchet over a
 * derived set, consumed by `regress_agent_src_requires.php` in this same
 * directory. The register is data about the tree that a reviewer of a
 * `manifests/` change reads directly, not a fixture belonging to one suite, and
 * `tools/` is where this repo already keeps that. It carries no PHP entry point,
 * so it adds no `tests/` PHPUnit obligation.
 *
 * WHAT A FAILURE MEANS
 * --------------------
 * "candidate is absent from the register" — you added or renamed a site that
 * handles platform/manifest/authority identity. Add it with a verdict and a
 * note; `carrier` and `derivation` are first-class answers, but an EMPTY note
 * is refused, because an exclusion without its reason is a silent omission.
 * "register names a site the tree does not have" — a rename or a move; the
 * register keys on file+function precisely so that ordinary edits do not churn
 * it, which means a key that stopped resolving is a real move.
 * "an excluded site is now selected" — an arm was widened. Either register it
 * as a site and drop the exclusion, or narrow the arm back; the one thing that
 * cannot happen is the widening going unreviewed.
 */
declare(strict_types=1);

// From offline/guards/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);

/** The 18 identity stems, grouped by the axis a move on them belongs to. */
const PMG_STEMS = [
    // manifest identity: ArtifactPolicyIdentity::manifest_rows() and its folds
    'manifesthash', 'manifestrows', 'manifestsha256', 'manifestinputssha256',
    'manifestdisposition', 'shippedmanifests', 'resolvedadapters', 'adapterdigest',
    // platform boundary: manifests/capabilities/platform.json
    'platformboundary', 'platformdigest', 'platformsha256', 'platformrelative',
    // WP-4.7 (§ v3.6) split the platform axis into two spellings that mean two
    // different numbers: `platform_sha256` is still the CONTRACT
    // attestation's whole-boundary digest, and `platform_axes_sha256` is a
    // certificate's digest of the exercised compatibility cells. A stem list
    // that carried only the first would have silently DESELECTED every site
    // the rename touched — which is exactly how a gate stops being watched,
    // so the second spelling is enumerated here rather than absorbed by a
    // looser pattern.
    'platformaxessha256',
    // authority records: the two Ed25519 trust roots
    'authoritydigest', 'authorityrecord', 'authoritykeys', 'authoritiesformat',
    'authoritiesrelative',
];

/** The identity DOCUMENTS. A literal naming one of these is an identity value. */
const PMG_DOCS = [
    'capabilities/platform.json',
    'capabilities/adapter-authorities.json',
    'adapters/authorities.json',
];

/** Arm 2's call names that are decisions rather than plumbing. */
const PMG_DECISION_CALLS = ['hash_equals', 'in_array'];
const PMG_GUARD_PREFIX = '/^(assert|validate|verify|refuse|require)/i';

const PMG_ROOTS = ['agent', 'cli', 'recovery'];

function pmg_norm(string $token): string
{
    return strtolower(str_replace('_', '', $token));
}

function pmg_stem_hit(string $raw): bool
{
    $normalized = pmg_norm($raw);
    foreach (PMG_STEMS as $stem) {
        if (str_contains($normalized, $stem)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> absolute paths, sorted, so the candidate order is stable */
function pmg_php_files(string $base, array $roots): array
{
    $files = [];
    foreach ($roots as $dir) {
        $path = rtrim($base, '/') . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * The identity-comparison predicate, applied to one file's token stream.
 *
 * Returns EVERY outermost unit, each carrying whether both arms held, rather
 * than only the selected ones: the exclusions block in the register names units
 * the predicate deliberately drops, and asserting "this site still exists and is
 * still not selected" needs the dropped units too. A list rather than a map, so
 * a caller can see a name claimed twice in one file instead of one of the two
 * silently overwriting the other.
 *
 * @return list<array{key:string,line:int,tokens:list<string>,selected:bool}>
 */
function pmg_file_units(string $file, string $relative): array
{
    $stripped = [];
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        // Rule 10 fills this tree with prose naming identity values. Prose is
        // not a gate; stripping it here is what keeps the register reviewable.
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $stripped[] = $token;
    }
    $count = count($stripped);

    // Index of the next non-whitespace token, for the `X::` class-name test.
    $next = [];
    $seen = null;
    for ($i = $count - 1; $i >= 0; $i--) {
        $next[$i] = $seen;
        if (!(is_array($stripped[$i]) && $stripped[$i][0] === T_WHITESPACE)) {
            $seen = $i;
        }
    }

    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $token = $stripped[$i];
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $nameAt = $i + 1;
        while ($nameAt < $count
            && ((is_array($stripped[$nameAt]) && $stripped[$nameAt][0] === T_WHITESPACE)
                || (!is_array($stripped[$nameAt]) && $stripped[$nameAt] === '&'))) {
            $nameAt++;
        }
        $name = (is_array($stripped[$nameAt]) && $stripped[$nameAt][0] === T_STRING)
            ? $stripped[$nameAt][1]
            : '{closure}#' . $token[2];

        // Find the body's opening brace, skipping the signature's parentheses.
        // An interface/abstract declaration ends at `;` and has no unit.
        $open = $nameAt;
        $parens = 0;
        $declarationOnly = false;
        for (; $open < $count; $open++) {
            $text = is_array($stripped[$open]) ? $stripped[$open][1] : $stripped[$open];
            if ($text === '(') {
                $parens++;
            } elseif ($text === ')') {
                $parens--;
            } elseif ($text === '{' && $parens === 0) {
                break;
            } elseif ($text === ';' && $parens === 0) {
                $declarationOnly = true;
                break;
            }
        }
        if ($declarationOnly || $open >= $count) {
            $i = $open;
            continue;
        }

        $identity = [];
        $decision = false;
        $depth = 0;
        $end = $open;
        for (; $end < $count; $end++) {
            $current = $stripped[$end];
            $text = is_array($current) ? $current[1] : $current;
            if ($text === '{'
                || (is_array($current)
                    && in_array($current[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if (!is_array($current)) {
                continue;
            }
            [$id, $body] = [$current[0], $current[1]];

            if ($id === T_STRING || $id === T_VARIABLE) {
                $after = $next[$end] ?? null;
                $isClassName = $after !== null
                    && is_array($stripped[$after])
                    && $stripped[$after][0] === T_DOUBLE_COLON;
                if (!$isClassName && pmg_stem_hit(ltrim($body, '$'))) {
                    $identity[ltrim($body, '$')] = true;
                }
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $literal = trim($body, "'\"");
                $document = null;
                foreach (PMG_DOCS as $candidate) {
                    if (str_contains($literal, $candidate)) {
                        $document = $candidate;
                    }
                }
                if ($document !== null) {
                    $identity[$document] = true;
                } elseif (!str_contains($literal, '/')
                    && !str_ends_with($literal, '.php')
                    && preg_match('/^[A-Z][A-Za-z0-9]*$/D', $literal) !== 1
                    && pmg_stem_hit($literal)) {
                    $identity[$literal] = true;
                }
            }

            if (in_array($id, [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL], true)
                || $id === T_THROW
                || ($id === T_STRING && in_array($body, PMG_DECISION_CALLS, true))
                || ($id === T_STRING && preg_match(PMG_GUARD_PREFIX, $body) === 1)) {
                $decision = true;
            }
        }

        $tokens = array_keys($identity);
        sort($tokens, SORT_STRING);
        $out[] = [
            'key' => $relative . '::' . $name,
            'line' => $token[2],
            'tokens' => $tokens,
            'selected' => $identity !== [] && $decision,
        ];
        $i = $end;
    }

    return $out;
}

/**
 * Every unit in the shipped roots, selected or not. `occurrences` is what makes
 * a name claimed twice in one file visible instead of one silently winning.
 *
 * @return array<string,array{occurrences:int,tokens:list<string>,selected:bool}>
 */
function pmg_units(string $base, array $roots): array
{
    $units = [];
    foreach (pmg_php_files($base, $roots) as $file) {
        $relative = substr($file, strlen(rtrim($base, '/')) + 1);
        foreach (pmg_file_units($file, $relative) as $unit) {
            $key = $unit['key'];
            $units[$key] = [
                'occurrences' => ($units[$key]['occurrences'] ?? 0) + 1,
                'tokens' => $unit['tokens'],
                'selected' => $unit['selected'],
            ];
        }
    }
    ksort($units, SORT_STRING);

    return $units;
}

/**
 * @param list<string> $collisions two units in one file sharing a name — the
 *        register keys on file+function, so it could describe only one of them
 * @return array<string,array{line:int,tokens:list<string>}>
 */
function pmg_candidates(string $base, array $roots, array &$collisions = []): array
{
    $all = [];
    $collisions = [];
    foreach (pmg_php_files($base, $roots) as $file) {
        $relative = substr($file, strlen(rtrim($base, '/')) + 1);
        foreach (pmg_file_units($file, $relative) as $unit) {
            if (!$unit['selected']) {
                continue;
            }
            if (isset($all[$unit['key']])) {
                $collisions[] = $unit['key'];
                continue;
            }
            $all[$unit['key']] = ['line' => $unit['line'], 'tokens' => $unit['tokens']];
        }
    }
    ksort($all, SORT_STRING);

    return $all;
}

// ---------------------------------------------------------------------------
// 1. The register's own shape.
// ---------------------------------------------------------------------------

$registerPath = $root . '/tools/platform-move-gates.json';
$registerRaw = file_get_contents($registerPath);
wprism_check(is_string($registerRaw) && $registerRaw !== '', 'tools/platform-move-gates.json is readable');
$register = json_decode((string) $registerRaw, true);
wprism_check(is_array($register), 'tools/platform-move-gates.json is a JSON object');
wprism_check_same('wprism-platform-move-gates/v1', $register['format'] ?? null, 'register declares its format');
wprism_check(
    is_array($register['sites'] ?? null) && array_is_list($register['sites']),
    'register carries a sites list'
);
wprism_check(
    is_array($register['anchors'] ?? null) && array_is_list($register['anchors']),
    'register carries an anchors list'
);
wprism_check(
    is_array($register['exclusions'] ?? null) && array_is_list($register['exclusions']),
    'register carries an exclusions list'
);

// Collected rather than asserted per entry: 76 sites times the six shape rules
// below is 456 `ok:` lines nobody reads, and the diagnostic a reviewer needs is
// the list of offenders, not the roll call. Clause 3 collects the same way.
$listed = [];
$verdicts = ['gate' => 0, 'carrier' => 0, 'derivation' => 0];
$nameless = [];
$duplicated = [];
$misordered = [];
$badVerdict = [];
$unexplained = [];
$badAxis = [];
$previousKey = '';
foreach ($register['sites'] as $index => $entry) {
    $site = is_array($entry) ? (string) ($entry['site'] ?? '') : '';
    if ($site === '') {
        $nameless[] = "sites[$index]";
        continue;
    }
    if (isset($listed[$site])) {
        $duplicated[] = $site;
    }
    if (strcmp($site, $previousKey) <= 0) {
        $misordered[] = $site;
    }
    $previousKey = $site;

    $verdict = (string) ($entry['verdict'] ?? '');
    if (isset($verdicts[$verdict])) {
        $verdicts[$verdict]++;
    } else {
        $badVerdict[] = "$site => '$verdict'";
    }
    // The mitigation the register exists for: an exclusion is a reviewed line,
    // never a silent omission. A `carrier`/`derivation` with no stated reason
    // is exactly the mute button this refuses to become.
    if (!is_string($entry['note'] ?? null) || strlen(trim((string) $entry['note'])) < 40) {
        $unexplained[] = $site;
    }
    $axes = $entry['axes'] ?? null;
    if (!is_array($axes) || !array_is_list($axes) || $axes === []
        || array_diff($axes, ['manifest', 'platform', 'authority']) !== []) {
        $badAxis[] = $site;
    }
    $listed[$site] = $entry;
}
wprism_check_same([], $nameless, 'every register entry names a site');
wprism_check_same([], $duplicated, 'no site is registered twice');
wprism_check_same([], $misordered, 'the register stays sorted by site, so a diff reads as one line');
wprism_check_same([], $badVerdict, 'every entry declares a verdict of gate/carrier/derivation');
wprism_check_same([], $unexplained, 'every entry states WHY — an exclusion without its reason is a silent omission');
wprism_check_same([], $badAxis, 'every entry names at least one of the manifest/platform/authority axes');
wprism_check($verdicts['gate'] > 0, 'the register names at least one gate (' . $verdicts['gate'] . ')');
fwrite(STDOUT, sprintf(
    "register: %d sites — %d gate, %d carrier, %d derivation\n",
    count($listed),
    $verdicts['gate'],
    $verdicts['carrier'],
    $verdicts['derivation']
));

// ---------------------------------------------------------------------------
// 2. Completeness: the tree's candidates and the register agree exactly.
// ---------------------------------------------------------------------------

$collisions = [];
$candidates = pmg_candidates($root, PMG_ROOTS, $collisions);
wprism_check(count($candidates) > 40, 'the predicate walked the shipped tree (' . count($candidates) . ' candidates)');
wprism_check_same([], $collisions, 'no two candidate units in one file share a name, so every key resolves to one site');

$unlisted = array_diff(array_keys($candidates), array_keys($listed));
wprism_check_same(
    [],
    array_values($unlisted),
    'every candidate the predicate selects is in tools/platform-move-gates.json'
        . ' — add it with a verdict and a note, `carrier`/`derivation` included'
);

$stale = array_diff(array_keys($listed), array_keys($candidates));
wprism_check_same(
    [],
    array_values($stale),
    'every registered site still exists in the tree — a key that stopped resolving is a real move'
);

// ---------------------------------------------------------------------------
// 3. The exclusions: each dropped site is a REVIEWED line, not a silent
//    omission. WP-0.6's stated risk is that a completeness check becomes noise
//    nobody reads; the answer is a narrow predicate, and the price of a narrow
//    predicate is that "why isn't X here?" must have a written answer that the
//    gate itself keeps true.
// ---------------------------------------------------------------------------

$units = pmg_units($root, PMG_ROOTS);
wprism_check(count($units) > count($candidates), 'the unit walk sees more than the selected set (' . count($units) . ' units)');

$absentExclusions = [];
$ambiguousExclusions = [];
$selectedExclusions = [];
$unexplainedExclusions = [];
$alsoRegistered = [];
$misorderedExclusions = [];
$wrongArm = [];
$previousExclusion = '';
foreach ($register['exclusions'] as $index => $entry) {
    $site = is_array($entry) ? (string) ($entry['site'] ?? '') : '';
    if ($site === '') {
        $absentExclusions[] = "exclusions[$index]";
        continue;
    }
    if (strcmp($site, $previousExclusion) <= 0) {
        $misorderedExclusions[] = $site;
    }
    $previousExclusion = $site;

    // Exists: an exclusion whose site was renamed away is stale prose, and
    // stale prose about what a gate does NOT cover is worse than none.
    $unit = $units[$site] ?? null;
    if ($unit === null) {
        $absentExclusions[] = $site;
    } elseif ($unit['occurrences'] > 1) {
        $ambiguousExclusions[] = $site;
    }
    // Still dropped: this is the ratchet against a silently widened arm.
    if (isset($candidates[$site])) {
        $selectedExclusions[] = $site;
    }
    if (isset($listed[$site])) {
        $alsoRegistered[] = $site;
    }
    if (!is_string($entry['reason'] ?? null) || strlen(trim((string) $entry['reason'])) < 40) {
        $unexplainedExclusions[] = $site;
    }
    // `arm` is re-derived, not taken on trust: it is the first thing a reviewer
    // reads to know whether the drop is "names no identity value" or "names one
    // and compares nothing", and a wrong arm sends them to the wrong half of
    // the predicate. `identity` means arm 1 found no token; `decision` means it
    // found one and arm 2 found no operator, throw or guard call.
    $arm = $entry['arm'] ?? null;
    $expected = $unit === null ? null : ($unit['tokens'] === [] ? 'identity' : 'decision');
    if ($unit !== null && $arm !== $expected) {
        $wrongArm[] = "$site => declared '" . (is_string($arm) ? $arm : gettype($arm)) . "', derived '$expected'";
    }
}
wprism_check_same([], $absentExclusions, 'every excluded site still exists in the tree — a renamed exclusion is stale prose');
wprism_check_same([], $ambiguousExclusions, 'every excluded site resolves to exactly one unit, so the reason describes one thing');
wprism_check_same(
    [],
    $selectedExclusions,
    'no excluded site is selected by the predicate — an arm widened until it selects one must be reviewed, not absorbed'
);
wprism_check_same([], $alsoRegistered, 'no site is both registered and excluded');
wprism_check_same([], $unexplainedExclusions, 'every exclusion states WHY it is not a gate on one of the three axes');
wprism_check_same([], $misorderedExclusions, 'the exclusions stay sorted by site');
wprism_check_same([], $wrongArm, 'every exclusion names the arm that actually dropped it, re-derived from the tree');
wprism_check(count($register['exclusions']) > 0, 'the register records at least one reviewed exclusion');
fwrite(STDOUT, 'exclusions: ' . count($register['exclusions']) . " reviewed drops re-derived from the tree\n");

// ---------------------------------------------------------------------------
// 4. The anchors: the nine sites this register was built from stay gates.
//    A predicate edit that stops selecting one of them is a weakening, and it
//    fails here rather than in the diff nobody re-derived.
// ---------------------------------------------------------------------------

$unselectedAnchors = [];
$demotedAnchors = [];
foreach ($register['anchors'] as $anchor) {
    if (!isset($candidates[(string) $anchor])) {
        $unselectedAnchors[] = (string) $anchor;
    }
    if (($listed[(string) $anchor]['verdict'] ?? null) !== 'gate') {
        $demotedAnchors[] = (string) $anchor;
    }
}
wprism_check_same([], $unselectedAnchors, 'every anchor is still selected by the predicate — an arm that stops selecting one is a weakening');
wprism_check_same([], $demotedAnchors, 'every anchor is still recorded as a gate');
fwrite(STDOUT, 'anchors: ' . count($register['anchors']) . " re-derived from the tree\n");

// The headline refusals AGENTS.md and the operator-facing surfaces quote. If a
// rename moves one of these, the register must move with it in the same change.
$namedRefusals = [
    'agent/src/Repository/CompiledArtifactReader.php::read_artifact' => 'manifest',
    'agent/src/Policy/ManifestDispositions.php::platform_boundary' => 'platform',
    'cli/src/Contract/ContractAttestation.php::verify' => 'platform',
    'agent/src/Adapter/AdapterCertification.php::verifyCertificate' => 'platform',
];
$missingRefusals = [];
foreach ($namedRefusals as $site => $axis) {
    if (!isset($listed[$site]) || !in_array($axis, (array) ($listed[$site]['axes'] ?? []), true)) {
        $missingRefusals[] = "$site ($axis)";
    }
}
wprism_check_same([], $missingRefusals, 'the four refusals AGENTS.md and the CLI quote are registered on their axis');

// ---------------------------------------------------------------------------
// 5. The comparison itself fails on an unlisted candidate. Asserting the diff
//    machinery rather than trusting it: a register that silently matched an
//    empty candidate set would pass clause 2 forever.
// ---------------------------------------------------------------------------

// Scoped to the candidates the register already carries, so this clause proves
// the diff machinery alone and does not double-report a clause-2 failure.
$probe = (string) $register['anchors'][0];
$registered = array_intersect_key($candidates, $listed);
$withoutProbe = $listed;
unset($withoutProbe[$probe]);
wprism_check_same(
    [$probe],
    array_values(array_diff(array_keys($registered), array_keys($withoutProbe))),
    "dropping $probe from the register surfaces it as unlisted"
);

// ---------------------------------------------------------------------------
// 6. The predicate itself, against a synthetic root: it selects a new gate, and
//    it does NOT select the three shapes that made this register noisy before
//    arm 1's three carve-outs were added.
// ---------------------------------------------------------------------------

$scratch = sys_get_temp_dir() . '/wprism-platform-move-gates-' . getmypid() . '-' . bin2hex(random_bytes(4));
wprism_check(mkdir($scratch . '/synthetic', 0700, true), 'synthetic root created');
file_put_contents($scratch . '/synthetic/Probe.php', <<<'PHP'
<?php
final class Probe {
    /** A new gate: compares a manifest hash and refuses. */
    public static function assertPinned(array $artifact, string $active): void {
        if (!hash_equals((string) $artifact['manifest_hash'], $active)) {
            throw new \RuntimeException('wprism: synthetic manifest mismatch');
        }
    }

    /** A shape gate with no operator of its own, like ScopedApplySession. */
    public static function shape(array $source): void {
        self::assertKeys($source, ['artifact_hash', 'manifest_hash']);
    }

    /** Prose only. manifest_hash and platform_boundary named in a comment. */
    public static function commentary(int $n): bool {
        // manifest_hash, resolved_adapters and the platform_boundary live here
        // as rationale, exactly the way rule 10 asks for. Not a gate.
        return $n === 1;
    }

    /** A bootstrap: a class name and a require path, neither an identity value. */
    public static function boot(string $s): bool {
        require_once __DIR__ . '/ManifestDispositions.php';
        return class_exists(ManifestDispositions::class) && hash_equals($s, $s);
    }
}
PHP);

$synthetic = pmg_candidates($scratch, ['synthetic']);
wprism_check_same(
    ['synthetic/Probe.php::assertPinned', 'synthetic/Probe.php::shape'],
    array_keys($synthetic),
    'the predicate selects a new gate and a bare shape gate, and nothing else'
);
wprism_check_same(
    ['manifest_hash'],
    $synthetic['synthetic/Probe.php::assertPinned']['tokens'],
    'the selected gate reports the identity token it matched on'
);

@unlink($scratch . '/synthetic/Probe.php');
@rmdir($scratch . '/synthetic');
@rmdir($scratch);

// The SSH certification targets pay their Docker setup cost before reading the
// platform pin. A stale source-layout path would therefore escape syntax-only
// review and fail late. Keep the two wired readers on the checked-in platform
// authority and refuse the retired flat-library path without provisioning.
$certificationReaders = [
    'sandbox/tests/certify/certify_ssh_adoption_roundtrip.sh',
    'sandbox/tests/certify/certify_ssh_rollback.sh',
];
foreach ($certificationReaders as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    wprism_check(
        str_contains($source, 'platform/adapter-library/capabilities/platform.json'),
        "$relative reads the checked-in platform authority"
    );
    wprism_check(
        !str_contains($source, 'manifests/capabilities/platform.json'),
        "$relative cannot regress to the retired flat-library path"
    );
}

wprism_check_summary('regress-platform-move-gates');
