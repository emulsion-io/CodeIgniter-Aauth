<p align="center">
  <img src="https://cloud.githubusercontent.com/assets/2417212/8925689/add409ea-34be-11e5-8e50-845da8f5b1b0.png" height="260" alt="Aauth">
</p>

# Aauth 3

Aauth is an authentication and authorization library for CodeIgniter 3.x. It
provides user accounts, session authentication, email verification, password
recovery, TOTP, groups, permissions, user variables, private messages, login
attempt protection, and optional CAPTCHA integration.

Version 3 is a breaking security-focused release. It requires PHP 8.2 or newer,
targets CodeIgniter 3.x, and is compatible with the
[pocketarc CodeIgniter fork](https://github.com/pocketarc/codeigniter).
CodeIgniter 2.x is no longer supported.

## Main features

- Authentication through CodeIgniter sessions, with optional TOTP and
  single-use recovery codes.
- Native `password_hash()` support and transparent migration of legacy hashes.
- Expiring, single-use password-reset and email-verification links.
- Independent email-verification and account-ban states.
- User, group, subgroup, and permission management.
- Google reCAPTCHA or self-hosted [Cap](https://trycap.dev/guide/) CAPTCHA.
- Per-user variables, private messages, and independent per-IP/per-identifier
  login throttling.
- Hardened `utf8mb4` SQL schema with constraints and useful indexes.
- Minimal controller and views demonstrating the authentication flows.

## Requirements

- PHP 8.2 or newer.
- CodeIgniter 3.x or the compatible pocketarc fork.
- MySQL or MariaDB with InnoDB support.
- A configured CodeIgniter session driver.
- A configured CodeIgniter `encryption_key`, or a dedicated
  `login_throttle_secret` in Aauth configuration.
- A configured email service when verification or recovery emails are enabled.

## Fresh installation

1. Copy these files into the matching CodeIgniter application directories:

   - `application/config/aauth.php`
   - `application/libraries/Aauth.php`
   - `application/helpers/*`
   - `application/language/*/aauth_lang.php`

2. Import `sql/Aauth_v3.sql`. This is a fresh-installation schema and drops
   existing Aauth tables if they already exist.

3. Check `application/config/database.php`, then configure `db_profile`, table
   names, email, password policy, verification, TOTP, and CAPTCHA in
   `application/config/aauth.php`.

   Generate a dedicated throttling secret once with
   `bin2hex(random_bytes(32))`, store it outside version control, and assign it
   to `login_throttle_secret`. CodeIgniter's `encryption_key` is used as a
   fallback.

4. Load the library from a controller or through CodeIgniter autoloading:

   ```php
   $this->load->library('aauth');
   ```

5. For local TOTP QR rendering, publish `assets/js/qr-creator.min.js` at the path
   configured by `totp_qr_script`. Its MIT license is included in
   `assets/licenses/qr-creator-LICENSE.txt`.

Continue with the [categorized documentation](DOCUMENTATION.md).

## Demonstration controller and views

The optional `application/controllers/Account.php` controller demonstrates the
browser-facing authentication flow. Its deliberately minimal templates live in
`application/views/aauth_demo/`; CSS and page-specific JavaScript are inline so
the example needs no frontend build system.

With standard CodeIgniter routing, the demonstration URLs are:

| URL | Purpose |
| --- | --- |
| `/account` | Authenticated account page |
| `/account/login` | Login and optional CAPTCHA/TOTP |
| `POST /account/logout` | End the authenticated session |
| `/account/forgot_password` | Request a reset email |
| `/account/reset_password/{token}` | Choose a new password |
| `/account/verification/{user_id}/{token}` | Verify an email address |
| `/account/twofactor_verification` | Complete two-step TOTP login |
| `POST /account/cancel_twofactor` | Cancel a pending two-step login |
| `/account/totp_setup` | Enable or disable TOTP |

The views have separate responsibilities:

- `layout.php`: common layout, inline CSS, and optional QR script.
- `login.php`: credentials, TOTP field, and CAPTCHA output.
- `forgot_password.php`: recovery request form.
- `reset_password.php`: token validation and new-password form.
- `totp_verify.php`: second-factor or recovery-code form.
- `totp_setup.php`: local QR rendering, confirmation, printable recovery codes,
  and removal.
- `dashboard.php`: authenticated landing page.
- `message.php`: generic verification/result page.

The demo is an integration reference, not a drop-in production account area.
Adapt authorization, templates, CSRF policy, messages, redirects, and rate
limits to the application.

For a disposable local installation, see [demo/demo.md](demo/demo.md). The
PowerShell deployment script downloads and caches the latest release of the
compatible CodeIgniter fork, extracts it into `demo/test`, generates the
`development` configuration, and imports the v3 schema into a dedicated test
database. It also configures CodeIgniter Email for a local Mailpit SMTP server
on port 1025.

## Migrating from Aauth 2.x to 3.x

Version 3 contains intentional API and database breaks. Back up the database
and application first. Do not import `sql/Aauth_v3.sql` over an existing
installation because it recreates the Aauth tables.

Run the scripts from `sql/migrations/v2.x-3.x/` once in this order:

1. `01_remove_remember_me.sql`
2. `02_email_verification.sql`
3. `03_account_states.sql`
4. `04_schema_preflight.sql`
5. Resolve every row reported by the read-only preflight.
6. `05_schema_hardening.sql`
7. `06_login_throttling.sql`
8. `07_totp_recovery_codes.sql`

See the [migration guide](sql/migrations/v2.x-3.x/README.md) for details.

Application changes requiring attention:

- `login()` is now `login($identifier, $password, $totpCode = null)`; the old
  remember-me argument has been removed.
- Session lifetime is controlled only by CodeIgniter session configuration.
- The modern reset call is `reset_password($token, $newPassword)`. The legacy
  generated-password workflow requires the explicit
  `password_recovery_mode => generated_password` option.
- `banned` is replaced by `email_verified_at`, `banned_at`, and `ban_reason`.
- Existing reset and verification tokens become invalid because v3 stores only
  hashed tokens and enforces expiration.
- Resolve duplicates and orphaned relations reported by the SQL preflight
  before applying the new constraints.
- Migration 06 recreates the temporary login-attempt table and discards its
  old IP-only counters. Set `login_throttle_secret`, or configure
  CodeIgniter's `encryption_key`, before putting the application online.

Read the [v3.0.0 changelog](CHANGELOG.md#v300-20260902---breaking-release) for
the complete list of breaking changes.

## Documentation

- [Categorized usage and API examples](DOCUMENTATION.md)
- [Configuration defaults](application/config/aauth.php)
- [Database migration guide](sql/migrations/v2.x-3.x/README.md)
- [Version history](CHANGELOG.md)

## Credits and license

Aauth was created by Emre Akay and is distributed under the GNU Lesser General
Public License 3.0. Version 3 contributions include Simonet Fabrice
<fabrice@emulsion.io>.

See [LICENSE](LICENSE) for the full license text.
