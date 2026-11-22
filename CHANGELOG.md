# Changelog

## Unreleased

Required update. Domain Name API changed its SSL API on 2026-11-20: a certificate is now
addressed by its certificate ID instead of its domain name. Versions up to 1.0.1 still
send the domain name, so they can no longer configure a new order, show its status,
deliver, reissue or revoke a certificate.

- Every call for an existing certificate sends its Domain Name API certificate ID. The ID
  has been stored with each order since 1.0.0, so services ordered through the module
  keep working without any migration. A service that only knows its domain (imported
  with an earlier version, or linked by typing the domain on the service page) is
  matched once to the newest current certificate on that domain.
- Import accepts a certificate ID as well as a domain, for domains with more than one
  certificate, and stores the ID.
- Renewal uses the gateway's new renew call: a renewal order linked to the current
  certificate, charged at the renewal price, instead of a second purchase.
  - A certificate that is cancelled or no longer exists at Domain Name API gets a new
    order, as before.
  - A renewal already opened for the certificate (for example from the reseller panel) is
    picked up instead of being charged again.
  - Domain Name API opens renewal 30 days before the subscription ends. Earlier than that
    nothing is bought and the gateway's message is reported.

## 1.0.1 — 2026-10-24

- WISECP Marketplace manifest (product 207), so marketplace installs and updates are recognised.

## 1.0.0 — 2026-10-24

First release.

- WISECP 5 product module `DNASSL` for Domain Name API SSL certificates, built on the
  core SSL framework.
- Catalogue import as products and daily cost sync, priced from the reseller's price
  group; duplicate product codes collapsed.
- Order opened and configured from the order form (CSR, validation method, approver
  e-mail, additional domains up to the package limit).
- Standard SSL client dashboard with the exact validation file or CNAME record, derived
  from the CSR; certificate download and reissue.
- Renewal as a new order, import of existing certificates by domain, optional revoke on
  cancellation (packages that cannot be revoked do not block it).
- API key and private keys masked in every log line; TLS verified on every request.
- Requests Cloudflare could not deliver to the gateway (521/522/523/525/526) are retried.
- Catalogue tab shows prices in the catalogue currency and explains an empty catalogue.
- SSL dashboard shows the real key size and signature algorithm.
