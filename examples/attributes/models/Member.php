<?php

/**
 * @property int    $id
 * @property int    $company_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $password_hash
 * @property int    $is_admin
 * @property-read string  $full_name
 * @property string  $email_address  alias of email
 * @property-read Company $company
 * @property-read string  $country    delegated from company
 */
class Member extends ActiveRecord\Model
{
    public static $belongs_to = [['company']];

    // Mass-assignment whitelist: only these are set from an array; is_admin is ignored.
    public static $attr_accessible = ['first_name', 'last_name', 'email', 'company_id'];

    // Expose email under a second name.
    public static $alias_attribute = ['email_address' => 'email'];

    // Read country straight off the associated company.
    public static $delegate = [['country', 'to' => 'company']];

    // Computed read-only attribute (get_ prefix -> $member->full_name).
    public function get_full_name(): string
    {
        return trim((string) $this->read_attribute('first_name') . ' ' . (string) $this->read_attribute('last_name'));
    }

    // Custom writer (set_ prefix -> $member->password = ...): never store plaintext.
    public function set_password(string $plaintext): void
    {
        $this->assign_attribute('password_hash', hash('sha256', $plaintext));
    }
}
