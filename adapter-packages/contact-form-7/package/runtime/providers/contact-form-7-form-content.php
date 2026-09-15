<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\Providers;
use WPrism\ProviderSdk;

/**
 * Re-derives each applied Contact Form 7 form's post_content on the target.
 *
 * WHY IT EXISTS
 * -------------
 * The form body is declared `derived` (agent/src/Grammar/DerivedBodyGrammar.php):
 * the repository carries none and apply writes none, because it is only CF7's
 * flattening of properties this adapter already carries as meta. CF7 derives it
 * in exactly one place, WPCF7_ContactForm::save() (CF7 6.1.7
 * includes/contact-form.php:1263, stored trimmed at :1270/:1277), and apply
 * writes the properties without calling save(). CF7 still reads the body
 * implicitly: its admin forms list (admin/includes/class-contact-forms-list-table.php:41)
 * and REST `contact-forms?search=` (includes/rest-api.php:172) pass `s` to
 * WP_Query, which searches post_content. Without this repair a form apply
 * created keeps the '' ensure_post_row() inserted, and a form apply updated
 * keeps a derivation of the properties it had before.
 *
 * WHY NOT save()
 * --------------
 * save() rewrites every property as meta through wpcf7_normalize_newline_deep(),
 * which can move authored bytes apply has just written; it rewrites the title
 * and status; and it fires wpcf7_after_save for integrations. This provider
 * performs only the derivation save() performs for post_content — through CF7's
 * own loader and flattener — and writes only that column. It stores the
 * derivation, not the output of whatever content_save_pre filters the acting
 * user's capabilities would have selected.
 *
 * WHY TWO PHASES
 * --------------
 * WPCF7_ContactForm's constructor runs its property filters and fires
 * `wpcf7_contact_form` for any add-on to hook. Inside the fenced write
 * transaction, an add-on's unrelated query would escape the declared tables and
 * turn into a provider refusal — a failed apply on sites that apply cleanly
 * without this provider. So CF7 derives unfenced, bound to a witness of the
 * exact property rows it read, and the fenced transaction rechecks that witness
 * under repeatable read before writing. A derivation of rows that moved is
 * refused rather than stored.
 *
 * Receipts carry counts and digests only. A derived body holds mail properties,
 * including the addresses `_mail` carries under its reviewed allow_pii rule.
 */
final class ContactForm7FormContent extends ManifestProviderRuntime {
    private const CONTEXT = 'Contact Form 7 form content rebuild';
    private const POST_KIND = 'post:wpcf7_contact_form';
    private const POST_TYPE = 'wpcf7_contact_form';
    /**
     * The five CF7 6.1.7 form properties in both admitted storage generations:
     * the only rows WPCF7_ContactForm::construct_properties() derives from.
     */
    private const PROPERTY_KEYS = [
        '_additional_settings', '_form', '_mail', '_mail_2', '_messages',
        'additional_settings', 'form', 'mail', 'mail_2', 'messages',
    ];
    /** One apply's CF7 surface; a wider batch is refused rather than truncated. */
    private const MAX_FORMS = 512;

    /** @param array<string,mixed> $args */
    protected function invoke_rebuild_form_content(array $args): array {
        $ids = self::form_ids($args);
        $derived = $this->derive($ids);
        $result = ProviderSdk::database_write_contract_transaction(
            self::CONTEXT,
            function () use ($ids, $derived): array {
                global $wpdb;
                $before = [];
                foreach ($ids as $id) {
                    if ($this->property_witness($id) !== $derived[$id]['witness']) {
                        throw new \RuntimeException(
                            "wprism: Contact Form 7 form $id properties changed between derivation and write; recovery_required"
                        );
                    }
                    $before[$id] = $this->stored_content($id);
                    if ($before[$id] !== $derived[$id]['content']) {
                        ProviderSdk::database_update(
                            $wpdb->posts,
                            ['post_content' => $derived[$id]['content']],
                            ['ID' => $id],
                            self::CONTEXT,
                            ['%s'],
                            ['%d']
                        );
                    }
                }
                $after = [];
                foreach ($ids as $id) {
                    $after[$id] = $this->stored_content($id);
                    if ($after[$id] !== $derived[$id]['content']) {
                        throw new \RuntimeException(
                            "wprism: Contact Form 7 form $id content did not read back as derived; recovery_required"
                        );
                    }
                }
                return ['before' => $before, 'after' => $after];
            },
            function (array $result) use ($ids): string {
                $actual = [];
                foreach ($ids as $id) {
                    $actual[$id] = $this->stored_content($id);
                }
                if ($actual === $result['after']) {
                    return ProviderSdk::DATABASE_POSTIMAGE_APPLIED;
                }
                if ($actual === $result['before']) {
                    return ProviderSdk::DATABASE_POSTIMAGE_NOT_APPLIED;
                }
                return ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN;
            }
        );
        foreach ($ids as $id) {
            clean_post_cache($id);
        }
        $durable = $this->durable_contents($ids);
        foreach ($ids as $id) {
            if ($durable[$id] !== $derived[$id]['content']) {
                throw new \RuntimeException(
                    "wprism: Contact Form 7 form $id content did not persist as derived; recovery_required"
                );
            }
        }
        return [
            'before' => self::projection($result['before'], $derived),
            'after' => self::projection($durable, $derived),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    protected function reconcile_rebuild_form_content(array $args): array {
        $ids = self::form_ids($args);
        return self::projection($this->durable_contents($ids), $this->derive($ids));
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{content:string,witness:string}>
     */
    private function derive(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            // Apply wrote these properties with SQL; a request cache primed
            // before that write would hand CF7 the previous generation.
            clean_post_cache($id);
            $witness = $this->property_witness($id);
            $form = \WPCF7_ContactForm::get_instance($id);
            if (!$form instanceof \WPCF7_ContactForm || (int) $form->id() !== $id) {
                throw new \RuntimeException(
                    "wprism: Contact Form 7 could not load form $id for content derivation; recovery_required"
                );
            }
            // WPCF7_ContactForm::save(), CF7 6.1.7 includes/contact-form.php:1263
            // and :1270/:1277. Its wp_slash()/wp_unslash() round trip is the
            // identity over the joined string, so it is not repeated here.
            $content = trim(implode("\n", \wpcf7_array_flatten($form->get_properties())));
            if ($this->property_witness($id) !== $witness) {
                throw new \RuntimeException(
                    "wprism: Contact Form 7 form $id properties changed while CF7 read them; recovery_required"
                );
            }
            $out[$id] = ['content' => $content, 'witness' => $witness];
        }
        return $out;
    }

    private function property_witness(int $id): string {
        global $wpdb;
        $rows = ProviderSdk::checked_get_results(
            $wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id),
            self::CONTEXT . ' property witness'
        );
        $properties = [];
        foreach ($rows as $row) {
            if (in_array((string) ($row['meta_key'] ?? ''), self::PROPERTY_KEYS, true)) {
                $properties[] = [(int) $row['meta_id'], (string) $row['meta_key'], (string) $row['meta_value']];
            }
        }
        // Statement order is unspecified without ORDER BY; the witness must not be.
        usort($properties, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return hash('sha256', serialize($properties));
    }

    private function stored_content(int $id): string {
        global $wpdb;
        $row = ProviderSdk::checked_get_row(
            $wpdb->prepare("SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE ID = %d", $id),
            self::CONTEXT . ' stored content'
        );
        if (!is_array($row) || (int) ($row['ID'] ?? 0) !== $id
            || ($row['post_type'] ?? null) !== self::POST_TYPE
            || !is_string($row['post_content'] ?? null)) {
            throw new \RuntimeException(
                "wprism: Contact Form 7 content rebuild was handed id $id, which is not a Contact Form 7 form row; recovery_required"
            );
        }
        return $row['post_content'];
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function durable_contents(array $ids): array {
        return ProviderSdk::database_read_contract_snapshot(
            self::CONTEXT . ' durable evidence',
            function () use ($ids): array {
                $out = [];
                foreach ($ids as $id) {
                    $out[$id] = $this->stored_content($id);
                }
                return $out;
            }
        );
    }

    /**
     * The engine-assembled entity batch, exactly: this capability declares no
     * arguments and no context channels, so the reserved key is the only one
     * and each row is the bare {kind, id} ProviderActionBatchBuilder emits.
     *
     * @param array<string,mixed> $args
     * @return list<int>
     */
    private static function form_ids(array $args): array {
        $rows = $args[Providers::ENTITIES_ARG] ?? null;
        if (array_keys($args) !== [Providers::ENTITIES_ARG] || !is_array($rows) || !array_is_list($rows)
            || $rows === [] || count($rows) > self::MAX_FORMS) {
            throw new \RuntimeException(
                'wprism: Contact Form 7 content rebuild requires one bounded engine entity batch and no other arguments'
            );
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== ['kind', 'id'] || $row['kind'] !== self::POST_KIND
                || !is_int($row['id']) || $row['id'] <= 0 || isset($ids[$row['id']])) {
                throw new \RuntimeException(
                    'wprism: Contact Form 7 content rebuild batch rows must be distinct post:wpcf7_contact_form ids'
                );
            }
            $ids[$row['id']] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param array<int,string> $contents
     * @param array<int,array{content:string,witness:string}> $derived
     * @return array{forms:int,derived:int,content_sha256:string}
     */
    private static function projection(array $contents, array $derived): array {
        ksort($contents, SORT_NUMERIC);
        $matching = 0;
        $digests = [];
        foreach ($contents as $id => $content) {
            if ($content === $derived[$id]['content']) {
                $matching++;
            }
            $digests[] = $id . ':' . hash('sha256', $content);
        }
        return [
            'forms' => count($contents),
            'derived' => $matching,
            'content_sha256' => hash('sha256', implode("\n", $digests)),
        ];
    }
}
