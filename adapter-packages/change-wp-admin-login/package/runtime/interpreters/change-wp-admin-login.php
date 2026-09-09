<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\Policy;
use WPrism\PlainData;

/**
 * AIO Login 2.4.1 save_login_redirection_rule() admits one all_users rule at
 * order zero in the free artifact. Pro residue can contain user ID lists at
 * condition_value; passing those through would claim portability without a
 * reviewed identity contract. All reference and URL rewriting stays generic.
 */
final class ChangeWpAdminLogin {
    private const RULES = 'aio_login_pro_login_redirection_rules';
    private const FIELDS = ['id', 'condition_type', 'condition_value', 'login_target_type',
        'login_target_value', 'logout_target_type', 'logout_target_value', 'order', 'created_at'];

    public function __construct(Policy $policy) {}

    public function post_meta_rule(string $key, array $allMeta): ?array { return null; }

    public function option_rule(string $name, array $allOptions): ?array {
        if ($name === self::RULES && array_key_exists($name, $allOptions)) {
            self::assert_rules(PlainData::decode($allOptions[$name], "AIO Login $name"));
        }
        return null;
    }

    private static function assert_rules(mixed $rules): void {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 1) {
            self::refuse('expected a list containing at most one free all-users rule');
        }
        foreach ($rules as $rule) {
            if (!is_array($rule) || array_is_list($rule)
                || array_diff(array_keys($rule), self::FIELDS) !== []
                || array_diff(self::FIELDS, array_keys($rule)) !== []) {
                self::refuse('rule fields differ from the native 2.4.1 writer; save the rule through AIO Login first');
            }
            if ($rule['condition_type'] !== 'all_users' || $rule['condition_value'] !== '' || $rule['order'] !== 0) {
                self::refuse('user, role, and priority rules require a separately qualified Pro adapter');
            }
            if (!is_string($rule['id']) || trim($rule['id']) === ''
                || !is_int($rule['created_at']) || $rule['created_at'] < 0) {
                self::refuse('rule identity or creation time does not match the native writer');
            }
            foreach (['login', 'logout'] as $event) {
                if (!in_array($rule[$event . '_target_type'], ['page', 'custom'], true)
                    || !is_string($rule[$event . '_target_value'])) {
                    self::refuse('redirect targets must be native page/custom string values');
                }
            }
            if (trim($rule['login_target_value']) === '') self::refuse('the login destination cannot be empty');
        }
    }

    private static function refuse(string $reason): never {
        throw new \RuntimeException('wprism: AIO Login free redirect contract refused: ' . $reason);
    }
}
