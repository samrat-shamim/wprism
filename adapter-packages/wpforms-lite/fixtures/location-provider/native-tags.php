<?php
declare(strict_types=1);

require_once __DIR__ . '/native-admin.php';
require_once __DIR__ . '/tag-evidence.php';

/** Real native AJAX authoring and fresh consumers, never a copied tag writer. */
final class WPFormsNativeTags {
    public static function physical(array $formIds): array {
        global $wpdb;
        $out = [];
        $tables = [
            'terms' => ['term_id', 'term_id,name,slug,term_group'],
            'term_taxonomy' => ['term_taxonomy_id', 'term_taxonomy_id,term_id,taxonomy,description,parent,count'],
            'term_relationships' => ['object_id,term_taxonomy_id', 'object_id,term_taxonomy_id,term_order'],
            'termmeta' => ['meta_id', 'meta_id,term_id,meta_key,meta_value'],
        ];
        foreach ($tables as $name => [$order, $columns]) {
            $bytes = implode('+', array_map(static fn(string $column): string => 'OCTET_LENGTH(' . $column . ')', explode(',', $columns)));
            $wpdb->last_error = '';
            $size = $wpdb->get_row("SELECT COUNT(*) AS n, COALESCE(SUM($bytes),0) AS bytes FROM {$wpdb->$name}", ARRAY_A);
            WPFormsTagEvidence::check(is_array($size) && $wpdb->last_error === '' && ctype_digit($size['n'])
                && ctype_digit($size['bytes']) && (int) $size['n'] <= 512 && (int) $size['bytes'] <= 65536, 'bounded complete physical ' . $name);
            $out[$name] = $wpdb->get_results("SELECT * FROM {$wpdb->$name} ORDER BY $order LIMIT 513", ARRAY_A);
            WPFormsTagEvidence::check(is_array($out[$name]) && count($out[$name]) === (int) $size['n'] && $wpdb->last_error === '', 'complete physical ' . $name . ' transfer');
        }
        $out['forms'] = [];
        foreach ($formIds as $formId) {
            WPFormsTagEvidence::check(is_int($formId) && $formId > 0, 'explicit native form identity');
            $size = $wpdb->get_var($wpdb->prepare("SELECT OCTET_LENGTH(post_content) FROM {$wpdb->posts} WHERE ID = %d", $formId));
            WPFormsTagEvidence::check(is_string($size) && ctype_digit($size) && (int) $size <= 65536 && $wpdb->last_error === '', 'bounded native form body');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $formId), ARRAY_A);
            WPFormsTagEvidence::check(is_array($row) && $wpdb->last_error === '', 'complete physical form row');
            $out['forms'][] = $row;
        }
        WPFormsTagEvidence::physical($out);
        return $out;
    }

    public static function author(string $pair, string $side, string $mode, int $formId): void {
        WPFormsTagEvidence::check(in_array($side . ':' . $mode, ['source:first', 'source:initial', 'source:change',
            'target:local', 'target:clear', 'target:initial'], true), 'declared native tag author sequence');
        $record = ['format' => 'wprism-wpforms-native-tag-author/v1', 'version' => WPFORMS_VERSION,
            'side' => $side, 'mode' => $mode, 'form_id' => $formId, 'home' => home_url(), 'actor' => wp_get_current_user()->user_login,
            'capabilities' => ['edit_others_forms' => wpforms_current_user_can('edit_others_forms'),
                'edit_form_single' => wpforms_current_user_can('edit_form_single', $formId)],
            'debug_config' => [WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY], 'diagnostics_before' => WPFormsNativeAdminSession::diagnostics(),
            'before' => self::physical([$formId]), 'requests' => [], 'session_retired' => false];
        $session = new WPFormsNativeAdminSession($pair, $side);
        try {
            $overview = $session->request('/wp-admin/admin.php?page=wpforms-overview', 'GET');
            $native = WPFormsTagEvidence::overview($overview['body'], $formId);
            $session->request('/wp-admin/admin-ajax.php', 'POST', WPFormsTagEvidence::post($native, $formId, $mode),
                $record['home'] . '/wp-admin/admin.php?page=wpforms-overview');
        } finally {
            $record['requests'] = $session->requests;
            if ($session->transportError !== null) $record['transport_error'] = $session->transportError;
            try {
                $record['session_retired'] = $session->retire();
                // This CLI boot predates the HTTP writer. Physical rows, not
                // warmed native caches, own this immediate post-save witness.
                $record['after'] = self::physical([$formId]);
                $record['diagnostics_after'] = WPFormsNativeAdminSession::diagnostics();
            } finally {
                echo wp_json_encode($record, JSON_THROW_ON_ERROR);
            }
        }
        WPFormsTagEvidence::author($record, $pair, $side, $mode);
    }

    public static function observe(array $formIds, bool $identities): array {
        $physical = self::physical($formIds);
        $consumers = [];
        foreach ($formIds as $formId) {
            $terms = get_the_terms($formId, WPFormsTagEvidence::TAXONOMY);
            WPFormsTagEvidence::check($terms === false || is_array($terms), 'fresh native form tag consumer');
            $consumers[(string) $formId] = ['terms' => array_map(static fn(WP_Term $term): array => $term->to_array(), $terms ?: []),
                'body' => wpforms()->obj('form')->get($formId, ['content_only' => true])];
        }
        $mappings = [];
        if ($identities) foreach ($physical['term_taxonomy'] as $tt) {
            if ($tt['taxonomy'] !== WPFormsTagEvidence::TAXONOMY) continue;
            $mappings[$tt['term_id']] = ['term' => WPrism\Ledger::uuid_for((int) $tt['term_id'], 'term'),
                'term_taxonomy' => WPrism\Ledger::uuid_for((int) $tt['term_taxonomy_id'], 'term_taxonomy')];
        }
        return ['physical' => $physical, 'consumers' => $consumers,
            'choices' => WPForms\Admin\Forms\Tags::get_all_tags_choices(), 'identities' => $mappings,
            'diagnostics' => WPFormsNativeAdminSession::diagnostics()];
    }
}
