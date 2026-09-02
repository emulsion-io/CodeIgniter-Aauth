# Aauth 3 documentation

This guide groups Aauth usage by feature. Examples assume a CodeIgniter
controller with the library loaded:

```php
$this->load->library('aauth');
```

When supported, omitting a user ID or passing `false` targets the user stored in
the current session. Group and permission parameters accept an ID or name where
indicated.

## Contents

- [Authentication and sessions](#authentication-and-sessions)
- [Login throttling](#login-throttling)
- [Users and account states](#users-and-account-states)
- [Password recovery](#password-recovery)
- [Email verification](#email-verification)
- [TOTP two-factor authentication](#totp-two-factor-authentication)
- [CAPTCHA providers](#captcha-providers)
- [Groups and memberships](#groups-and-memberships)
- [Permissions and authorization](#permissions-and-authorization)
- [User variables](#user-variables)
- [Private messages](#private-messages)
- [Errors and information](#errors-and-information)
- [Configuration reference](#configuration-reference)

## Authentication and sessions

The identifier is an email by default, or a username when `login_with_name` is
enabled:

```php
$identifier = $this->input->post('identifier', false);
$password = $this->input->post('password', false);
$totpCode = $this->input->post('totp_code', false) ?: null;

if ($this->aauth->login($identifier, $password, $totpCode)) {
    redirect('account');
}
```

Check and close the CodeIgniter session with:

```php
if ($this->aauth->is_loggedin()) {
    $user = $this->aauth->get_user();
}

$this->aauth->logout();
```

Aauth 3 has no remember-me cookie. Configure persistence through the
CodeIgniter session driver.

`control()` requires authentication and optionally a permission, using the
configured redirects when access is refused:

```php
public function administration()
{
    $this->aauth->control('manage_site');
    // Authorized action.
}
```

## Login throttling

Aauth limits password and TOTP failures with two independent buckets:

- a lower per-identifier limit protects one account across many source IPs;
- a higher per-IP limit slows one source testing many accounts without quickly
  blocking every user behind the same NAT gateway.

The values stored in `aauth_login_attempts` are HMAC digests. Configure a
dedicated random secret, or let Aauth fall back to CodeIgniter's
`encryption_key`:

```php
'login_throttling'                        => true,
'login_throttle_identifier_limit'         => 5,
'login_throttle_ip_limit'                 => 30,
'login_throttle_totp_identifier_limit'    => 10,
'login_throttle_totp_ip_limit'            => 30,
'max_login_attempt_time_period'           => '15 minutes',
'login_throttle_lockout_time'             => '15 minutes',
'login_throttle_secret'                   => 'a-long-random-application-secret',
```

Generate the dedicated value once with `bin2hex(random_bytes(32))` and keep it
outside version control. Throttling fails closed when neither secret is set.
Configure CodeIgniter's trusted proxy addresses correctly as well, otherwise
the per-IP bucket may receive the reverse proxy address instead of the client
address.

Counters use an atomic database upsert, so simultaneous failures do not lose
increments. A successful authentication clears only that account's identifier
buckets; it deliberately does not clear the shared IP bucket. Expired buckets
are removed probabilistically, and can also be cleaned from a scheduled task:

```php
$this->aauth->cleanup_login_attempts();
```

This feature is application-level brute-force protection, not DDoS mitigation.
Use a reverse proxy, web server, or WAF for coarse request-rate limiting before
requests reach PHP.

Login-attempt helpers remain public for custom authentication flows:

```php
$attempts = $this->aauth->get_login_attempts($identifier);
$accepted = $this->aauth->update_login_attempts($identifier);
$cleared = $this->aauth->reset_login_attempts($identifier);
```

Calling these helpers without an identifier retains the legacy IP-only helper
behavior. `ddos_protection` and `max_login_attempt` are deprecated aliases for
older application configuration files.

## Users and account states

### Creating, reading, updating, and listing users

```php
$frodoId = $this->aauth->create_user(
    'frodo@example.com',
    'a-long-frodo-password',
    'FrodoBaggins'
);
$legolasId = $this->aauth->create_user(
    'legolas@example.com',
    'a-long-legolas-password',
    'Legolas'
);

$user = $this->aauth->get_user($frodoId);

$updated = $this->aauth->update_user(
    $frodoId,
    'frodo.baggins@example.com',
    false,
    'Frodo'
);

$users = $this->aauth->list_users(false, 25, 0, false, 'email ASC');
```

The `list_users()` arguments are group, limit, offset, include-banned flag, and
sort expression. Changing an email clears its previous verification and sends a
new link when verification is enabled.

Lookup helpers:

```php
$byEmail = $this->aauth->user_exist_by_email('frodo@example.com');
$byUsername = $this->aauth->user_exist_by_username('Frodo');
$byId = $this->aauth->user_exist_by_id($frodoId);
$userId = $this->aauth->get_user_id('frodo@example.com');
```

`user_exist_by_name()` remains an alias of `user_exist_by_username()`.

```php
$this->aauth->delete_user($frodoId);
```

### Verification and ban states

Verification and banning are independent:

```php
$this->aauth->ban_user($frodoId, 'Repeated abuse');

if ($this->aauth->is_banned($frodoId)) {
    // Access is suspended.
}

$this->aauth->unban_user($frodoId);
```

The reason is optional, so `ban_user($frodoId)` remains valid. Activity methods
accept an optional user ID:

```php
$this->aauth->update_last_login($userId);
$this->aauth->update_activity($userId);
```

## Password recovery

### Recommended link workflow

Send an expiring, single-use link:

```php
$sent = $this->aauth->remind_password('frodo@example.com');
```

Or create the link for another trusted private delivery channel:

```php
$link = $this->aauth->create_password_reset_link('frodo@example.com');
```

Never display that link publicly. At the reset endpoint:

```php
if (!$this->aauth->is_password_reset_token_valid($token)) {
    show_error('Invalid or expired reset link.', 400);
}

$success = $this->aauth->reset_password($token, $newPassword);
```

```php
'reset_password_expiration' => '+1 hour',
'password_recovery_mode'    => 'link',
```

Only a SHA-256 digest of the random 256-bit token is stored.

### Generated-password compatibility

Legacy one-argument reset controllers can opt in:

```php
'password_recovery_mode' => 'generated_password',

$success = $this->aauth->reset_password($token);
```

Aauth generates and hashes a secure password, then emails it. The transaction
is rolled back if delivery fails. This mode sends a reusable credential by
email and is less secure than the default link workflow.

## Email verification

```php
'verification'            => true,
'verification_link'       => '/account/verification/',
'verification_expiration' => '+24 hours',
```

Send or independently create a verification link:

```php
$sent = $this->aauth->send_verification($userId);
$link = $this->aauth->create_verification_link($userId);
```

Accept it at the configured endpoint:

```php
$verified = $this->aauth->verify_user($userId, $token);
```

Tokens are random, stored only as SHA-256 digests, expire after 24 hours by
default, and can be used once. Verification never removes an account ban.

## TOTP two-factor authentication

### Configuration and setup

```php
'totp_active'                  => true,
'totp_only_on_ip_change'       => false,
'totp_two_step_login_active'   => true,
'totp_two_step_login_redirect' => '/account/twofactor_verification/',
'totp_issuer'                  => 'Example site',
'totp_label'                   => '{issuer} - {email}',
'totp_qr_script'               => 'assets/js/qr-creator.min.js',
'totp_recovery_code_count'     => 10,
```

The label accepts `{issuer}`, `{site}`, `{email}`, and `{username}`. Generate a
secret and standard provisioning URI:

```php
$secret = $this->aauth->generate_unique_totp_secret();
$uri = $this->aauth->generate_totp_uri($secret, $userId);
```

Render `$uri` locally with the bundled QR library. Do not save the secret until
the first code has been verified:

```php
$this->load->helper('googleauthenticator');
$authenticator = new PHPGangsta_GoogleAuthenticator();

if ($authenticator->verifyCode($secret, $submittedCode, 1)) {
    $this->aauth->update_user_totp_secret($userId, $secret);
    $recoveryCodes = $this->aauth->generate_totp_recovery_codes($userId);
}
```

Display or export `$recoveryCodes` immediately after generation: Aauth returns
their clear-text values only once and stores only SHA-256 digests. Generating a
new list invalidates every previous code. Each code contains 80 random bits and
is consumed atomically after one successful use.

The remaining count can be displayed without exposing the codes:

```php
$remaining = $this->aauth->get_totp_recovery_code_count($userId);
```

Disable TOTP with:

```php
$this->aauth->update_user_totp_secret($userId, '');
```

Disabling TOTP also deletes all recovery codes. They are deleted as well when
`totp_reset_over_reset_password` removes TOTP during a password reset.

### TOTP login

For one form, supply the code as the third login argument. With two-step login,
check pending state and complete it on the dedicated page:

```php
$this->aauth->login($identifier, $password, $totpCode);

if ($this->aauth->is_totp_required()) {
    redirect('account/twofactor_verification');
}

if ($this->aauth->verify_user_totp_code($totpOrRecoveryCode)) {
    redirect('account');
}
```

Both login modes accept either a current six-digit TOTP value or a recovery
code. A recovery code is single-use. The separate step is enabled by default.
After the password has been accepted,
Aauth stores only the pending user ID in the session and redirects to
`totp_two_step_login_redirect`; the authenticated session is created only after
the TOTP code succeeds. Set `totp_two_step_login_active` to `false` only when a
single form must submit the password and TOTP code together.

## CAPTCHA providers

Only one provider can be active. CAPTCHA appears after the configured number of
failed login attempts.

Cap configuration:

```php
'captcha_provider'      => 'cap',
'recaptcha_active'      => false,
'cap_instance_url'      => 'https://cap.example.com',
'cap_site_key'          => 'your-site-key',
'cap_secret'            => 'your-site-secret',
'cap_widget_script_url' => 'https://cdn.jsdelivr.net/npm/cap-widget@0.1.56',
'cap_widget_mode'       => 'invisible', // or 'checkbox'
```

Google reCAPTCHA configuration:

```php
'captcha_provider'  => 'recaptcha',
'recaptcha_active'  => false,
'recaptcha_siteKey' => 'your-site-key',
'recaptcha_secret'  => 'your-site-secret',
```

The legacy `recaptcha_active => true` toggle works while `captcha_provider` is
`false`. Selecting Cap at the same time raises a configuration error.

Render the selected provider inside the login form:

```php
echo form_open('account/login');
echo $this->aauth->generate_captcha_field($identifier);
echo form_submit('login', 'Sign in');
echo form_close();
```

`generate_recaptcha_field()` remains a compatible alias.

## Groups and memberships

### Group lifecycle

```php
$hobbitsId = $this->aauth->create_group('hobbits', 'Hobbit users');
$elvesId = $this->aauth->create_group('elves', 'Elf users');
$this->aauth->update_group($hobbitsId, 'shire_hobbits', 'Hobbits of the Shire');

$group = $this->aauth->get_group($hobbitsId);
$groupId = $this->aauth->get_group_id('elves');
$groupName = $this->aauth->get_group_name($elvesId);
$groups = $this->aauth->list_groups();

$this->aauth->delete_group($hobbitsId);
```

### Memberships and subgroups

```php
$this->aauth->add_member($frodoId, 'hobbits');
$this->aauth->remove_member($frodoId, 'hobbits');
$this->aauth->remove_member_from_all($frodoId);
$groups = $this->aauth->get_user_groups($frodoId);
```

`is_member()` means “member of at least one” and accepts one group, an array, or
the legacy pipe syntax:

```php
$this->aauth->is_member('admin', $userId);
$this->aauth->is_member('admin|editor', $userId);
$this->aauth->is_member(array('admin', 'editor'), $userId);
$this->aauth->is_member_of_any(array('admin', 'editor'), $userId);
$this->aauth->is_member_of_all(array('admin', 'editor'), $userId);
$this->aauth->is_admin($userId);
```

```php
$this->aauth->add_subgroup('staff', 'editors');
$subgroups = $this->aauth->get_subgroups('staff');
$this->aauth->remove_subgroup('staff', 'editors');
```

## Permissions and authorization

### Permission lifecycle

```php
$walkId = $this->aauth->create_perm('walk_unseen', 'Walk unseen');
$immortalityId = $this->aauth->create_perm('immortality');
$this->aauth->update_perm($walkId, 'walk_unseen', 'Move without notice');

$permission = $this->aauth->get_perm($walkId);
$permissionId = $this->aauth->get_perm_id('walk_unseen');
$permissions = $this->aauth->list_perms();
```

### Group and public permissions

```php
$this->aauth->allow_group('hobbits', 'walk_unseen');
$this->aauth->allow_group('elves', 'immortality');
$this->aauth->allow_group('hobbits', 'immortality');

// Hobbits should not have this permission after all.
$this->aauth->deny_group('hobbits', 'immortality');

if ($this->aauth->is_group_allowed('immortality', 'elves')) {
    echo 'Elves are immortal';
}

$groupPermissions = $this->aauth->get_group_perms('elves');
$groupPermissions = $this->aauth->list_group_perms('elves');
$this->aauth->allow_group('public', 'travel');
```

The permission is the first argument of `is_group_allowed()`.

### User permissions

```php
$gandalfId = $this->aauth->create_user(
    'gandalf@example.com',
    'a-long-gandalf-password',
    'GandalfTheGray'
);

$this->aauth->allow_user($gandalfId, 'immortality');

if ($this->aauth->is_allowed('immortality', $gandalfId)) {
    echo 'Gandalf is immortal';
}

$directPermissions = $this->aauth->get_user_perms($gandalfId);
$this->aauth->deny_user($gandalfId, 'immortality');
$this->aauth->delete_perm('immortality');
```

The permission is also the first argument of `is_allowed()`. Administrators
bypass normal permission checks.

## User variables

```php
$this->aauth->set_user_var('phone', '+33 1 23 45 67 89', $userId);
$phone = $this->aauth->get_user_var('phone', $userId);
$variables = $this->aauth->get_user_vars($userId);
$keys = $this->aauth->list_user_var_keys($userId);
$this->aauth->unset_user_var('phone', $userId);
```

System variables from older releases are not part of Aauth 3.

## Private messages

```php
$sent = $this->aauth->send_pm(
    $frodoId,
    $legolasId,
    'New cloaks',
    'These new cloaks are fantastic!'
);

$results = $this->aauth->send_pms(
    $senderId,
    array($frodoId, $legolasId),
    'Meeting',
    'The meeting starts at noon.'
);
```

Use sender ID `false` or `0` for a system message. Mailbox operations:

```php
$inbox = $this->aauth->list_pms(20, 0, $receiverId);
$outbox = $this->aauth->list_pms(20, 0, null, $senderId);
$message = $this->aauth->get_pm($messageId, $userId, true);
$unread = $this->aauth->count_unread_pms($receiverId);

$this->aauth->set_as_read_pm($messageId, $receiverId);
$this->aauth->delete_pm($messageId, $userId);
$this->aauth->cleanup_pms();
```

`cleanup_pms()` is intended for a scheduled task and uses
`pm_cleanup_max_age`.

## Errors and information

```php
if (!$this->aauth->login($identifier, $password)) {
    $errors = $this->aauth->get_errors_array();
    $this->aauth->print_errors('<br>');
}

$this->aauth->error('Operation failed.', true);
$this->aauth->info('Operation completed.', true);

$this->aauth->keep_errors(true);
$this->aauth->keep_infos(true);
$infos = $this->aauth->get_infos_array();
$this->aauth->print_infos('<br>');
$this->aauth->clear_errors();
$this->aauth->clear_infos();
```

The boolean argument of `error()` and `info()` also stores the message as
CodeIgniter flash data.

## Password hashing utilities

Most applications should let user creation, update, login, and reset manage
hashes. Low-level helpers remain public for legacy integrations:

```php
$hash = $this->aauth->hash_password($password, $userId);
$valid = $this->aauth->verify_password($password, $hash, $userId);
```

With `use_password_hash` enabled, successful login migrates valid legacy hashes
and rehashes native hashes when the configured algorithm changes.

## Configuration reference

Complete defaults and comments are in `application/config/aauth.php`.

### Accounts, access, and email

| Option | Purpose |
| --- | --- |
| `admin_group`, `default_group`, `public_group` | Special group names |
| `login_with_name` | Use username rather than email for login |
| `min`, `max` | Password-length policy |
| `additional_valid_chars` | Extra valid username characters |
| `no_permission` | Failed-authorization redirect |
| `email`, `name`, `email_config` | CodeIgniter email configuration |

### Recovery and verification

| Option | Purpose |
| --- | --- |
| `verification` | Require new-user email verification |
| `verification_link`, `verification_expiration` | Verification path and lifetime |
| `reset_password_link`, `reset_password_expiration` | Reset path and lifetime |
| `password_recovery_mode` | `link` or legacy `generated_password` |
| `use_password_hash` | Enable native PHP password hashes |
| `password_hash_algo`, `password_hash_options` | Native hash configuration |

### TOTP, attempts, and CAPTCHA

| Option | Purpose |
| --- | --- |
| `totp_active`, `totp_only_on_ip_change` | TOTP activation policy |
| `totp_reset_over_reset_password` | Remove TOTP during password reset |
| `totp_two_step_login_active` | Use a separate second-factor page; enabled by default |
| `totp_two_step_login_redirect` | Second-factor page path |
| `totp_issuer`, `totp_label`, `totp_qr_script` | Provisioning display and QR asset |
| `totp_recovery_code_count` | Number of recovery codes generated (1 to 20) |
| `login_throttling` | Enable application-level brute-force throttling |
| `login_throttle_identifier_limit`, `login_throttle_ip_limit` | Password limits per identifier and IP |
| `login_throttle_totp_identifier_limit`, `login_throttle_totp_ip_limit` | TOTP limits per account and IP |
| `max_login_attempt_time_period`, `login_throttle_lockout_time` | Counter window and lockout duration |
| `login_throttle_secret` | HMAC secret; falls back to CI `encryption_key` |
| `login_throttle_cleanup_probability`, `login_throttle_cleanup_after` | Expired-bucket cleanup policy |
| `remove_successful_attempts` | Clear successful account identifier counters |
| `ddos_protection`, `max_login_attempt` | Deprecated configuration aliases |
| `captcha_provider` | `false`, `recaptcha`, or `cap` |
| `recaptcha_login_attempts` | Attempts before CAPTCHA appears |
| `recaptcha_siteKey`, `recaptcha_secret` | reCAPTCHA credentials |
| `cap_instance_url`, `cap_site_key`, `cap_secret` | Cap credentials |
| `cap_widget_script_url`, `cap_widget_mode` | Cap frontend configuration |

### Storage and private messages

`db_profile` selects the CodeIgniter database profile. The `users`, `groups`,
`group_to_group`, `user_to_group`, `perms`, `perm_to_group`, `perm_to_user`,
`pms`, `user_variables`, `login_attempts`, and `totp_recovery_codes` options
customize table names.

`pm_encryption` enables the compatible fork's Encrypt integration;
`pm_cleanup_max_age` controls scheduled message cleanup.
