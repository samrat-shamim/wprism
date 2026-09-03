<?php
/**
 * WP-4.4: the reviewed claim source is one document per subject, and the
 * current WPrism identity baseline is pinned through the product path
 * (spec/repo-format.md § v3.4).
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * `manifests/dispositions.json` was 302 lines, 37,707 bytes and 16 entries in
 * one file; the current library has one package-local disposition per adapter
 * plus `platform/adapter-library/core/disposition.json` — 18 subjects in all.
 * That is a
 * relocation of bytes AGENTS.md rule 2 calls adapter identity:
 * `ArtifactPolicyIdentity::manifest_rows()` folds each manifest's own
 * disposition into that adapter's row and the row hashed IS its `digest`, so a
 * one-byte canonical difference in one document would move that adapter's
 * digest, every `site.wprism.json` content pin naming it, and — through
 * `manifest_hash` — every compiled artifact in the field. The WPrism
 * greenfield baseline below pins the current 18-subject identity map through
 * two maximal compatible 17-subject policy worlds, so a split-induced byte
 * change is still a measured fleet-visible failure.
 *
 * THE GATE ASSERTION, AND WHY IT IS NOT A TAUTOLOGY
 * ------------------------------------------------
 * PART 1 pins the current WPrism 18-subject digest union, each compatible
 * world's `manifest_hash` and snapshot, and `registry_sha256` as LITERALS
 * captured from this greenfield tree, through the product path a deployed
 * site uses. Recomputing both sides of an equality would prove nothing — it
 * would hold whatever the split did to the bytes — so the expected values are
 * frozen text in this file and the comparison is against the engine. The
 * older split-transition values remain below as historical exact maps; they
 * are not the current baseline.
 *
 * PART 2 is what makes that comparison a measurement. It enumerates the
 * canonical-encoding hazards a relocation of JSON can introduce and measures
 * each one through the same product path, in three verdicts rather than one:
 *
 *   - a nested LIST re-ordered and a UTF-8 prose `reason` re-composed each MOVE
 *     a digest, so PART 1 would have named the adapter a splitter corrupted
 *     that way;
 *   - map KEY order at every nesting level moves nothing, which is the one
 *     property that makes lifting an entry out of a document admissible at all
 *     (Canon sorts keys everywhere, Canon.php:44,58);
 *   - the int/float round trip is the hazard the digest CANNOT catch — Canon
 *     erases it — so its guard is the census beside it: no shipped reviewed
 *     member is a number, and that assertion is a tripwire, not trivia.
 *
 * A suite that reported three passes here would be hiding the third answer,
 * which is the one a future reviewer needs.
 *
 * PART 3 is the refusals. Every per-entry rule, the frozen root rule and the
 * profile rules fire from the split form in their existing wording, the
 * coverage refusal for a PINNED subject with no document is byte-identical to
 * the monolith's, and the three rules the DIRECTORY adds refuse by name.
 */
declare(strict_types=1);

// WP-4.12: derived from agent/wprism.php, not retyped. This suite reads the
// SHIPPED platform.json (through Policy::load -> AdapterRegistry), and that
// document restates both defines — so a literal here disagrees with the tree
// the moment the defines move and the suite dies on "platform version
// disagrees with the loaded agent" instead of reporting anything about
// dispositions. See sandbox/tests/lib/agent_version.php.
require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterRegistry.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';

use WPrism\ArtifactPolicyIdentity;
use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\ManifestDispositions;
use WPrism\Policy;

$repo = dirname(__DIR__, 4);
$adapterLibrary = AdapterLibrary::fromSourceTree($repo);
$manifestPath = static function (string $name) use ($adapterLibrary): string {
    return $adapterLibrary->package($name)?->manifestPath()
        ?? throw new RuntimeException("shipped adapter package '$name' is absent");
};

/**
 * One stable scratch root under sandbox/tmp (AGENTS.md rule 3), cleared before
 * use: nothing else writes this path, and a per-pid name would leave a fixture
 * behind on every red run.
 */
$scratchRoot = $repo . '/sandbox/tmp/disposition-split';
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeTree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
};
$removeTree($scratchRoot);
register_shutdown_function(static function () use ($removeTree, $scratchRoot): void {
    if (wprism_check_failed() === 0) {
        $removeTree($scratchRoot);
    }
});

// ---------------------------------------------------------------------------
echo "\nPART 1 — THE GATE: every shipped digest matches the explicit WPrism baseline\n";
// ---------------------------------------------------------------------------
// Historical capture from the pre-split tree (adapter-program @ ed852db6),
// retained as the exact input to the transition overlays below. It covered the
// then-shipped 16-subject set; it is not the current WPrism expected map.
$frozenDigests = [
    'acf' => '59bcfb5c04958c9e4b340f2c47772b245f4afee15e358eef692c0887365450c3',
    'advanced-editor-tools' => 'f053a9a1974869ae957795357282250ef664194ed3be192d97222789a4b755c3',
    'classic-editor' => '33ba7b66daadcf52f0103d7a6abd2594d98067ba22f82e0c2751ac25e106bd29',
    'code-snippets' => 'b65ed9be8c7ddbe4436bf98bbaf0bb1dbf412b67817f1884082fe1df56c1851b',
    'contact-form-7' => '8b85b02e7cc816799be570b1e86e0fa16502628771abd66dbe74415e85f34553',
    'core' => '9c07275d02d726336a2270ce4260403f40dc406c3353470adf714cf753905aa2',
    'wprism-agency-cpt' => '77ba41d17579c97ee27cd30a3eac67224c66f9244d74e349b29035cd3ae95362',
    'elementor' => '80df69cc238bf8859b02635519d562e069442b5948091fe666bfaab81d4bf42b',
    'ninja-forms' => '54a069f27fb1518f7a7dee825fb004cf1cdecb95024f83f6b51fd87e7d32352c',
    'paid-memberships-pro' => 'ec1109615042d839f4958319bfe1b52be98f95a3024748c91f92f6ce5d27bf72',
    'polylang' => '99a32be4ecbd016f548f3e9352855281a8a9a6d3775322c5f71b599f0f95c07d',
    'the-events-calendar' => '8e9bc8095835916f70866c903e520610c18fd760ed246e4b755389e281e2cf61',
    'woocommerce' => 'fe4edd96fed1ab7dc54a204bf270792cb01f7f434c51897beab27055c0133b0b',
    'wps-hide-login' => 'd98e5643027fdf941239a32c4e95bbd2bac68a29b326e0b4fbb5a9a3395e060e',
    'yoast' => '652bff48b6f28beb40e667da31f498a7d0d7dde68693e3c2f92977affe6cdbaa',
    'yoast-duplicate-post' => '123547fbbbdcba321dbc99d6d16482e443f3eed5edb0d2ac7965690a23144fa3',
];
const SPLIT_FROZEN_MANIFEST_HASH = '937450d86d3ac3e216979c204e3952c543572ce194da5bc34b2a0c9c3e891393';
const SPLIT_FROZEN_REGISTRY_SHA = '8d6c35cfe4c5f21193e83cc4707a11359680df8e95e48e087387fe00ef491744';
const SPLIT_FROZEN_SNAPSHOT_SHA = 'c9ef88ac0f92ba04411de26738b974deca77600c8e79947b53e927703cf93bbc';

/**
 * Historical reviewed changes that moved identities after that capture: #561
 * rewrote manifests/the-events-calendar.json and promoted
 * its disposition experimental -> certified, and widened manifests/core.json's
 * native rewrite action to declare TEC's rewrite-listener effects. The
 * reviewed Polylang and WooCommerce production-readiness ports then rewrote
 * their manifests and executable sets, while Yoast gained Woo's permalink
 * reindex trigger. PMPro's reviewed engine-absorption move later replaced its
 * provider with generic invalidation; the manifest-provider runtime then moved
 * eight more manifest identities. Moving the shipped packages later exposed
 * six runtime files whose relative WpCliChildProcess dependency was no longer
 * valid in either supported layout. Correcting those paths moved five of the
 * same ten adapter digests again; regress_spec_v3_digest_neutrality.php pins
 * every corrected runtime file byte and the five resulting digests.
 * Rule 2 makes each of those historical transitions fleet-visible BY DESIGN.
 *
 * The frozen map and these overlays remain historical evidence only. The
 * current WPrism gate is the explicit 18-subject map below, observed as the
 * union of two valid maximal policy worlds; it does not infer an unmoved or
 * moved count from the pre-split capture.
 */
const SPLIT_REVIEWED_MOVED_ADAPTERS = [
    'code-snippets',
    'core',
    'elementor',
    'ninja-forms',
    'paid-memberships-pro',
    'polylang',
    'the-events-calendar',
    'woocommerce',
    'yoast',
    'yoast-duplicate-post',
];
const SPLIT_REVIEWED_MOVED_DIGESTS = [
    'code-snippets' => 'f4f235fcbb7349c3254fd91bebb0cc7922e02e113c898611963099ee2a60cb6b',
    'core' => '2d72608ff976c3b050062c126128549f0711a84203ef28f17d594728afb18858',
    'elementor' => '5b5a1791f24a44dc49e853db84c7027ed4af6791f6cba6ad111c941cb73b2752',
    'ninja-forms' => 'd5be1f2b39fc535762c3e7553cfde5f750c426567e5e0893001cea5238e74176',
    'paid-memberships-pro' => '59e95f6f2089cb7b37787920ae62a9adbc83f6f7b4c673f611aa62fdb8fe2880',
    'polylang' => 'd79be83046ea30fabb0298225e60cb104d2d445d715651b8b104ab30b1c9742d',
    'the-events-calendar' => '0a6d67877140db53304d041842f29c7707feab0248ded5cca8a5df992ef2148b',
    'woocommerce' => 'fc23c5cf46e51afe6d8a5e19abff9cd8793aec0cb854e4cfd23be0fa7f073f9b',
    'yoast' => '6c030625e5b8c2e8569adceca24c9e054c6bb0cf62d1ec7bcf6c22b5c84a2f80',
    'yoast-duplicate-post' => '9c17439fc670eebbe216133abbe57dd0e9add20ccf8f1897c2f4445013c65e75',
];
const SPLIT_REVIEWED_MANIFEST_HASH = '41547ea08901dd1d804850db3485a2f712be0894525285ae6fdfb1f9bb5f16a1';
const SPLIT_REVIEWED_REGISTRY_SHA = 'a9b7fdbb8d7c62e78ac8ca1c10a395aa0dc54079fb54cef2809c71babf395f2e';
const SPLIT_REVIEWED_SNAPSHOT_SHA = '783cc9483f6f45c5676f80e5987553292c69867f12297ec2b8e747d7d68f748f';

/**
 * The second reviewed overlay: six runtime dependency-path corrections inside
 * five adapters. Keep the prior ten-adapter literals above intact so this
 * suite proves both transitions and cannot misattribute these identity moves
 * to disposition splitting or physical package relocation.
 */
const SPLIT_PACKAGE_DEPENDENCY_MOVED_ADAPTERS = [
    'elementor',
    'ninja-forms',
    'polylang',
    'woocommerce',
    'yoast',
];
const SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS = [
    'elementor' => 'c13aceb84019223e88f292168655d7f435ae2ab92e68cc84ca38602f86b94383',
    'ninja-forms' => '8100c32a2f46e91ad4daae9c80ab61d33092b54d7810e533aedd265d4ae166c9',
    'polylang' => 'ec697d7e5baa1b2847e16e7aceeacf3942d8a3d73297313f9d50d7128b068f75',
    'woocommerce' => '40f089f1f19db8846074c2ac6858aad69a009c2b5eb1d5f04c68bcdd16967c39',
    'yoast' => '3edb81748cf3e84923779a74a913f339889a8f9cd430342dabdcfa0dbd3ceae2',
];
const SPLIT_PACKAGE_DEPENDENCY_MANIFEST_HASH = '8d3f7ba1afe8dc9e63a6991da2ad412d8718796b0c31278b4a8dca97fda672c4';

/**
 * The third reviewed overlay fixes the WooCommerce scheduler provider's
 * assumption that stock WordPress reports a boolean local object-cache state.
 * Core returns null when no external cache drop-in was loaded, so the runtime
 * byte, WooCommerce digest, and every intersecting manifest hash move together.
 */
const SPLIT_LOCAL_CACHE_MOVED_DIGESTS = [
    'woocommerce' => '918bf4c4ef23d6b5ac73f66f24e0a51be533845ab0f4222a7190ea74cfe01bf3',
];
const SPLIT_LOCAL_CACHE_MANIFEST_HASH = '9110bee6b889f4309ef0a85e93f0bd5fc81bbc39f01455fbda01bc63527a3bea';

/** The fourth overlay admits only the exact WordPress 7.1 and Woo 11.0.1 native option-hook callbacks. */
const SPLIT_NATIVE_HOOK_MOVED_DIGESTS = [
    'woocommerce' => 'aceb8cbf443a8b2787f25938c5687855883192b8df313c4446e4e36b7d39ccad',
];
const SPLIT_NATIVE_HOOK_MANIFEST_HASH = 'bd63504adf239e544149f973780087b8ed885ec7d9393baba9382f75d8755a8c';

/** The fifth overlay admits Action Scheduler's exact completed-migration store and logger selectors. */
const SPLIT_DATA_STORE_HOOK_MOVED_DIGESTS = [
    'woocommerce' => '8274ba1c78171149bda79b57afa2fa882053a2327f8ed89d81b03e336bc7703b',
];
const SPLIT_DATA_STORE_HOOK_MANIFEST_HASH = '0f2225d6d765789375e9a134479447727236c7fdaeadcd8d10df12ed7186093f';

/** The sixth overlay normalizes MariaDB's exact display of Action Scheduler's native unsigned priority. */
const SPLIT_MARIADB_PRIORITY_MOVED_DIGESTS = [
    'woocommerce' => 'e6a266d3a0d6341b11348b871664cc9e883b23141ad50e247bf30c50563aac7c',
];
const SPLIT_MARIADB_PRIORITY_MANIFEST_HASH = '9f0fff8fa880daa24d175bbbd3b498dcab8bacc3affd611f069733fa79668e7f';

/** The seventh overlay normalizes MariaDB's full-width Action Scheduler args index report. */
const SPLIT_MARIADB_ARGS_INDEX_MOVED_DIGESTS = [
    'woocommerce' => '87b13af34d6bf63f91fa8a3406215a97cbd47a23b073a2e3a253437b3369a4ce',
];
const SPLIT_MARIADB_ARGS_INDEX_MANIFEST_HASH = '3ff9b18f630d3096635553f8eb1d97d20e06db18efc95c3ec349d7d7dc3c045e';

/** The eighth overlay recognizes Woo's exact native shared retention-hook topology. */
const SPLIT_RETENTION_NATIVE_HOOK_MOVED_DIGESTS = [
    'woocommerce' => '3c626ae613ff9073c24f89941c0b00079a5284262a60325fee37ee7be5ba07c1',
];
const SPLIT_RETENTION_NATIVE_HOOK_MANIFEST_HASH = 'ec06dbaec5e4822b55b7a5e99a886583d37792431ad71e2649e922d07a8edfdc';

/** The ninth overlay binds the two native background-process instances that own Woo's cron filters. */
const SPLIT_RETENTION_CRON_OWNER_MOVED_DIGESTS = [
    'woocommerce' => 'e2e99c1f086fc9c080d0b4d129f56d74dd3f19cfa50fb91ca6b5adc366b0a176',
];
const SPLIT_RETENTION_CRON_OWNER_MANIFEST_HASH = '1fbcd7d59b44147932751a32bad7b28f6ab8c37caf26b52e059158d147740caf';

/** The tenth overlay adds Woo's bounded asynchronous lifecycle-migration settlement provider. */
const SPLIT_LIFECYCLE_SETTLEMENT_MOVED_DIGESTS = [
    'woocommerce' => 'c62ce1317b08d2b10fa6e6bfb62bf640e4a8fc7a8021741ea9dee2063ffa3060',
];
const SPLIT_LIFECYCLE_SETTLEMENT_MANIFEST_HASH = 'ebf0a904a8fa73b72c0b9a0dad58016a7ad640d18177ab2829c74f0ddcd38502';
const SPLIT_LIFECYCLE_SETTLEMENT_SNAPSHOT_SHA = '938b9214d772604a1aab3574e4b447d362d5a7c4aac6938035c3c52aab9adaeb';

/** The prior Rank Math identity, retained because moving schema establishment before observation is fleet-visible. */
const WPRISM_PRE_SCHEMA_SETTLEMENT_RANK_MATH_DIGEST = 'd1824bb11311adcae5e780ae5d67f8279ea1c3f2dd2d9885797f2d2cad5a726e';
const WPRISM_PRE_SCHEMA_SETTLEMENT_MANIFEST_HASH = '56d2f33bf18edabfb3e83699406bb289eab27f321ab55544eca4ea80da676eea';
const WPRISM_PRE_SCHEMA_SETTLEMENT_REGISTRY_SHA = '6fea0ec625cfde7b271fcc55e1610008784994c08a81df0f3ef4cb3172bcd147';
const WPRISM_PRE_SCHEMA_SETTLEMENT_SNAPSHOT_SHA = '378c41299b4a3a7dab67ff7f0c5007ae907d8dbd6051edc2de1705c8d795a937';

/** The prior Rank Math baseline, retained because closing native callback/readback authority moves shipped bytes. */
const WPRISM_PRE_RANK_MATH_HARDENING_DIGEST = '16b935f76aee22e2a709c138ef90a8c942e5a89842c9fe392d9025824dba6bff';
const WPRISM_PRE_RANK_MATH_HARDENING_MANIFEST_HASH = 'c90f7a6ccf5722acf10cbb6ea2fc7592488bfd15ec70c6bafa07f18ce77c162d';
const WPRISM_PRE_RANK_MATH_HARDENING_SNAPSHOT_SHA = '6214faf30fc56e65a5108256d89db3713227f21b38bf5241be54ca86049e3010';

/**
 * The duplicate durable-context declaration correction changes only the
 * WooCommerce manifest row. Preserve the immediately preceding fleet-visible
 * values: the disposition registry itself is byte-identical, while the
 * adapter digest, aggregate manifest hash and policy snapshot must move.
 */
const PRE_CONTEXT_CHANNEL_WOOCOMMERCE_DIGEST = '62331a62fe4a934d4ec6a82ac926650844f31f6aa98df7e501b49d2044eceb35';
const PRE_CONTEXT_CHANNEL_MANIFEST_HASH = '66e76a18e9731c2f2b85a9023f816b53d6c5ef4ddb5ed4e4ba826f3e4b3d7c08';
const PRE_CONTEXT_CHANNEL_REGISTRY_SHA = '99e02ee9b61b7b471b9e651e140efbe396dd437851c6ef7dac4a725d3ff18faa';
const PRE_CONTEXT_CHANNEL_SNAPSHOT_SHA = '0c861c30900f044571821184dd865d2666540634a8b885bd52fda71330a5ccc7';
const POST_CONTEXT_CHANNEL_MANIFEST_HASH = '713b9224ff4de40e4ff309636875343a3b799f0e91356fff844c1c7c5488edc5';
const POST_CONTEXT_CHANNEL_REGISTRY_SHA = '99e02ee9b61b7b471b9e651e140efbe396dd437851c6ef7dac4a725d3ff18faa';
const POST_CONTEXT_CHANNEL_SNAPSHOT_SHA = 'db1408562dd3afa8c9fbfe3cdef208fe4ebea4df3261dd7677407b9961ee10ae';

/**
 * The current WPrism greenfield baseline. Unlike the historical split
 * overlays above, this map includes every currently shipped subject,
 * including Redirection, and is the only expected identity set used against
 * the live source tree below. These literals are intentionally explicit:
 * changing a package or disposition requires a deliberate re-pin.
 */
const WPRISM_CURRENT_DIGESTS = [
    'acf' => 'c86d0888237d2b9cfce09f5287d03c6cc4bda46c768f15a32bfab9101ba2307d',
    'advanced-editor-tools' => 'cfc61d12273c7b72cd24c9a7cf2a4b2dd2b08a8a3b261f43c96893aa3ba4492d',
    'classic-editor' => '908c6cd00f9cd389b40105bbb1f906ae5271ad13dfafcbc65d4face4ff2156ea',
    'code-snippets' => 'ca66c5959ea2fa0d0fc39b6d5d2f3da0a866c728bd8eb2b9c0d942455054fc04',
    'contact-form-7' => 'fc544747e494f54e7fb574643c5a4b3c8c5f789aecf27f8a35a7af7d5b0c06b5',
    'core' => '9f9a23cfb2be0b8dd693cecd1df6adb4e9082ca8d589675ce95bfae85c185b63',
    'elementor' => '5383779c98b51bb94e2aab363d72729fd55003798656f7d025f8773ae5793d64',
    'ninja-forms' => '35d804bf74779db8ac50ea9e15ef28a26b5917e1417f701a108519244e4b1011',
    'paid-memberships-pro' => 'e518a516bb44d144cff92bdb423c04813847064fe7113ac4e1cfc386ba37f253',
    'polylang' => '60edabfdaab55d4ea74d0c6ac71228bffc2b2911afe57a962ac898ee73064548',
    'rank-math' => '9b3501919f8085def74dd8a465afad37d846d01d1e741f75ceab722674f7c01d',
    'redirection' => '6ba607e26345be23b0a89eeee69dadc0ceff75ca40b8cdf9d0ab88d066303bc7',
    'the-events-calendar' => 'cad93805c2c5689002346f24fc766c58bfda075b669d9c7c1f16542d8b9ac9ee',
    'woocommerce' => 'c66724d62f9410b43208b172ebdf95f6a5003b81a00dd6137401da4ae5cc94e3',
    'wprism-agency-cpt' => '174e37838bab6f855d1fb756c5d252d4106e7c246febc807e82bfe6384a3f4ab',
    'wps-hide-login' => '4734afd32e2f9558f4fb13a1d56076e77a14c6e15f904bbee2c92b381d381050',
    'yoast' => '565673dd40899c736e615add51d6e39f51aaa7e8b42b986c183ea279c54c5eea',
    'yoast-duplicate-post' => '1c1982d1def124a61abe5a9ee2f6859d6a65711f11b38a5c6e3f6c40b4f71456',
];
const WPRISM_CURRENT_RANK_WORLD_MANIFEST_HASH = 'af6d9ef7bc08c3bc7595059a5a617cbba22df5528fdbafefafe2f4fca93d20f0';
const WPRISM_CURRENT_YOAST_WORLD_MANIFEST_HASH = '713b9224ff4de40e4ff309636875343a3b799f0e91356fff844c1c7c5488edc5';
const WPRISM_CURRENT_REGISTRY_SHA = '3adb3ba42758e60e8130f6790cc93d2e7097edc863c81e826d342a7adf60f65c';
const WPRISM_CURRENT_RANK_WORLD_SNAPSHOT_SHA = 'bfb52ac1a0e735382b6593064b3689d4e41fb19ebbb42e9865d49cb26af8b079';
const WPRISM_CURRENT_YOAST_WORLD_SNAPSHOT_SHA = '4e145d6468909bc1858a6b98e9d758d625f8d4e63aebb04ffdf4247cec33b783';

$shippedRegistry = ManifestDispositions::load_library($adapterLibrary);
wprism_check(
    $shippedRegistry instanceof ManifestDispositions,
    'the shipped library loads its reviewed claim source from the per-subject directory'
);
$shippedNames = array_keys(WPRISM_CURRENT_DIGESTS);
$rankWorldPins = array_values(array_diff($shippedNames, ['yoast']));
$yoastWorldPins = array_values(array_diff($shippedNames, ['rank-math']));
$shippedPolicies = [
    'rank-world' => Policy::load(null, $rankWorldPins, adapterLibrary: $adapterLibrary),
    'yoast-world' => Policy::load(null, $yoastWorldPins, adapterLibrary: $adapterLibrary),
];
$observed = [];
foreach ($shippedPolicies as $world => $policy) {
    foreach (ArtifactPolicyIdentity::resolved_adapters($policy) as $row) {
        $name = (string) $row['name'];
        $digest = (string) $row['digest'];
        if (isset($observed[$name]) && $observed[$name] !== $digest) {
            throw new RuntimeException("adapter '$name' has inconsistent identity across compatible world '$world'");
        }
        $observed[$name] = $digest;
    }
}
ksort($observed, SORT_STRING);
$worldUnion = array_values(array_unique(array_merge($rankWorldPins, $yoastWorldPins)));
sort($worldUnion, SORT_STRING);
wprism_check_same($shippedNames, $worldUnion, 'the two maximal compatible worlds jointly cover every shipped subject');
wprism_check_same(['rank-math'], array_values(array_diff($rankWorldPins, $yoastWorldPins)), 'the Rank-compatible world differs only by Rank Math');
wprism_check_same(['yoast'], array_values(array_diff($yoastWorldPins, $rankWorldPins)), 'the Yoast-compatible world differs only by Yoast');
wprism_check_same(
    array_keys(SPLIT_REVIEWED_MOVED_DIGESTS),
    SPLIT_REVIEWED_MOVED_ADAPTERS,
    'the historical split transition names exactly the adapters recorded in its explicit digest map; later WPrism identity changes are pinned separately'
);
$packageDependencyMovedNames = [];
foreach (SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_REVIEWED_MOVED_DIGESTS[$name] ?? $frozenDigests[$name] ?? null;
    if ($priorDigest !== $digest) {
        $packageDependencyMovedNames[] = $name;
    }
}
wprism_check_same(
    SPLIT_PACKAGE_DEPENDENCY_MOVED_ADAPTERS,
    $packageDependencyMovedNames,
    'the package dependency-path overlay changes exactly five prior digest literals, preserving the earlier values '
    . 'as evidence of the second intentional identity transition rather than overwriting them'
);
$localCacheMovedNames = [];
foreach (SPLIT_LOCAL_CACHE_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $localCacheMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $localCacheMovedNames,
    'the stock-WordPress local-cache correction changes only the WooCommerce digest and preserves both prior values '
    . 'as evidence of the third intentional identity transition'
);
$nativeHookMovedNames = [];
foreach (SPLIT_NATIVE_HOOK_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_LOCAL_CACHE_MOVED_DIGESTS[$name]
        ?? SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $nativeHookMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $nativeHookMovedNames,
    'the exact WordPress and WooCommerce native-hook correction changes only WooCommerce and preserves the third '
    . 'transition as a separate reviewed identity'
);
$dataStoreHookMovedNames = [];
foreach (SPLIT_DATA_STORE_HOOK_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_LOCAL_CACHE_MOVED_DIGESTS[$name]
        ?? SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $dataStoreHookMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $dataStoreHookMovedNames,
    'the completed-migration Action Scheduler selector correction changes only WooCommerce and preserves the fourth '
    . 'transition as a separate reviewed identity'
);
$mariaDbPriorityMovedNames = [];
foreach (SPLIT_MARIADB_PRIORITY_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_DATA_STORE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_LOCAL_CACHE_MOVED_DIGESTS[$name]
        ?? SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $mariaDbPriorityMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $mariaDbPriorityMovedNames,
    'the MariaDB native-priority normalization changes only WooCommerce and preserves the fifth transition as a '
    . 'separate reviewed identity'
);
$mariaDbArgsIndexMovedNames = [];
foreach (SPLIT_MARIADB_ARGS_INDEX_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_MARIADB_PRIORITY_MOVED_DIGESTS[$name]
        ?? SPLIT_DATA_STORE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_LOCAL_CACHE_MOVED_DIGESTS[$name]
        ?? SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $mariaDbArgsIndexMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $mariaDbArgsIndexMovedNames,
    'the MariaDB full-width args-index normalization changes only WooCommerce and preserves the sixth transition as '
    . 'a separate reviewed identity'
);
$retentionNativeHookMovedNames = [];
foreach (SPLIT_RETENTION_NATIVE_HOOK_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_MARIADB_ARGS_INDEX_MOVED_DIGESTS[$name]
        ?? SPLIT_MARIADB_PRIORITY_MOVED_DIGESTS[$name]
        ?? SPLIT_DATA_STORE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_LOCAL_CACHE_MOVED_DIGESTS[$name]
        ?? SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $retentionNativeHookMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $retentionNativeHookMovedNames,
    'the native shared retention-hook correction changes only WooCommerce and preserves the seventh transition as '
    . 'a separate reviewed identity'
);
$retentionCronOwnerMovedNames = [];
foreach (SPLIT_RETENTION_CRON_OWNER_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_RETENTION_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_MARIADB_ARGS_INDEX_MOVED_DIGESTS[$name]
        ?? SPLIT_MARIADB_PRIORITY_MOVED_DIGESTS[$name]
        ?? SPLIT_DATA_STORE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_LOCAL_CACHE_MOVED_DIGESTS[$name]
        ?? SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $retentionCronOwnerMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $retentionCronOwnerMovedNames,
    'the native background-process ownership correction changes only WooCommerce and preserves the eighth '
    . 'transition as a separate reviewed identity'
);
$lifecycleSettlementMovedNames = [];
foreach (SPLIT_LIFECYCLE_SETTLEMENT_MOVED_DIGESTS as $name => $digest) {
    $priorDigest = SPLIT_RETENTION_CRON_OWNER_MOVED_DIGESTS[$name]
        ?? SPLIT_RETENTION_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_MARIADB_ARGS_INDEX_MOVED_DIGESTS[$name]
        ?? SPLIT_MARIADB_PRIORITY_MOVED_DIGESTS[$name]
        ?? SPLIT_DATA_STORE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_NATIVE_HOOK_MOVED_DIGESTS[$name]
        ?? SPLIT_LOCAL_CACHE_MOVED_DIGESTS[$name]
        ?? SPLIT_PACKAGE_DEPENDENCY_MOVED_DIGESTS[$name]
        ?? SPLIT_REVIEWED_MOVED_DIGESTS[$name]
        ?? $frozenDigests[$name]
        ?? null;
    if ($priorDigest !== $digest) {
        $lifecycleSettlementMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $lifecycleSettlementMovedNames,
    'the bounded lifecycle-migration settlement provider changes only WooCommerce and preserves the ninth '
    . 'transition as a separate reviewed identity'
);
$postContextChannelDigests = WPRISM_CURRENT_DIGESTS;
unset($postContextChannelDigests['rank-math']);
$preContextChannelDigests = $postContextChannelDigests;
$preContextChannelDigests['woocommerce'] = PRE_CONTEXT_CHANNEL_WOOCOMMERCE_DIGEST;
$contextChannelMovedNames = [];
foreach ($postContextChannelDigests as $name => $digest) {
    if ($preContextChannelDigests[$name] !== $digest) {
        $contextChannelMovedNames[] = $name;
    }
}
wprism_check_same(
    ['woocommerce'],
    $contextChannelMovedNames,
    'the duplicate durable-context declaration correction changes only WooCommerce and preserves its prior '
    . 'fleet-visible identity'
);
wprism_check_same(
    PRE_CONTEXT_CHANNEL_REGISTRY_SHA,
    POST_CONTEXT_CHANNEL_REGISTRY_SHA,
    'the manifest-only durable-context correction leaves the reviewed disposition registry byte-identical'
);
wprism_check(
    PRE_CONTEXT_CHANNEL_MANIFEST_HASH !== POST_CONTEXT_CHANNEL_MANIFEST_HASH
        && PRE_CONTEXT_CHANNEL_SNAPSHOT_SHA !== POST_CONTEXT_CHANNEL_SNAPSHOT_SHA,
    'the historical Rank-free 17-adapter durable-context correction re-pins its manifest and snapshot while preserving its registry'
);
wprism_check_same(
    POST_CONTEXT_CHANNEL_MANIFEST_HASH,
    WPRISM_CURRENT_YOAST_WORLD_MANIFEST_HASH,
    'the current Yoast-compatible world preserves the historical Rank-free manifest address because later Rank Math bytes are outside that pin set'
);
wprism_check(
    POST_CONTEXT_CHANNEL_REGISTRY_SHA !== WPRISM_CURRENT_REGISTRY_SHA
        && POST_CONTEXT_CHANNEL_SNAPSHOT_SHA !== WPRISM_CURRENT_YOAST_WORLD_SNAPSHOT_SHA,
    'the current Yoast-compatible snapshot still moves with the full reviewed registry after Rank Math disposition changes'
);
wprism_check(
    WPRISM_PRE_SCHEMA_SETTLEMENT_RANK_MATH_DIGEST !== WPRISM_CURRENT_DIGESTS['rank-math']
        && WPRISM_PRE_SCHEMA_SETTLEMENT_MANIFEST_HASH !== WPRISM_CURRENT_RANK_WORLD_MANIFEST_HASH
        && WPRISM_PRE_SCHEMA_SETTLEMENT_REGISTRY_SHA !== WPRISM_CURRENT_REGISTRY_SHA
        && WPRISM_PRE_SCHEMA_SETTLEMENT_SNAPSHOT_SHA !== WPRISM_CURRENT_RANK_WORLD_SNAPSHOT_SHA,
    'the historical pre-observation schema-settlement addresses are distinct from the current Rank-compatible baseline'
);
wprism_check(
    WPRISM_PRE_RANK_MATH_HARDENING_DIGEST !== WPRISM_CURRENT_DIGESTS['rank-math']
        && WPRISM_PRE_RANK_MATH_HARDENING_MANIFEST_HASH !== WPRISM_CURRENT_RANK_WORLD_MANIFEST_HASH
        && WPRISM_PRE_RANK_MATH_HARDENING_SNAPSHOT_SHA !== WPRISM_CURRENT_RANK_WORLD_SNAPSHOT_SHA,
    'the historical pre-hardening Rank Math addresses are distinct from the current Rank-compatible baseline'
);
wprism_check_same(
    WPRISM_CURRENT_DIGESTS,
    $observed,
    'the current WPrism greenfield baseline pins every shipped adapter digest explicitly; a disposition split that '
    . 'changes any manifest row is a measured identity failure'
);
$movedNames = [];
foreach ($observed as $name => $digest) {
    if (($frozenDigests[$name] ?? '') !== $digest) {
        $movedNames[] = $name;
    }
}
wprism_check_same(
    array_keys(WPRISM_CURRENT_DIGESTS),
    $movedNames,
    'and the current identity set is exactly the explicit WPrism baseline, including the shipped Redirection subject'
);
wprism_check_same(
    WPRISM_CURRENT_RANK_WORLD_MANIFEST_HASH,
    ArtifactPolicyIdentity::manifest_hash($shippedPolicies['rank-world']),
    'and manifest_hash over the maximal Rank-compatible pins is pinned to the WPrism greenfield source'
);
wprism_check_same(
    WPRISM_CURRENT_YOAST_WORLD_MANIFEST_HASH,
    ArtifactPolicyIdentity::manifest_hash($shippedPolicies['yoast-world']),
    'and manifest_hash over the maximal Yoast-compatible pins is independently pinned to the WPrism greenfield source'
);
wprism_check(
    SPLIT_REVIEWED_MANIFEST_HASH !== SPLIT_FROZEN_MANIFEST_HASH
        && SPLIT_PACKAGE_DEPENDENCY_MANIFEST_HASH !== SPLIT_REVIEWED_MANIFEST_HASH
        && SPLIT_LOCAL_CACHE_MANIFEST_HASH !== SPLIT_PACKAGE_DEPENDENCY_MANIFEST_HASH
        && SPLIT_NATIVE_HOOK_MANIFEST_HASH !== SPLIT_LOCAL_CACHE_MANIFEST_HASH
        && SPLIT_DATA_STORE_HOOK_MANIFEST_HASH !== SPLIT_NATIVE_HOOK_MANIFEST_HASH
        && SPLIT_MARIADB_PRIORITY_MANIFEST_HASH !== SPLIT_DATA_STORE_HOOK_MANIFEST_HASH
        && SPLIT_MARIADB_ARGS_INDEX_MANIFEST_HASH !== SPLIT_MARIADB_PRIORITY_MANIFEST_HASH
        && SPLIT_RETENTION_NATIVE_HOOK_MANIFEST_HASH !== SPLIT_MARIADB_ARGS_INDEX_MANIFEST_HASH
        && SPLIT_RETENTION_CRON_OWNER_MANIFEST_HASH !== SPLIT_RETENTION_NATIVE_HOOK_MANIFEST_HASH
        && SPLIT_LIFECYCLE_SETTLEMENT_MANIFEST_HASH !== SPLIT_RETENTION_CRON_OWNER_MANIFEST_HASH
        && PRE_CONTEXT_CHANNEL_MANIFEST_HASH !== SPLIT_LIFECYCLE_SETTLEMENT_MANIFEST_HASH
        && WPRISM_CURRENT_RANK_WORLD_MANIFEST_HASH !== PRE_CONTEXT_CHANNEL_MANIFEST_HASH
        && WPRISM_CURRENT_RANK_WORLD_MANIFEST_HASH !== SPLIT_LIFECYCLE_SETTLEMENT_MANIFEST_HASH
        && SPLIT_REVIEWED_REGISTRY_SHA !== SPLIT_FROZEN_REGISTRY_SHA
        && SPLIT_REVIEWED_SNAPSHOT_SHA !== SPLIT_FROZEN_SNAPSHOT_SHA
        && SPLIT_LIFECYCLE_SETTLEMENT_SNAPSHOT_SHA !== SPLIT_REVIEWED_SNAPSHOT_SHA
        && PRE_CONTEXT_CHANNEL_SNAPSHOT_SHA !== SPLIT_LIFECYCLE_SETTLEMENT_SNAPSHOT_SHA
        && POST_CONTEXT_CHANNEL_MANIFEST_HASH !== PRE_CONTEXT_CHANNEL_MANIFEST_HASH
        && POST_CONTEXT_CHANNEL_SNAPSHOT_SHA !== PRE_CONTEXT_CHANNEL_SNAPSHOT_SHA
        && WPRISM_PRE_RANK_MATH_HARDENING_MANIFEST_HASH !== WPRISM_CURRENT_RANK_WORLD_MANIFEST_HASH
        && WPRISM_PRE_RANK_MATH_HARDENING_SNAPSHOT_SHA !== WPRISM_CURRENT_RANK_WORLD_SNAPSHOT_SHA
        && WPRISM_CURRENT_REGISTRY_SHA !== SPLIT_REVIEWED_REGISTRY_SHA
        && WPRISM_CURRENT_RANK_WORLD_SNAPSHOT_SHA !== SPLIT_LIFECYCLE_SETTLEMENT_SNAPSHOT_SHA
        && WPRISM_CURRENT_YOAST_WORLD_SNAPSHOT_SHA !== SPLIT_LIFECYCLE_SETTLEMENT_SNAPSHOT_SHA,
    '...and all re-pinned numbers really differ from their frozen originals, so the assertions '
    . 'around them are re-pins a reviewer must read rather than restatements of the frozen constants'
);
wprism_check_same(
    WPRISM_CURRENT_REGISTRY_SHA,
    $shippedRegistry->sha256(),
    'and registry_sha256, the content address a host contract pins, reassembles from the current per-subject '
    . 'documents to exactly one WPrism registry (WP-4.5 is the rider that narrows this to per-subject addressing)'
);
wprism_check_same(
    WPRISM_CURRENT_RANK_WORLD_SNAPSHOT_SHA,
    hash('sha256', Canon::encode($shippedPolicies['rank-world']->export_snapshot())),
    'and the Rank-compatible policy snapshot, including the whole registry, is pinned to its explicit baseline'
);
wprism_check_same(
    WPRISM_CURRENT_YOAST_WORLD_SNAPSHOT_SHA,
    hash('sha256', Canon::encode($shippedPolicies['yoast-world']->export_snapshot())),
    'and the Yoast-compatible policy snapshot, including the same registry, is independently pinned'
);
// The relocation must also be invisible in the other direction: bytes frozen
// before it still reconstruct a policy, through the validator that reads them.
// Taken over a ONE-PIN policy because a snapshot's manifest list is checked
// against the site's own pins (Policy.php:653-655), and the maximal policies
// above were loaded without a site file to state them.
$corePolicy = Policy::load(null, ['core'], adapterLibrary: $adapterLibrary);
wprism_check_same(
    ArtifactPolicyIdentity::manifest_hash($corePolicy),
    ArtifactPolicyIdentity::manifest_hash(Policy::from_snapshot($corePolicy->export_snapshot(), $adapterLibrary)),
    'and the v6 snapshot round trip reproduces manifest_hash, so from_snapshot() reads the reassembled document the '
    . 'way it read the monolith'
);

// ---------------------------------------------------------------------------
echo "\nPART 2 — THE HAZARDS: each canonical-encoding difference the split could have introduced\n";
// ---------------------------------------------------------------------------
// A census of the shipped source first, because it says which hazards these
// bytes could even express. Both numbers are tripwires: a reviewed entry that
// starts carrying a number or a non-ASCII reason lands in a suite that already
// measures what that would cost.
$numberMembers = [];
$nonAsciiMembers = [];
$documentCount = 0;
$walk = static function ($value, string $path) use (&$walk, &$numberMembers, &$nonAsciiMembers): void {
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $walk($child, $path . '/' . $key);
        }
        return;
    }
    if (is_int($value) || is_float($value)) {
        $numberMembers[] = $path;
        return;
    }
    if (is_string($value) && preg_match('/[^\x20-\x7E\t\n]/', $value) === 1) {
        $nonAsciiMembers[] = $path;
    }
};
$reviewedDocuments = [$adapterLibrary->profilesPath()];
foreach ($adapterLibrary->packages() as $package) {
    $reviewedDocuments[] = $package->dispositionPath();
}
foreach ($reviewedDocuments as $document) {
    $documentCount++;
    $walk(Canon::decode(Canon::read_file($document)), basename($document, '.json'));
}
wprism_check_same(19, $documentCount, 'the reviewed source is 19 documents: 18 subjects and the profiles map');
wprism_check_same(
    [],
    $numberMembers,
    'no shipped reviewed member is a number, so the int/float hazard below cannot arise from these bytes — but a '
    . 'reviewed entry that starts carrying one arrives in a suite that measures what it costs'
);
wprism_check_same(
    [],
    $nonAsciiMembers,
    'and no shipped reason carries a non-ASCII character, so the UTF-8 composition hazard cannot arise from them '
    . 'either'
);

/**
 * A throwaway one-adapter library, digest-capable through the real product
 * path: a manifest, the shipped platform boundary verbatim (a claim cannot be
 * projected without one, and re-authoring it would describe a runtime nobody
 * runs), and one reviewed document.
 *
 * @param array<string,mixed> $manifest
 * @param array<string,mixed> $entry
 */
$probeLibrary = static function (string $label, array $manifest, array $entry) use ($scratchRoot, $adapterLibrary): AdapterLibrary {
    $dir = $scratchRoot . '/' . $label . '/manifests';
    foreach (['capabilities', 'dispositions', 'interpreters', 'providers', 'regenerators'] as $relative) {
        if (!is_dir("$dir/$relative") && !mkdir("$dir/$relative", 0777, true)) {
            throw new RuntimeException("cannot create the probe library at $dir/$relative");
        }
    }
    Canon::write_file($dir . '/' . $manifest['name'] . '.json', Canon::encode($manifest));
    Canon::write_file($dir . '/dispositions/' . $manifest['name'] . '.json', Canon::encode($entry));
    Canon::write_file($dir . '/dispositions/profiles.json', "{}\n");
    copy($adapterLibrary->platformBoundaryPath(), $dir . '/capabilities/platform.json');
    copy($adapterLibrary->authoritiesPath(), $dir . '/capabilities/adapter-authorities.json');
    return AdapterLibrary::fromLegacyFlatDirectory($dir);
};

/** That library's one adapter digest, taken exactly as a repository pin takes it. */
$probeDigest = static function (AdapterLibrary $library, string $name): string {
    $rows = ArtifactPolicyIdentity::resolved_adapters(Policy::load(null, [$name], adapterLibrary: $library));
    return (string) $rows[0]['digest'];
};

$probeManifest = [
    'name' => 'split-probe',
    'option_autoload' => 'preserve',
    'options' => ['split_probe_layout' => ['class' => 'authored']],
    'spec_version' => WPRISM_SPEC_VERSION,
];
$probeEntry = [
    'capabilities' => [
        'deletion_semantics' => ['supported' => [], 'unsupported' => ['nothing is deletable here']],
        'entity_sections' => [],
        'field_sections' => ['options'],
        // A nested LIST, which is where element order is load-bearing.
        'lifecycle_phases' => ['retire', 'activate', 'verify'],
        'operations' => ['apply', 'capture'],
    ],
    'default_authored_keyspaces' => [],
    'reason' => 'Synthetic subject for the canonical-encoding hazard cases.',
    'status' => 'experimental',
    'supported_versions' => ['plugin' => 'split-probe/split-probe.php', 'range' => ['max' => '2.0.0', 'min' => '1.0.0']],
    'unsupported' => [[
        'operation' => 'delete',
        'reason' => 'the probe owns options only',
        'surface' => 'deletions.*',
    ]],
];
$baseline = $probeDigest($probeLibrary('baseline', $probeManifest, $probeEntry), 'split-probe');

// HAZARD 0 — the one difference that is NOT a hazard, and the reason the whole
// relocation is admissible: Canon ksorts every map at every nesting level, so a
// document authored with its keys in any order encodes identically. Measured
// with the key order reversed at every level, which is the strongest form of
// the claim a splitter could have needed.
$reverseKeys = static function ($value) use (&$reverseKeys) {
    if (!is_array($value) || array_is_list($value)) {
        // Lists are left alone deliberately: their order is the SEPARATE
        // hazard measured below, and reversing both at once would not say
        // which of the two moved the digest.
        return $value;
    }
    $out = [];
    foreach (array_reverse($value, true) as $key => $child) {
        $out[$key] = $reverseKeys($child);
    }
    return $out;
};
$keyReversedEntry = $reverseKeys($probeEntry);
wprism_check_same(
    $baseline,
    $probeDigest($probeLibrary('key-order', $probeManifest, $keyReversedEntry), 'split-probe'),
    'MAP KEY ORDER is neutral at every nesting level (Canon.php:44,58) — which is exactly why one entry may be '
    . 'lifted out of a document and written as its own root without moving a digest'
);

// HAZARD 1 — nested LIST order. Canon leaves lists alone, deliberately (a
// consumer may read a value's order: Canon.php:15-36). A splitter that
// normalised `lifecycle_phases` while it was normalising key order would have
// moved this digest, and PART 1 would have named the adapter.
$listReorderedEntry = $probeEntry;
$listReorderedEntry['capabilities']['lifecycle_phases'] = array_reverse(
    $probeEntry['capabilities']['lifecycle_phases']
);
wprism_check(
    $probeDigest($probeLibrary('list-order', $probeManifest, $listReorderedEntry), 'split-probe') !== $baseline,
    'HAZARD nested list order: reversing `capabilities.lifecycle_phases` MOVES the digest, so PART 1 would catch a '
    . 'splitter that re-ordered a list'
);

// HAZARD 2 — int/float round trip, and THE ONE HAZARD THE DIGEST DOES NOT
// CATCH. The split rewrote every entry through Canon::decode/encode, and that
// round trip is lossy for numbers in two directions, measured here rather than
// assumed:
//
//   - `3` and `3.0` are different PHP types and the SAME canonical bytes:
//     Canon::encode() does not pass JSON_PRESERVE_ZERO_FRACTION
//     (Canon.php:95-98), so a whole float re-encodes as an integer;
//   - a value past PHP's integer precision decodes to a float, so
//     10000000000000000001 and …002 are ONE value by the time a digest sees
//     them, and both re-encode as `1.0e+19` — bytes that are not what either
//     author wrote.
//
// So the guard against this hazard is not the digest. It is the census above:
// no shipped reviewed member is a number at all, which is why the rewrite was
// safe, and why that assertion is a tripwire rather than trivia.
$wholeFloat = Canon::encode(['reviewed_revision' => 3]) === Canon::encode(['reviewed_revision' => 3.0]);
$pastPrecision = Canon::encode(Canon::decode('{"reviewed_revision":10000000000000000001}'))
    === Canon::encode(Canon::decode('{"reviewed_revision":10000000000000000002}'));
wprism_check(
    $wholeFloat && $pastPrecision,
    'HAZARD int/float is the one the digest CANNOT catch: Canon erases the int/float distinction for a whole '
    . 'number, and two distinct integers past PHP precision collide on one float — which is exactly why the census '
    . 'above asserts the reviewed source carries no number at all'
);

// HAZARD 3 — UTF-8 composition across the prose reasons. `reason` is free text
// a human wrote, and it is folded into the digest verbatim. Two spellings of
// the same visible character — precomposed U+00E9 and e + U+0301 — are equal on
// screen, different on the wire, and a tool that normalised one to the other
// while rewriting 16 files would move exactly the adapters whose reasons carry
// an accent.
$precomposedEntry = $probeEntry;
$precomposedEntry['reason'] = "Certified against the re\u{00E9}dited reviewed boundary.";
$decomposedEntry = $probeEntry;
$decomposedEntry['reason'] = "Certified against the ree\u{0301}dited reviewed boundary.";
wprism_check(
    $precomposedEntry['reason'] !== $decomposedEntry['reason']
        && $probeDigest($probeLibrary('nfc', $probeManifest, $precomposedEntry), 'split-probe')
            !== $probeDigest($probeLibrary('nfd', $probeManifest, $decomposedEntry), 'split-probe'),
    'HAZARD UTF-8 composition: two spellings of one visible character in a prose `reason` MOVE the digest, so a '
    . 'normalising rewrite of the reviewed prose would be caught adapter by adapter'
);

// ---------------------------------------------------------------------------
echo "\nPART 3 — THE REFUSALS: every rule fires from the split form, in its own wording\n";
// ---------------------------------------------------------------------------
$coreManifest = Canon::decode(Canon::read_file($manifestPath('core')));
$coreEntry = Canon::decode(Canon::read_file(
    $adapterLibrary->package('core')?->dispositionPath()
        ?? throw new RuntimeException("shipped adapter package 'core' is absent")
));

/** A one-subject library whose reviewed documents are written by $publish. */
$reviewedLibrary = static function (string $label, callable $publish) use ($scratchRoot, $adapterLibrary, $manifestPath): string {
    $dir = $scratchRoot . '/' . $label . '/manifests';
    if (!is_dir($dir . '/capabilities') && !mkdir($dir . '/capabilities', 0777, true)) {
        throw new RuntimeException("cannot create the probe library at $dir");
    }
    if (!is_dir($dir . '/dispositions') && !mkdir($dir . '/dispositions', 0777, true)) {
        throw new RuntimeException("cannot create the probe disposition directory at $dir");
    }
    copy($manifestPath('core'), $dir . '/core.json');
    copy($adapterLibrary->platformBoundaryPath(), $dir . '/capabilities/platform.json');
    $publish($dir . '/dispositions');
    return $dir;
};

/** The refusal SENTENCE, whole: a wording that IS the contract (AGENTS.md rule 8). */
$refusal = static function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '<no refusal thrown>';
};

// THE COVERAGE REFUSAL, byte for byte. This is the sentence the monolith
// produced for a pinned manifest with no reviewed entry, and it is quoted
// verbatim in docs/guides/adapter-authoring.md, so the split had to reach it
// through a MISSING FILE rather than a missing key without changing a
// character.
$noDocument = $reviewedLibrary('missing-document', static function (string $dir): void {
    // Nothing published: `core` is pinned and has no document.
    if (!is_dir($dir)) {
        throw new RuntimeException('the probe disposition directory was not created');
    }
});
wprism_check_same(
    'wprism: manifest disposition coverage mismatch; missing=[core], extra=[]',
    $refusal(static function () use ($noDocument, $coreManifest): void {
        ManifestDispositions::load($noDocument)?->assert_covers([$coreManifest]);
    }),
    'a document missing for a PINNED subject refuses by name, in the monolith coverage sentence to the byte'
);
wprism_check_same(
    "wprism: manifest disposition 'core' must be an object",
    $refusal(static function () use ($reviewedLibrary, $coreManifest): void {
        $dir = $reviewedLibrary('null-document', static function (string $dir): void {
            Canon::write_file($dir . '/core.json', "null\n");
        });
        ManifestDispositions::load($dir)?->assert_covers([$coreManifest]);
    }),
    'and a document that EXISTS and decodes to null is present-and-malformed, not missing — the distinction '
    . 'assert_covers() kept when a key became a file'
);

// The per-entry rules, each reached through the same public entry point a site
// reaches them through. Every wording here predates the split.
$entryRules = [
    'a status outside the reviewed vocabulary' => [
        static function (array $entry): array {
            $entry['status'] = 'ratified';
            return $entry;
        },
        "wprism: manifest disposition 'core' has a malformed required field",
    ],
    'the synthesized runtime status, DECLARED' => [
        static function (array $entry): array {
            $entry['status'] = ManifestDispositions::STATUS_UNCOVERED;
            return $entry;
        },
        "wprism: manifest disposition 'core' has a malformed required field",
    ],
    'capabilities with a key the closed set does not carry' => [
        static function (array $entry): array {
            $entry['capabilities']['invented'] = [];
            return $entry;
        },
        "wprism: manifest disposition 'core' capabilities are malformed",
    ],
    'deletion semantics missing an arm' => [
        static function (array $entry): array {
            unset($entry['capabilities']['deletion_semantics']['unsupported']);
            return $entry;
        },
        "wprism: manifest disposition 'core' deletion semantics are malformed",
    ],
    'a declared section the manifest does not carry' => [
        static function (array $entry): array {
            $entry['capabilities']['field_sections'][] = 'invented_section';
            return $entry;
        },
        "wprism: manifest disposition 'core' names absent manifest section 'invented_section'",
    ],
    'an unsupported row with no reason' => [
        static function (array $entry): array {
            $entry['unsupported'][0]['reason'] = '';
            return $entry;
        },
        "wprism: manifest disposition 'core' unsupported[0] is malformed",
    ],
    'default-authored evidence for a keyspace the manifest never declares' => [
        static function (array $entry): array {
            $entry['default_authored_keyspaces'][] = [
                'reason' => 'synthetic evidence for a table core does not declare',
                'status' => 'justified',
                'table' => 'not_a_core_table',
            ];
            return $entry;
        },
        "wprism: manifest disposition 'core' names non-default-authored keyspace 'not_a_core_table'",
    ],
    'a certified claim citing nothing' => [
        static function (array $entry): array {
            unset($entry['evidence']);
            return $entry;
        },
        "wprism: certified manifest disposition 'core' lacks current bundle evidence",
    ],
];
foreach ($entryRules as $label => [$edit, $expected]) {
    $dir = $reviewedLibrary(
        'entry-rule-' . substr(hash('sha256', (string) $label), 0, 8),
        static function (string $documents) use ($edit, $coreEntry): void {
            Canon::write_file($documents . '/core.json', Canon::encode($edit($coreEntry)));
        }
    );
    $message = $refusal(static function () use ($dir, $coreManifest): void {
        ManifestDispositions::load($dir)?->assert_covers([$coreManifest]);
    });
    wprism_check(
        str_contains($message, $expected),
        "per-entry rule fires from the split form, unchanged: $label ($message)"
    );
}

// The profile rules resolve against the SUBJECTS THE DIRECTORY DECLARES, which
// is the listing — the split's replacement for the monolith's `manifests` keys.
$badProfile = $reviewedLibrary('bad-profile', static function (string $documents) use ($coreEntry): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
    Canon::write_file($documents . '/profiles.json', Canon::encode([
        'orphan' => [
            'evidence' => ['bundle_schema' => ManifestDispositions::EVIDENCE_SCHEMA, 'tests' => ['conformance-core']],
            'manifest' => 'not-a-reviewed-subject',
            'reason' => 'names a subject this library does not review',
            'scope' => [],
            'status' => 'certified',
            'supported_versions' => ['range' => ['max' => '8.0', 'min' => '6.0']],
        ],
    ]));
});
wprism_check_same(
    "wprism: manifest disposition profile 'orphan' is malformed",
    $refusal(static function () use ($badProfile): void {
        ManifestDispositions::load($badProfile);
    }),
    'a profile naming a subject the directory does not declare refuses at load, in the profile rule wording'
);
$goodProfile = $reviewedLibrary('good-profile', static function (string $documents) use ($coreEntry, $adapterLibrary): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
    copy($adapterLibrary->profilesPath(), $documents . '/profiles.json');
});
wprism_check_same(
    ['fse'],
    array_keys(ManifestDispositions::load($goodProfile)?->profiles() ?? []),
    'and the shipped profiles document resolves against a directory that declares its subject'
);

// The frozen root rule keeps its one live reader: a snapshot is still the WHOLE
// document, so validate_root() still refuses a malformed one.
wprism_check(
    str_contains(
        $refusal(static function () use ($coreManifest): void {
            ManifestDispositions::from_snapshot(['format' => ManifestDispositions::FORMAT], [$coreManifest]);
        }),
        'frozen manifest disposition registry must contain exactly format, manifests, and profiles'
    ),
    'the root rule survives on the frozen path, which is where a root still exists'
);

// The three rules the DIRECTORY adds. A namespace can carry mistakes a key set
// could not, and each of them is inert bytes an operator believes in — the
// failure mode AdapterSources refuses everywhere else (:868-873).
$strayName = $reviewedLibrary('stray-name', static function (string $documents) use ($coreEntry): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
    // Numeric-only, so it fails the grammar's mandatory-lowercase rule and no
    // case-insensitive filesystem can fold it onto `core.json`.
    Canon::write_file($documents . '/2024.json', Canon::encode($coreEntry));
    // A profiles document forces the listing, which is where the name grammar
    // is enforced; without a profile there is nothing to resolve and no listing.
    Canon::write_file($documents . '/profiles.json', Canon::encode([]));
});
wprism_check(
    str_contains(
        $refusal(static function () use ($strayName): void {
            ManifestDispositions::load($strayName)?->data();
        }),
        "is not named for a canonical adapter slug"
    ),
    'a document not named for a canonical adapter slug refuses rather than being skipped — reviewed bytes with no '
    . 'subject are the authoring half of the coverage rule'
);
wprism_check(
    str_contains(
        $refusal(static function () use ($goodProfile): void {
            ManifestDispositions::load($goodProfile)?->entry('profiles');
        }),
        "is the reserved name of the profiles document"
    ),
    "'profiles' is reserved: an adapter of that name would be its own profiles map, so it refuses by name"
);
$staleMonolith = $reviewedLibrary('stale-monolith', static function (string $documents) use ($coreEntry): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
});
Canon::write_file($staleMonolith . '/dispositions.json', Canon::encode([
    'format' => ManifestDispositions::FORMAT,
    'manifests' => ['core' => $coreEntry],
    'profiles' => [],
]));
wprism_check(
    str_contains(
        $refusal(static function () use ($staleMonolith): void {
            ManifestDispositions::load($staleMonolith);
        }),
        'still carries the pre-split dispositions.json'
    ),
    'and a stale monolith beside the directory refuses the load: ratification bytes nothing reads are exactly what '
    . 'this library refuses everywhere else'
);

wprism_check_summary('disposition split');
