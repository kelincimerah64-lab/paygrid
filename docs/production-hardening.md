# PayGrid Production Hardening

## AWS Edge / WAF

- Put CloudFront or ALB in front of the app.
- Enable AWS WAF managed rules: CommonRuleSet, KnownBadInputs, SQLi, Linux, PHP.
- Rate-limit login and public topup paths at edge:
  - `/login`: 5 requests/minute per IP.
  - `/topup/*/submit`: 20 requests/minute per IP.
  - Dashboard write routes: 60 requests/minute per authenticated source/IP.
- Do not allow outbound tests against Hilogate create merchant group unless explicitly approved.

## App Security

- App sets security headers globally: CSP, frame deny, nosniff, referrer policy, permissions policy, COOP/CORP, HSTS on HTTPS.
- Passwords are never displayed permanently in tables.
- New/reset passwords are shown once in flash messages only.
- Use HTTPS in production so session cookies and HSTS are effective.

## Database Backup

- For production RDS, use automated RDS snapshots and point-in-time recovery.
- Keep at least 7 daily snapshots and 4 weekly snapshots.
- Verify restore regularly in staging.
- Local SQLite backup command exists for local/dev only: `php artisan paygrid:backup-db`.

## Load Test

- Run load tests only against local/staging URLs that do not create external gateway resources.
- Safe paths to test: `/login`, `/superadmin`, `/cs-pusat`, merchant dashboard GET routes, topup status GET routes.
- Do not load-test merchant group creation against Hilogate.
- Recommended staged target before AWS cutover: p95 dashboard GET under 500ms with realistic DB volume and cache warmed.
