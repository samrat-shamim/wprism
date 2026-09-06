<?php
declare(strict_types=1);

/** Capsule-owned native intent, shared by the writer and independent host acceptance. */
final class PolylangBiographyValues {
    public const KEYS = ['description', 'description_fr', 'description_ar'];

    public static function authored(string $home): array {
        $home = rtrim($home, '/');
        // Default English uses description, not description_en: Polylang
        // 3.8.6 admin-filters.php:47-54 writes exactly this native key roster.
        return [
            'description' => '<strong>English author 東京 🚀</strong> <a href="' . $home . '/biography-en/">About &amp; work</a>',
            'description_fr' => '<em>Auteur français 東京 🚀</em> <a href="' . $home . '/biography-fr/">Œuvres &amp; parcours</a>',
            'description_ar' => '<strong>كاتب عربي বাংলা 🚀</strong> <a href="' . $home . '/biography-ar/">السيرة والأعمال</a>',
        ];
    }

    public static function hostile(): array {
        return [
            'script' => '<script>alert(1)</script>Biography',
            'event-handler' => '<a href="https://example.test/" onclick="alert(1)">Biography</a>',
            'javascript-uri' => '<a href="javascript:alert(1)">Biography</a>',
            'data-uri' => '<a href="data:text/html,hostile">Biography</a>',
            'unknown-entity' => 'Biography &wprism_unknown_entity;',
        ];
    }
}
