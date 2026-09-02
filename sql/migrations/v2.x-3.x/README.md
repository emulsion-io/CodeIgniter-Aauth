# Aauth 2.x to 3.x database migrations

These scripts upgrade an existing Aauth 2.x database to the schema expected by
Aauth 3.x. They are not needed for a fresh installation created from
`sql/Aauth_v3.sql`.

Back up the database before starting. Run each numbered migration only once and
in the order below. The table names assume the default Aauth configuration; if
the application uses custom table names, adapt the scripts before executing
them.

## Migration order

1. `01_remove_remember_me.sql`
   Removes the obsolete `remember_time` and `remember_exp` columns. Aauth 3.x
   relies exclusively on the CodeIgniter session.

2. `02_email_verification.sql`
   Adds `verification_exp`, which stores the expiration date of temporary email
   verification links.

3. `03_account_states.sql`
   Replaces the overloaded `banned` flag with the independent
   `email_verified_at`, `banned_at`, and `ban_reason` fields. Existing rows with
   a verification token remain unverified; other legacy banned rows remain
   banned.

4. `04_schema_preflight.sql`
   Performs read-only checks for duplicate values, oversized legacy data, and
   orphaned relations. Every query must return zero rows before continuing.
   Correct reported data manually; this script intentionally deletes nothing.

5. `05_schema_hardening.sql`
   Adds unique constraints, foreign keys with cascading cleanup, and indexes
   matching Aauth queries. It also converts the tables to `utf8mb4`, supports
   254-character email addresses and 45-character IP addresses, and replaces
   undersized numeric flags where appropriate.

6. `06_login_throttling.sql`
   Recreates the temporary login-attempt table with independent IP and
   identifier buckets. Bucket keys are HMAC digests, so email addresses,
   usernames, and IP addresses are not stored in clear text. Existing attempt
   counters are intentionally discarded because they are short-lived state.

The hardening migration deliberately does not add a foreign key on
`aauth_pms.sender_id`, because Aauth uses sender ID `0` for system messages.

After migration 06, configure `login_throttle_secret` or ensure CodeIgniter's
`encryption_key` is set. Changing that secret invalidates existing temporary
throttle buckets, but does not affect users or password hashes.
