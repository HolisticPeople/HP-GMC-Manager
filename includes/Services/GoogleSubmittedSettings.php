<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) { exit; }

/** Observed Google settings, separate from current local store configuration. */
final class GoogleSubmittedSettings
{
    public const OPTION = 'hp_gmc_submitted_settings_v1';
    public const HISTORY_OPTION = 'hp_gmc_submitted_settings_history_v1';
    public const SUPPORT = 'https://merchants.google.com/mc/merchantprofile/businessinfo?a=5298746911';
    public const RETURNS = 'https://merchants.google.com/mc/returnpolicies/adsorganic/standard/edit?a=5298746911&policyId=9298149193';

    public static function import(array $value)
    {
        $clean = self::sanitize($value);
        if ($clean instanceof \WP_Error) { return $clean; }
        $prior = self::current();
        if ($prior && $clean['observed_at'] <= $prior['observed_at']) { return self::error('Observation time must advance.'); }
        $history = self::history();
        if ($prior) { array_unshift($history, $prior); }
        update_option(self::HISTORY_OPTION, array_slice($history, 0, 30), false);
        update_option(self::OPTION, $clean, false);
        return get_option(self::OPTION) === $clean ? true : self::error('Observation storage failed.');
    }

    public static function current(): ?array
    {
        $clean = self::sanitize(get_option(self::OPTION, null));
        return is_array($clean) ? $clean : null;
    }

    public static function history(): array
    {
        $history = get_option(self::HISTORY_OPTION, []);
        if (!is_array($history)) { return []; }
        $clean = [];
        foreach (array_slice($history, 0, 30) as $row) {
            $item = self::sanitize($row);
            if (is_array($item)) { $clean[] = $item; }
        }
        return $clean;
    }

    private static function sanitize($value)
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || !self::validTime($value['observed_at'] ?? null)
            || !is_array($value['support'] ?? null) || !is_array($value['returns'] ?? null) || !is_array($value['loyalty'] ?? null)) {
            return self::error('Invalid submitted-settings envelope or UTC observation time.');
        }
        $support = $value['support']; $returns = $value['returns'];
        if (($support['source'] ?? null) !== self::SUPPORT || ($returns['source'] ?? null) !== self::RETURNS
            || !is_string($support['url'] ?? null) || strlen($support['url']) > 160
            || !preg_match('~^https://holisticpeople\.com/(?:return-policy/|contact/|contact-us/)?$~D', $support['url'])
            || !is_string($support['email'] ?? null) || strlen($support['email']) > 160 || !filter_var($support['email'], FILTER_VALIDATE_EMAIL)
            || !is_string($support['phone'] ?? null) || !preg_match('/^\+[1-9][0-9]{6,14}$/D', $support['phone'])) {
            return self::error('Invalid observed support source or public contact fields.');
        }
        if (($returns['policy_id'] ?? null) !== 9298149193
            || !in_array($returns['status'] ?? null, ['verified', 'pending', 'unverified', 'rejected', 'not_observed'], true)
            || !array_key_exists('days', $returns) || ($returns['days'] !== null && (!is_int($returns['days']) || $returns['days'] < 0 || $returns['days'] > 3650))
            || !in_array($returns['cost'] ?? null, ['customer_responsibility', 'free', 'unknown'], true)
            || !array_key_exists('products', $returns) || ($returns['products'] !== null && (!is_int($returns['products']) || $returns['products'] < 0 || $returns['products'] > 10000000))) {
            return self::error('Invalid observed return-policy values.');
        }
        // A configured program requires its own verified details, not an unsupported flag.
        if (($value['loyalty']['status'] ?? null) !== 'not_observed') { return self::error('Loyalty program details have not been observed.'); }
        return ['version' => 1, 'observed_at' => $value['observed_at'],
            'support' => ['source' => self::SUPPORT, 'url' => $support['url'], 'email' => $support['email'], 'phone' => $support['phone']],
            'returns' => ['source' => self::RETURNS, 'policy_id' => 9298149193, 'status' => $returns['status'], 'days' => $returns['days'], 'cost' => $returns['cost'], 'products' => $returns['products']],
            'loyalty' => ['status' => 'not_observed']];
    }

    private static function validTime($value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        return $date && !($errors && ($errors['warning_count'] || $errors['error_count']))
            && $date->format('Y-m-d\TH:i:s\Z') === $value && $date->getTimestamp() <= time() + 60;
    }

    private static function error(string $message): \WP_Error
    {
        return new \WP_Error('hp_gmc_submitted_settings_invalid', $message);
    }
}
