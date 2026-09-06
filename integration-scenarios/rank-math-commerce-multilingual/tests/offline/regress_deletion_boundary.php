<?php
/** Execute the actual unsupported-deletion evidence caller against typed engine refusals. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
$relative = '/integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh';
$live = (string) file_get_contents($root . $relative);
$prior = is_dir($argv[1] ?? '') ? $argv[1] : $root;
$caller = (string) file_get_contents($prior . $relative);
$slice = static function (string $bytes, string $start, string $end): string {
    $a = strpos($bytes, $start);
    $b = $a === false ? false : strpos($bytes, $end, $a + strlen($start));
    if ($a === false || $b === false) throw new RuntimeException('the actual deletion evidence window is absent');
    return substr($bytes, $a, $b - $a);
};
$assertions = $slice($live, 'assert_rmcombo_warning_free_capture() {', "\nfor command in docker");
$observer = $slice($live, 'rmcombo_canonical_state() {', 'run_leg() {');
$start = str_contains($caller, "say 'unsupported custom-CPT deletion")
    ? "say 'unsupported custom-CPT deletion" : "say 'direct deletion remains refusal-only";
$window = $slice($caller, $start, "\n}\n");
$scratch = sys_get_temp_dir() . '/wprism-combined-deletion-' . bin2hex(random_bytes(8));
if (!mkdir($scratch, 0700)) throw new RuntimeException('deletion test scratch allocation failed');
$nativeFile = $scratch . '/native.php';
register_shutdown_function(static function () use ($nativeFile, $scratch): void {
    @unlink($nativeFile);
    @rmdir($scratch);
});

// The refusal is not canned text: both pin orders run the real capability
// resolver, Capture's tombstone factory, parser and public CLI serializer.
// Shell transport and native post/compiled-site setup remain deterministic
// seams; the complete four-lane live driver supplies the WordPress proof.
$native = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1].'/sandbox/tests/lib/wp_stubs.php';
require $argv[1].'/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require $argv[1].'/agent/src/Policy/Policy.php';
require $argv[1].'/agent/src/Delete/Deletion.php';
require $argv[1].'/agent/src/Repository/CompiledArtifact.php';
require $argv[1].'/agent/src/Repository/SidebarState.php';
require $argv[1].'/agent/src/Repository/RepositoryDeletionParser.php';
require $argv[1].'/agent/src/Command/Cli.php';
class WP_CLI {
    public static function add_command(string $name, string $class): void {}
    public static function line(string $message): void { echo $message, "\n"; }
    public static function halt(int $status): never { exit($status); }
}
$pins = $argv[4] === 'forward' ? ['core','acf','polylang','rank-math','woocommerce'] : ['core','woocommerce','rank-math','polylang','acf'];
$policy = WPrism\Policy::load(null,$pins,false,null,WPrism\AdapterLibrary::fromSourceTree($argv[1]));
$uuid='11111111-1111-4111-8111-111111111111';
$path="posts/rmcombo_book/$uuid--rmcombo-book.md";
$entity=['uuid'=>$uuid,'type'=>'post','path'=>$path,'hash'=>hash('sha256',"book bytes\n"),'data'=>['type'=>'rmcombo_book','slug'=>'rmcombo-book']];
$compiled=WPrism\CompiledRepository::create(['revision_hash'=>str_repeat('a',64),'tree'=>[$uuid=>$entity]]);
$intent=['expected_hash'=>$entity['hash'],'expected_revision'=>$compiled->revision_hash(),
    'format'=>'wprism-deletion/v1','kind'=>'post','source_path'=>$path,'type'=>'rmcombo_book','uuid'=>$uuid];
$verb=$argv[2];
if($policy->deletion_capability('post:rmcombo_book')!==null) throw new RuntimeException('combined policy unexpectedly grants custom-CPT deletion');
if($verb==='intent') { echo json_encode($intent,JSON_THROW_ON_ERROR); exit; }
if($verb==='capture') {
    try { WPrism\Deletion::capture_tombstones($compiled,[],$policy); throw new RuntimeException('unsupported Capture unexpectedly succeeded'); }
    catch(WPrism\CommandRefusalException $refusal) {}
} else {
    $diagnostics=[];
    $parser=new WPrism\RepositoryDeletionParser($policy,static function(string $code,string $path,string $locator,string $message) use (&$diagnostics):void {
        $diagnostics[]=compact('code','path','locator','message')+['severity'=>'blocking'];
    });
    $file=$argv[3]."/state/deletions/$uuid.json";
    if(!is_file($file) || file_exists($argv[3].'/state/'.$path)) throw new RuntimeException('synthetic intent was not published without its live canonical entity');
    $bytes=(string)file_get_contents($file);
    if($bytes!==WPrism\Canon::encode($intent)) throw new RuntimeException('synthetic intent is not byte-exact canonical compiled identity');
    $parser->parse("deletions/$uuid.json",$bytes);
    if(count($diagnostics)!==1 || $diagnostics[0]['code']!=='unsupported_deletion') throw new RuntimeException('synthetic intent did not reach exactly its unsupported capability boundary');
    $refusal=new WPrism\RepositoryCompilationException($diagnostics);
}
(new ReflectionMethod(WPrism\Cli::class,'halt_json_failure'))->invoke(null,$refusal,['format'=>'json'],$verb);
throw new RuntimeException('the public CLI serializer did not halt');
PHP;
file_put_contents($nativeFile, $native);

$probe = <<<'SH'
set -euo pipefail
actual_root="$1" native="$2" fault="$3" order="$4"
scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT
ROOT="$scratch/root" PAIR=rmdelete source_order="$order" meta_mode=independent
mkdir -p "$ROOT/sandbox/siterepo/rmdelete1/state/posts/rmcombo_book" "$ROOT/sandbox/siterepo/rmdelete2/state/posts/rmcombo_book" "$scratch/private"
ROOT=$(cd "$ROOT" && pwd -P)
ln -s "$actual_root/agent" "$ROOT/agent"
R1="$ROOT/sandbox/siterepo/rmdelete1" R2="$ROOT/sandbox/siterepo/rmdelete2" TMP_ROOT="$scratch/private"
uuid=11111111-1111-4111-8111-111111111111
for repo in "$R1" "$R2"; do
  printf 'book bytes\n' >"$repo/state/posts/rmcombo_book/$uuid--rmcombo-book.md"
  printf 'already dirty\n' >"$repo/state/unrelated"
  printf '{}\n' >"$repo/site.wprism.json"
done
SOURCE_SEED='{"book":311}' TARGET_FINAL='{"book":{"id":911},"neighbor":{"price":"67"},"scheduler":[{"id":"5"}]}'
fail() { printf '%s\n' "$*" >&2; exit 1; }
say() { :; }
pass() { :; }
. "$actual_root/sandbox/conformance/asserts.sh"
git() { [ "$*" = "-C $R2 rev-parse HEAD" ] || fail 'unsupported source intent reached Git publication'; printf '%040d\n' 1; }
record() { printf '%s\n' "$1" >>"$scratch/trace"; }
refusal() {
  local side="$1" verb="$2" answer status=0
  record "$verb"
  answer=$(php "$native" "$actual_root" "$verb" "$R2" "$order" 2>&1) || status=$?
  [ "$status" = 1 ] || fail 'typed engine refusal fixture failed'
  case "$fault" in
    "$verb-success") status=0 ;;
    "$verb-code") answer=$(jq -c '.error="wrong"|.reason_code="wrong"' <<<"$answer") ;;
    "$verb-diagnostic") answer=$(jq -c '.diagnostics[0].code="malformed_deletion"' <<<"$answer") ;;
    "$verb-command") answer=$(jq -c '.command="wrong"' <<<"$answer") ;;
    "$verb-duplicate") answer="$answer"$'\n'"$answer" ;;
    "$verb-noise") printf 'unexpected transport noise\n' >&2 ;;
    "$verb-warning") printf 'PHP Warning: fixture in /fixture.php on line 1\n' >&2 ;;
    capture-surface) [ "$verb" != capture ] || answer=$(jq -c '.diagnostics[0].surface="post:page"' <<<"$answer") ;;
    "$verb-path") answer=$(jq -c '.diagnostics[0].path="deletions/wrong.json"' <<<"$answer") ;;
    "$verb-canonical") printf 'second dirty edit\n' >"$side/state/unrelated" ;;
  esac
  if [ "$verb" = apply ]; then
    if [ "$fault" != apply-missing-pointer ]; then
      if [ "$fault" = apply-foreign-pointer ]; then
        printf 'private command diagnostics (unverified): /foreign/wprism-rmcombo-apply.rmdelete.a12345\n' >&2
      else
        printf 'private command diagnostics (unverified): %s/sandbox/tmp/wprism-rmcombo-apply.%s.a12345\n' "$ROOT" "$PAIR" >&2
      fi
    fi
  fi
  printf ' Container wprism-%s-cli%s-run-a12345 Created\n' "$PAIR" "${side: -1}" >&2
  printf '%s\n' "$answer"
  return "$status"
}
wp1() {
  case "$1:$2" in
    post:delete) record post-delete; touch "$scratch/source-absent" ;;
    wprism:capture) [ -f "$scratch/source-absent" ] || fail 'Capture did not follow native removal'; refusal "$R1" capture ;;
    eval:*) record source-absence; [ -f "$scratch/source-absent" ] || fail 'source absence was not observed';
      [ "$fault" != source-absence-noise ] || printf 'unexpected source absence message\n' >&2
      [ "$fault" != source-absence-duplicate ] || printf '{"absent":true}\n'
      if [ "$fault" = missing-source-premise ]; then printf '{"absent":false}\n'; else printf '{"absent":true}\n'; fi ;;
    *) fail 'unknown source command' ;;
  esac
}
wp2() {
  case "$1:$2" in
    eval:*) record intent
      [ "$fault" != intent-noise ] || printf 'unexpected intent message\n' >&2
      [ "$fault" != intent-duplicate ] || printf '{}\n'
      php "$native" "$actual_root" intent "$R2" "$order" ;;
    wprism:plan|wprism:apply) refusal "$R2" "$2" ;;
    *) fail 'unknown target command' ;;
  esac
}
capture_rmcombo_native_state() {
  [ "$*" = 'DELETE_REFUSAL_NATIVE wp2 target-delete-refusal' ] || fail 'unknown native observation'
  record native
  if [ "$fault" = native-mutation ]; then
    printf -v "$1" '%s' '{"book":null,"neighbor":{"price":"67"},"scheduler":[{"id":"5"}]}'
  else printf -v "$1" '%s' "$TARGET_FINAL"; fi
}
SH;
$after = <<<'SH'
[ "$(<"$scratch/trace")" = $'post-delete\ncapture\nsource-absence\nintent\nplan\napply\nnative' ] || fail 'the complete deletion path was not exercised'
[ -f "$scratch/source-absent" ] || fail 'the negative native premise was silently restored'
for repo in "$R1" "$R2"; do
  [ "$(<"$repo/state/posts/rmcombo_book/$uuid--rmcombo-book.md")" = 'book bytes' ] || fail 'canonical entity was lost'
  [ "$(<"$repo/state/unrelated")" = 'already dirty' ] || fail 'unrelated canonical bytes changed'
  [ ! -e "$repo/state/deletions" ] || fail 'synthetic tombstone survived cleanup'
done
printf 'DELETION_READY\n'
SH;
$run = static fn(string $fault, string $order = 'forward'): array => ShellProbe::run(
    $probe . "\n" . $assertions . "\n" . $observer . "\n" . $window . "\n" . $after,
    [$root, $nativeFile, $fault, $order], $root
);
foreach (['forward', 'reverse'] as $order) {
    [$status, $stdout, $stderr] = $run('ready', $order);
    wprism_check($status === 0 && $stdout === "DELETION_READY\n" && $stderr === '',
        "actual $order caller accepts real unsupported Capture and parser/CLI Plan/Apply refusals, exact native preservation and canonical cleanup");
    if ($status !== 0 && $prior === $root) fwrite(STDERR, substr($stderr, 0, 2500));
}
foreach (['capture', 'plan', 'apply'] as $verb) {
    foreach (['success', 'code', 'diagnostic', 'command', 'duplicate', 'noise', 'warning', 'canonical'] as $fault) {
        [$status, $stdout] = $run("$verb-$fault");
        wprism_check($status !== 0 && !str_contains($stdout, 'DELETION_READY'), "actual deletion caller refuses $verb-$fault");
    }
}
foreach (['capture-surface', 'plan-path', 'apply-path', 'apply-missing-pointer', 'apply-foreign-pointer', 'missing-source-premise', 'native-mutation',
    'source-absence-noise', 'source-absence-duplicate', 'intent-noise', 'intent-duplicate'] as $fault) {
    [$status, $stdout] = $run($fault);
    wprism_check($status !== 0 && !str_contains($stdout, 'DELETION_READY'), "actual deletion caller refuses $fault");
}
wprism_check(!str_contains($window, 'git -C "$R1"') && !str_contains($window, 'git status')
    && str_contains($observer, 'FilesystemTreeSnapshot::observe'),
    'unsupported intent never enters Git publication; complete canonical byte identity uses existing generic engine observation');
wprism_check_summary('combined unsupported-deletion boundary');
