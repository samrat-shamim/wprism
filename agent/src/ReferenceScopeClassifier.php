<?php
namespace Duo;

/**
 * Classifies unresolved local references without depending on capture.
 *
 * Token codecs first consult the durable identity map. When that lookup
 * fails, this service distinguishes a genuinely dangling id from a live row
 * outside reviewed policy scope. A live, in-scope row with no identity yet is
 * deliberately not a scope violation: identity minting order is a separate
 * concern owned by the candidate builder.
 */
final class ReferenceScopeClassifier {
    /** Return the live post type or taxonomy addressed by the local id. */
    public static function targetType(int $id, string $kind): ?string {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        if ($kind === 'post') {
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d AND post_type != 'revision' AND post_status != 'auto-draft'",
                $id
            ));
            return $type === null ? null : (string) $type;
        }
        if ($kind === 'term') {
            $taxonomy = $wpdb->get_var($wpdb->prepare(
                "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
                $id
            ));
            return $taxonomy === null ? null : (string) $taxonomy;
        }
        return null;
    }

    /**
     * Return the live target type only when the unresolved id is genuinely
     * outside policy scope. Dangling, forced, and in-scope/unminted ids return
     * null and retain their established warning/drop behavior.
     */
    public static function classify(int $id, string $kind, bool $force, object $policy): ?string {
        if ($force) {
            return null;
        }
        $targetType = self::targetType($id, $kind);
        if ($targetType === null) {
            return null;
        }
        $inPolicyScope = $kind === 'term'
            ? in_array($targetType, $policy->taxonomies(), true)
            : in_array($targetType, $policy->post_types(), true);
        return $inPolicyScope ? null : $targetType;
    }
}
