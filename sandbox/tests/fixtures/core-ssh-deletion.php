<?php
declare(strict_types=1);

namespace WPrismTest;

/**
 * Native fixture/observer for the shared signed-SSH core deletion lane.
 *
 * The manifest authorizes post/meta/revision/relationship deletion, not
 * wp_delete_attachment()'s filesystem side effects. Upload witnesses include
 * the actual original and generated sizes; retaining those exact bytes is
 * intentional (spec/repo-format.md's surviving-attachment uploads inventory).
 * Every read checks its immediate error and shape before another query can
 * erase last_error. No COUNT/null cast is evidence of absence.
 */
final class CoreSshDeletionFixture {
    private const ROW_LIMIT = 4096;
    private const SLUGS = [
        'page' => 'core-ssh-guarded-page',
        'post' => 'core-ssh-local-post',
        'attachment' => 'core-ssh-delete-image',
    ];

    /** @return array<string,mixed> */
    public static function seed(): array {
        global $wpdb;
        foreach (self::SLUGS as $slug) {
            if (self::rows($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name=%s", $slug), ['ID']) !== []) {
                self::fail('fixture slug is already occupied');
            }
        }
        $admin = get_user_by('login', 'admin');
        if (!$admin instanceof \WP_User) {
            self::fail('fixture administrator is absent');
        }
        $author = self::positiveId($admin->ID);
        $ids = [];
        foreach (['page', 'post'] as $type) {
            $ids[$type] = self::nativeId(wp_insert_post([
                'post_type' => $type,
                'post_author' => $author,
                'post_status' => 'publish',
                'post_name' => self::SLUGS[$type],
                'post_title' => 'Core SSH ' . $type,
                'post_content' => '<p>Captured core deletion baseline.</p>',
            ], true));
            if (add_post_meta($ids[$type], '_core_ssh_delete_note', 'captured native metadata', true) === false) {
                self::fail('native post metadata was not created');
            }
        }
        if (!function_exists('imagecreatetruecolor')) {
            self::fail('native image fixture requires the installed GD capability');
        }
        $image = imagecreatetruecolor(480, 320);
        if ($image === false) {
            self::fail('native image allocation failed');
        }
        ob_start();
        $encoded = imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        if (!$encoded || !is_string($bytes) || $bytes === '') {
            self::fail('native image encoding failed');
        }
        $upload = wp_upload_bits('core-ssh-delete-image.png', null, $bytes);
        if (!is_array($upload) || ($upload['error'] ?? null) !== false
            || !is_string($upload['file'] ?? null) || !is_string($upload['url'] ?? null)) {
            self::fail('native upload failed');
        }
        $ids['attachment'] = self::nativeId(wp_insert_attachment([
            'post_name' => self::SLUGS['attachment'],
            'post_author' => $author,
            'post_title' => 'Core SSH attachment',
            'post_mime_type' => 'image/png',
            'post_status' => 'inherit',
            'guid' => $upload['url'],
        ], $upload['file'], 0, true));
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($ids['attachment'], $upload['file']);
        if (!is_array($metadata) || !is_string($metadata['file'] ?? null)
            || !is_array($metadata['sizes'] ?? null) || $metadata['sizes'] === []) {
            self::fail('native attachment derivatives were not recorded');
        }
        // wp_create_image_subsizes() already persists generated metadata;
        // wp_update_attachment_metadata() returns false for that exact no-op.
        // Its boolean is not storage evidence (65d92acd's live seed refused
        // here). Check the immediate error, then one exact raw metadata row.
        $wpdb->last_error = '';
        $updated = wp_update_attachment_metadata($ids['attachment'], $metadata);
        if ($wpdb->last_error !== ''
            || (!is_bool($updated) && (!is_int($updated) || $updated <= 0))) {
            self::fail('native attachment metadata update failed');
        }
        $stored = self::rows($wpdb->prepare(
            "SELECT LEFT(meta_key,24) AS meta_key,SHA2(meta_value,256) AS value_hash FROM {$wpdb->postmeta} "
            . 'WHERE post_id=%d AND meta_key=%s ORDER BY meta_id LIMIT 2',
            $ids['attachment'], '_wp_attachment_metadata'
        ), ['meta_key', 'value_hash']);
        if (count($stored) !== 1
            || array_keys($stored[0]) !== ['meta_key', 'value_hash']
            || $stored[0]['meta_key'] !== '_wp_attachment_metadata'
            || $stored[0]['value_hash'] !== hash('sha256', serialize($metadata))) {
            self::fail('native attachment derivatives were not recorded');
        }
        $paths = [$metadata['file']];
        foreach ($metadata['sizes'] as $size) {
            if (!is_array($size) || !is_string($size['file'] ?? null) || basename($size['file']) !== $size['file']) {
                self::fail('native derivative path is malformed');
            }
            $paths[] = dirname($metadata['file']) . '/' . $size['file'];
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $comment = self::nativeId(wp_insert_comment([
            'comment_post_ID' => $ids['page'],
            'comment_content' => 'Runtime comment survives explicit page deletion.',
            'comment_author' => 'Fixture visitor',
            'comment_approved' => '1',
        ]));
        if (add_comment_meta($comment, 'core_ssh_runtime_note', 'preserve exact comment metadata', true) === false) {
            self::fail('native comment metadata was not created');
        }
        $context = ['ids' => $ids, 'comment' => $comment, 'revisions' => [], 'uploads' => $paths, 'uuids' => []];
        self::uploads($paths);
        return $context;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function drift(array $context): array {
        global $wpdb;
        self::context($context);
        if (self::nativeId(wp_update_post([
            'ID' => $context['ids']['post'],
            'post_title' => 'Locally edited core SSH post',
            'post_content' => '<p>Target-local edit after the captured tombstone base.</p>',
        ], true)) !== $context['ids']['post']) {
            self::fail('native local edit changed its identity');
        }
        $roots = implode(',', array_values($context['ids']));
        $revisions = self::rows("SELECT ID,post_parent FROM {$wpdb->posts} WHERE post_parent IN ($roots) AND post_type='revision' ORDER BY ID", ['ID', 'post_parent']);
        if (array_filter($revisions, static fn(array $row): bool => $row['post_parent'] === (string) $context['ids']['post']) === []) {
            self::fail('the native local edit created no real revision child');
        }
        $context['revisions'] = array_map(static fn(array $row): int => self::positiveId($row['ID']), $revisions);
        foreach ($context['revisions'] as $id) {
            // add_post_meta() redirects a revision to its parent. The metadata
            // API on the exact revision is the native way to seed this cascade.
            if (add_metadata('post', $id, '_core_ssh_delete_note', 'revision child metadata', true) === false) {
                self::fail('native revision metadata was not created');
            }
        }
        return $context;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function observe(array $context): array {
        global $wpdb;
        self::context($context);
        $roots = implode(',', array_values($context['ids']));
        $ids = implode(',', array_merge(array_values($context['ids']), $context['revisions']));
        $comment = $context['comment'];
        $result = [
            'posts' => self::rows("SELECT * FROM {$wpdb->posts} WHERE ID IN ($ids) ORDER BY ID", ['ID', 'post_type', 'post_parent']),
            'postmeta' => self::rows("SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($ids) ORDER BY meta_id", ['meta_id', 'post_id', 'meta_key', 'meta_value']),
            'relationships' => self::rows("SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ($ids) ORDER BY object_id,term_taxonomy_id", ['object_id', 'term_taxonomy_id']),
            'children' => self::rows("SELECT * FROM {$wpdb->posts} WHERE post_parent IN ($roots) ORDER BY ID", ['ID', 'post_type', 'post_parent']),
            'comments' => self::rows("SELECT * FROM {$wpdb->comments} WHERE comment_ID=$comment ORDER BY comment_ID", ['comment_ID', 'comment_post_ID', 'comment_content']),
            'commentmeta' => self::rows("SELECT * FROM {$wpdb->commentmeta} WHERE comment_id=$comment ORDER BY meta_id", ['meta_id', 'comment_id', 'meta_key', 'meta_value']),
            'options' => self::rows("SELECT * FROM {$wpdb->options} WHERE option_name IN ('admin_email','home','siteurl','scoped-apply_scoped_option') ORDER BY option_name", ['option_id', 'option_name', 'option_value', 'autoload']),
            'uploads' => self::uploads($context['uploads']),
            'fk' => self::foreignKeyRows(),
        ];
        if ($context['uuids'] !== []) {
            $uuidSql = implode(',', array_map(static fn(string $uuid): string => $wpdb->prepare('%s', $uuid), $context['uuids']));
            $result['map'] = self::rows("SELECT * FROM {$wpdb->prefix}wprism_map WHERE uuid IN ($uuidSql) ORDER BY uuid,id_kind", ['uuid', 'entity_type', 'id_kind', 'local_id']);
            $result['state'] = self::rows("SELECT * FROM {$wpdb->prefix}wprism_state WHERE uuid IN ($uuidSql) ORDER BY uuid", ['uuid', 'entity_type', 'content_hash']);
        }
        // A failed JSON encoding is not an empty, stable observation either.
        json_encode($result, JSON_THROW_ON_ERROR);
        return $result;
    }

    /** @param array<string,mixed> $context */
    public static function installForeignKey(array $context): void {
        global $wpdb;
        self::context($context);
        if (self::foreignKeyRows() !== null) {
            self::fail('foreign-key fixture table is already occupied');
        }
        $table = self::foreignKeyTable();
        // Real CASCADE metadata is the dangerous boundary. InnoDB's native
        // referential action would escape the declared mutation table set;
        // the product must refuse before its authored DELETE, not roll one back.
        self::write("CREATE TABLE $table (id bigint unsigned NOT NULL PRIMARY KEY, post_id bigint unsigned NOT NULL, CONSTRAINT core_ssh_delete_fk FOREIGN KEY (post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE) ENGINE=InnoDB");
        self::write($wpdb->prepare("INSERT INTO $table (id,post_id) VALUES (1,%d)", $context['ids']['post']));
        if (self::foreignKeyRows() !== [['id' => '1', 'post_id' => (string) $context['ids']['post']]]) {
            self::fail('foreign-key fixture did not retain its exact child row');
        }
    }

    /** @param array<string,mixed> $context */
    public static function removeForeignKey(array $context): void {
        self::context($context);
        if (self::foreignKeyRows() !== [['id' => '1', 'post_id' => (string) $context['ids']['post']]]) {
            self::fail('foreign-key fixture ownership changed before removal');
        }
        self::write('DROP TABLE ' . self::foreignKeyTable());
        if (self::foreignKeyRows() !== null) {
            self::fail('owned foreign-key fixture table survived removal');
        }
    }

    /** @return array<string,mixed> */
    public static function refusalProfile(array $context): array {
        self::context($context);
        if (count($context['uuids']) !== 3) {
            self::fail('foreign-key refusal context requires all three deletion identities');
        }
        $cause = 'wprism: apply transaction start mutation scope refused — a foreign-key referential action escapes the declared mutation tables';
        // ApplyPreparationCoordinator preserves both explicit force decisions
        // before transaction admission. The coordinator wraps the FK cause
        // without publishing these native identities in its typed envelope.
        $operator = 'Warning: FORCED delete of guarded post ' . $context['uuids'][0]
            . ': 1 rows in comments will be orphaned; comments is not a declared authored-snapshot table '
            . 'and must be resolved through its owning content workflow. Surviving rows: comments.comment_ID=' . $context['comment']
            . "\nWarning: FORCED deletion conflict " . $context['uuids'][1]
            . " (target entity changed locally since the tombstone base)\n" . $cause;
        return ['command' => 'apply', 'reason_code' => 'apply_forced_override_failed', 'nodes' => [[
            'parent_index' => null,
            'relation' => 'root',
            'class' => 'WPrism\\CommandRefusalException',
            'message' => $operator,
        ], [
            'parent_index' => 0,
            'relation' => 'previous',
            'class' => 'RuntimeException',
            'message' => $cause,
        ]]];
    }

    /** @param list<string> $required @return list<array<string,?string>> */
    private static function rows(string $sql, array $required): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows) || !array_is_list($rows) || count($rows) > self::ROW_LIMIT) {
            self::fail('native row read failed or exceeded its bounded shape');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row) || array_diff($required, array_keys($row)) !== []) {
                self::fail('native row omitted its declared columns');
            }
            foreach ($row as $key => $value) {
                if (!is_string($key) || ($value !== null && !is_string($value))) {
                    self::fail('native row is not a text-protocol scalar record');
                }
            }
        }
        return $rows;
    }

    /** @return ?list<array<string,?string>> */
    private static function foreignKeyRows(): ?array {
        global $wpdb;
        $table = self::foreignKeyTable();
        $wpdb->last_error = '';
        $present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error !== '' || ($present !== null && $present !== $table)) {
            self::fail('native foreign-key table presence read failed');
        }
        return $present === null ? null : self::rows("SELECT id,post_id FROM $table ORDER BY id", ['id', 'post_id']);
    }

    private static function foreignKeyTable(): string {
        global $wpdb;
        $table = $wpdb->prefix . 'core_ssh_delete_fk';
        if (preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $table) !== 1) {
            self::fail('foreign-key table identifier is malformed');
        }
        return $table;
    }

    private static function write(string $sql): void {
        global $wpdb;
        $wpdb->last_error = '';
        if ($wpdb->query($sql) === false || $wpdb->last_error !== '') {
            self::fail('native fixture mutation failed');
        }
    }

    /** @param list<string> $paths @return array<string,string> */
    private static function uploads(array $paths): array {
        $upload = wp_upload_dir();
        $base = $upload['basedir'] ?? null;
        if (!is_string($base) || !is_dir($base) || is_link($base) || realpath($base) !== $base) {
            self::fail('native upload root is unsafe');
        }
        $hashes = [];
        foreach ($paths as $relative) {
            if (preg_match('#\A[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\z#', $relative) !== 1
                || array_intersect(explode('/', $relative), ['.', '..']) !== []) {
                self::fail('native upload relative path is malformed');
            }
            $path = $base;
            foreach (explode('/', $relative) as $part) {
                $path .= '/' . $part;
                if (is_link($path)) {
                    self::fail('native upload path is linked');
                }
            }
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                self::fail('native upload bytes cannot be observed');
            }
            $hashes[$relative] = $hash;
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    /** @param array<string,mixed> $context */
    private static function context(array $context): void {
        if (array_keys($context) !== ['ids', 'comment', 'revisions', 'uploads', 'uuids']
            || !is_array($context['ids']) || array_keys($context['ids']) !== array_keys(self::SLUGS)
            || !is_array($context['revisions']) || !array_is_list($context['revisions']) || count($context['revisions']) > 128
            || !is_array($context['uploads']) || !array_is_list($context['uploads'])
            || count($context['uploads']) < 2 || count($context['uploads']) > 32
            || !is_array($context['uuids']) || !array_is_list($context['uuids'])
            || !in_array(count($context['uuids']), [0, 3], true)) {
            self::fail('fixture context is not its exact bounded shape');
        }
        foreach (array_merge(array_values($context['ids']), [$context['comment']], $context['revisions']) as $id) {
            if (!is_int($id) || $id < 1) {
                self::fail('fixture context identity is malformed');
            }
        }
        if (count(array_unique(array_merge(array_values($context['ids']), $context['revisions']))) !== 3 + count($context['revisions'])
            || count(array_unique($context['uploads'])) !== count($context['uploads'])
            || count(array_unique($context['uuids'])) !== count($context['uuids'])) {
            self::fail('fixture context repeats an identity');
        }
        foreach ($context['uploads'] as $path) {
            if (!is_string($path)) {
                self::fail('fixture upload path is not a string');
            }
        }
        foreach ($context['uuids'] as $uuid) {
            if (!is_string($uuid) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $uuid) !== 1) {
                self::fail('fixture context UUID is malformed');
            }
        }
    }

    private static function nativeId(mixed $id): int {
        if (is_wp_error($id)) {
            self::fail('native WordPress identity mutation refused');
        }
        return self::positiveId($id);
    }

    private static function positiveId(mixed $id): int {
        if ((!is_int($id) && !is_string($id)) || preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $id) !== 1) {
            self::fail('native WordPress identity is malformed');
        }
        return (int) $id;
    }

    private static function fail(string $reason): never {
        throw new \RuntimeException('core SSH deletion fixture: ' . $reason);
    }
}
