# M1 — Auth, Roles & Sessions

## File trace

| File | Layer | Change | Purpose |
|---|---|---|---|
| `database/migrations/003_auth_sessions.sql` | DB | New | Rebuild the role ENUM to exactly eight values; add account lock/password-change fields; create persisted sessions and append-only security logs. |
| `app/Services/AuthService.php` | Backend | New | Throttle, verify, count consecutive failures, lock accounts, create/logout sessions, change passwords, and invalidate sessions on account lock. |
| `app/Repositories/SessionRepository.php` | Backend | New | Store session ID hashes, validate/touch sessions, list/invalidate sessions, and sweep expired rows. |
| `app/Repositories/SecurityLogRepository.php` | Backend | New | Append security events with hashed email identity and no credential values. |
| `app/Repositories/StaffUserRepository.php` | Backend | Modified | Preserve seed/login call sites and add account queries and admin updates for the migrated user schema. |
| `app/Security/StaffAuth.php` | Backend | Modified | Preserve `user()`, `login()`, and `logout()` as a compatibility facade over `AuthService` and persisted sessions. |
| `app/Http/AuthMiddleware.php` | Backend | New | Authenticate, enforce the forced-password-change gate before role checks, and return role/identity failures. |
| `app/Controllers/AuthController.php` | Backend | Modified | Use the new login service and serve forced password change. |
| `app/Controllers/Admin/UserController.php` | Backend | New | Create users, assign roles, deactivate/reactivate, unlock, and issue temporary password resets. |
| `app/Controllers/Admin/SessionController.php` | Backend | New | Show and invalidate a user's sessions. |
| `app/Controllers/StaffNotificationController.php` | Backend | Modified | Apply the role and forced-password gates to existing staff history routes. |
| `app/Controllers/StaffHomeController.php` | Backend | New | Provide an authenticated role landing page so all eight roles have a valid post-login destination. |
| `app/Http/Router.php` | Backend | Used as-is | The existing route registry dispatches the M1 routes wired in the front controller. |
| `public/index.php` | Backend | Modified | Construct M1 services/guards and wire login, password, user, and session routes. |
| `app/Views/auth/login.php` | Frontend | New | Entry view for login, including the existing `staff/login.php` form partial. |
| `app/Views/staff/login.php` | Frontend | Modified | Retain the existing form as the partial included by the M1 auth view and load the auth form script. |
| `app/Views/auth/change-password.php` | Frontend | New | Require the current temporary password and accept a new password. |
| `app/Views/admin/users.php` | Frontend | New | User/role/access management with one-time temporary credential display. |
| `app/Views/admin/user-form.php` | Frontend | New | Edit email and role through a CSRF-protected form. |
| `app/Views/admin/sessions.php` | Frontend | New | List session metadata and provide an invalidate action. |
| `app/Views/staff/home.php` | Frontend | New | Show the authenticated role and currently available staff tools. |
| `public/assets/js/auth.js` | Frontend | New | Prevent duplicate auth-form submissions. |
| `public/assets/js/notifications.js` | Frontend | Modified | Redirect stale staff sessions with a forced-change gate to the password-change page. |
| `public/assets/css/app.css` | Frontend | Modified | Add basic auth/admin form and table styles. |
| `bin/sessions-sweep.php` | Ops | New | Invalidate expired persisted session rows for scheduled cleanup. |
| `.env.example` | Setup | Modified | Document the five-failure threshold and 12-hour default session lifetime. |
| `README.md` | Setup | Modified | Document migration, roles, forced changes, lockout, session schedule, and credential handling. |
| `docs/FEATURE_E.md` | Documentation | Modified | Mark the first-login password-change backlog item as implemented by M1. |
| `docs/FEATURE_M1.md` | Documentation | New | Define the file trace, role/session contracts, operational steps, and round trips. |

## Role and account contract

The final `users.role` ENUM contains exactly `system_admin`, `fleet_manager`, `front_desk`, `driver_coordinator`, `mechanic`, `finance_staff`, `auditor`, and `support_staff`. Migration 001's existing `system_admin` and `fleet_manager` values map to themselves; migration 003 rebuilds the ENUM without extra legacy values. Existing accounts are flagged to change their password after first login.

Five consecutive incorrect passwords lock the account. `failed_login_count` and `locked_at` are on the user row and the failed-login update runs while that row is locked in the authentication transaction. A successful login clears a sub-threshold count. A locked account stays locked until an administrator explicitly unlocks it. The lock operation invalidates every persisted session in the same transaction. Locked and ordinary credential failures have the same response body and status; only admin screens show the lock state.

IP/email request throttling is an independent pre-authentication gate backed by the existing Feature E `rate_limits` table: 20/IP/minute and 5/normalized-email/minute. A throttled request is appended as `login_throttled` but does not increment the per-account counter. An allowed request with bad credentials increments the account counter if the account exists; unknown and inactive accounts receive the same outward failure. Authentication and account events are written to append-only `security_logs`.

New accounts receive a random temporary password shown once in the successful administrator response and communicated out of band. Only a password hash is stored. Temporary passwords, submitted passwords, and request bodies are never included in security, application, error, request, or response logs. The application does not log request or response bodies, and deployments must not enable web-server body capture. The password-change middleware gate authenticates first, then allows only the change-password flow and logout until the flag clears; normal role checks run afterward. Admin password resets invalidate the target's sessions and force the next login to change the generated temporary password.

## Session contract

The browser retains the PHP session cookie. MySQL stores only SHA-256 of its session ID, the user, creation/last-seen/expiry timestamps, IP and user agent, and optional invalidation time. Session expiry is fixed at `AUTH_SESSION_TTL_SECONDS` (12 hours by default). Each protected request resolves the DB row and touches `last_seen_at`. Deactivation, role changes, admin invalidation, password reset, and account lock invalidate affected sessions; the next request rejects an invalidated session. The active PHP session ID is rotated after login.

`sessions` is operational state and may be updated by invalidation/expiry. `security_logs` is append-only and protected by DB triggers. It stores separate nullable `actor_user_id` and `subject_user_id` references; authentication events set the subject, and administrator actions set both actor and target subject. `email_hash` identifies the normalized login/target email without storing it in plaintext.

## End-to-end round trips

1. Login form sends a CSRF-protected POST. RateLimiter checks IP and normalized-email windows. AuthService locks the user row, checks active/deleted/locked state and password, logs the outcome, and either increments the failure counter or rotates the PHP session ID and inserts its hash. Account lock and all-session invalidation commit together. A forced-change account is redirected to the password page before any role-protected page.
2. The password-change page requires the current credential, CSRF, a matching new-password confirmation, and at least 14 characters. The new hash clears `must_change_password`; other active sessions are invalidated while the current session remains valid.
3. A system administrator creates a user with one of the eight roles. The server stores the generated temporary password hash, marks the user for forced change, and displays the raw value once. The user must change it before the role-guarded console is accessible.
4. A system administrator edits email/role, deactivates/reactivates, unlocks, or resets a password. Role/deactivation/reset operations invalidate sessions as applicable; every action appends a security log. No user row is physically deleted.
5. A system administrator views active sessions and invalidates a selected row. That browser's next protected request is rejected because its DB session is no longer active.
6. The scheduled session sweeper invalidates expired rows; it does not delete sessions or audit history.

## Run and acceptance checklist

Apply `003_auth_sessions.sql` after migrations 001 and 002, then seed as in the README. Schedule `bin/sessions-sweep.php` every five minutes. For the M11 acceptance run, create a test account for each of the eight roles through the admin UI, verify login and permitted/denied screens, and invalidate each role's session; `bin/seed.php` intentionally creates only the first system administrator.

The current development environment has no PHP/MySQL runtime, so migration and HTTP behavior require local runtime verification. Static review is the available check here.
