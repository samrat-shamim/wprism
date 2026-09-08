<?php
declare(strict_types=1);

namespace WPForms\Forms;

/**
 * Supplied native-response model, not a copied WPForms scanner/parser.
 * Real plugin/UI and separate-process evidence remain independent gates.
 */
final class Locator {
    public const STANDALONE_LOCATION_TYPES = ['form_pages', 'conversational_forms'];
    public int $postScans = 0;
    public int $standaloneBuilds = 0;
    public array $formsByContent = [];
    public array $standaloneLocations = [];
    public array $widgetLocations = [];

    public function get_post_types(): array { return ['post', 'page']; }
    public function get_post_statuses(): array { return ['publish', 'draft', 'pending', 'future', 'private']; }
    public function get_form_ids(string $content): array {
        $this->postScans++;
        return $this->formsByContent[$content] ?? [];
    }
    public function search_in_widgets(): array {
        foreach (['widget_wpforms-widget', 'widget_text', 'widget_block'] as $name) get_option($name, []);
        return $this->widgetLocations;
    }
    public function build_standalone_location(int $id, array $data, string $status): array {
        $this->standaloneBuilds++;
        return $this->standaloneLocations[$id] ?? [];
    }
}
