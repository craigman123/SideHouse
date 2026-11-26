# Privileged MFA lost-device and break-glass procedure

This procedure applies only to admin, owner, and staff accounts. It does not create an application bypass or authorize an admin to change customer MFA.

## Lost device with another admin available

1. Use an unused recovery code at sign-in if one remains.
2. After signing in, enroll and verify a replacement authenticator before discarding the old factor. Regenerate recovery codes with the current TOTP code and store the new codes securely.
3. If no recovery code or working device remains, ask a different real admin to require re-enrollment for an admin or staff account. The affected account is notified, its sessions are invalidated, and protected pages remain unavailable until a new TOTP factor is verified.
4. An owner account cannot be disabled or forced to re-enroll by one admin. One admin creates a request and a second real admin approves it with a fresh TOTP code. The requester cannot approve their own request.

Temporary MFA disablement is a separate, time-limited login bypass of up to 24 hours. It retains the authenticator, recovery codes, and existing sessions; expiration restores the normal challenge automatically. Re-enabling sooner invalidates existing sessions so the saved TOTP is required again. Do not use temporary disablement to recover a lost device; use the forced re-enrollment process above.

## Only admin or all admins locked out

There is no self-service or application-level bypass for a locked-out admin. If no real admin can perform an authorized action, designated infrastructure operators may carry out a narrowly scoped recovery under two-person control:

1. Open an incident record with the affected account, reason, time, and the names of two distinct authorized operators. Verify the account holder using the organization's established identity-verification process; do not rely on access to the locked account's email alone.
2. Have both operators independently approve the change. Use individually attributable, short-lived infrastructure credentials and the approved production database access procedure. Do not share credentials or export MFA secrets or recovery codes.
3. Take or verify a recoverable database snapshot. Read and record the affected row's role, MFA state, and access version before changing anything.
4. For a privileged account, set `mfa_reenroll_required` to true and clear `mfa_admin_disabled_until`. Keep any existing factor and recovery-code values intact until the user verifies a replacement factor. Increment `access_version` and rotate `remember_token` to invalidate existing sessions. If the account already has no usable factor, leave it factor-free only for the restricted enrollment path; do not grant access to protected pages.
5. Record the approved fields, before-and-after values, operator identities, incident reference, and database change result in the incident record and the organization's infrastructure audit log. Verify the row again after the update.
6. Require the account holder to sign in through the production login page and complete TOTP enrollment. Confirm they cannot access protected pages before enrollment, then verify access and issue new recovery codes from the profile.
7. Close the incident only after the account holder confirms access and the second operator verifies that the incident record and audit evidence are complete.

Do not use this procedure to disable MFA for a customer, alter a customer's allowed method, change password or authorization rules, or create a reusable support login. If identity cannot be verified or two distinct operators are unavailable, stop and escalate through the organization's incident-response process.
