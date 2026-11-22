<div align="center">
  <a href="README-TR.md">TR <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/TR.png" alt="TR" height="20" /></a>
  <a href="README.md"> | EN <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/US.png" alt="EN" height="20" /></a>
</div>

<div align="center">

# DNA SSL for WISECP

**Sell Domain Name API SSL certificates from WISECP 5.**

DV, OV, EV, wildcard and multi-domain certificates from your Domain Name API reseller
account: imported as products, ordered automatically, validated and delivered from the
client area.

![WISECP](https://img.shields.io/badge/WISECP-5-2C3E50?style=flat-square)

</div>

> Using WHMCS? See [whmcs-ssl-dna](https://github.com/domainreseller/whmcs-ssl-dna).

---

## 📦 Download

Get `DNASSL-WiseCP5.zip` from the [latest release](https://github.com/domainreseller/wisecp-ssl-dna/releases/latest)
and unpack it into the WISECP root; it creates `coremio/modules/Product/DNASSL`.

## ✨ What it does

Built on WISECP 5's own SSL framework, so it looks and works like the bundled SSL modules:

- **Catalog import:** every Domain Name API certificate becomes a product under *SSL
  Certificates*, priced at your reseller cost plus your margin
- **Automatic price update:** a daily job follows cost changes and re-applies the margin
- Order opened and configured automatically from the CSR, validation method and approver
  e-mail on the order form
- The standard SSL client dashboard: status, validation instructions (e-mail, HTTP file or
  DNS CNAME), certificate download, reissue
- Additional domains (SAN) on the order form, up to the package limit
- Renewal as a renewal order linked to the current certificate, at the renewal price
- Import of a certificate that already exists in your Domain Name API account, by domain or certificate ID
- Optional revoke on cancellation

## 📋 Requirements

- A **Domain Name API reseller account** with SSL products, and its **Reseller ID** and **API Key**
- WISECP 5
- PHP with `curl` and `json` (`intl` recommended for internationalised domains)

## 🚀 Installation

1. Unpack `DNASSL-WiseCP5.zip` into the WISECP root.
2. **Products → Product Group Modules → All Modules → DNA SSL**: enter the **Reseller ID**
   and **API Key**, tick **Test Mode** for the test environment (use the test API key
   then), **Test Connection**, save.
3. **Catalog** tab: set your margin and **Import Catalog**. Turn on **Automatic Price
   Update** to follow cost changes daily.
4. Optionally tick **Revoke on Cancellation** in the settings.

## 🧩 Things worth knowing

- **A certificate is identified by its certificate ID.** A domain can have more than one
  certificate at Domain Name API (a renewal next to the one it renews), so the module
  stores the ID of the service's certificate. A service that only knows its domain is
  matched to the newest current certificate on it; the admin can see and correct the
  domain on the service page.
- **Renewal opens 30 days before the subscription ends.** A renewal attempted earlier than
  that is refused by Domain Name API and nothing is charged. Unused days of the old term
  are not carried over.
- **Additional domains are taken with the order.** The API cannot add a SAN to an existing
  certificate, so the dashboard's add-SAN action explains that instead.
- **Validation e-mails cannot be re-sent on request,** and the method cannot be changed on
  an open order. A reissue restarts validation.
- **Validation methods** are e-mail, an HTTP file or a DNS CNAME record. The API has no
  HTTPS-file or TXT validation; an order that asks for HTTPS uses the HTTP file method.
- **Web server type** is *Apache* (also covers nginx, LiteSpeed, cPanel, Plesk) or
  *Microsoft IIS*: the two values the API accepts.
- **The contact phone country code can have at most two digits** (API limit). Clients with
  a three-digit code (e.g. +994, +380) get the API's validation message.
- **Approver e-mails** are limited to a mailbox on the certificate domain.
- **Prices** come from the price group of your reseller account (Reseller, Premium, Platinum, VIP).
- **Some packages cannot be revoked** (the API answers "Package is not supported");
  cancellation still completes and the attempt is written to the module log.

## 🔍 Logs

*Tools → Logs → Module Logs*. The API key and private keys are replaced by `***` before
anything is logged.

## 🛠 Development

```
coremio/modules/Product/DNASSL/             the module
coremio/modules/Product/DNASSL/lib/DnaSsl/  Domain Name API SSL client (PHP 7.4+)
tools/build-release-zip.sh [ref|WORKTREE] [out-dir]
tests/                                      smoke tests with a fake gateway
```

```bash
php tests/smoke-shared.php
```

`tests/smoke-wisecp5.php` runs inside a WISECP 5 test installation (see the file header).
`lib/DnaSsl` is the same client as in [whmcs-ssl-dna](https://github.com/domainreseller/whmcs-ssl-dna);
keep the two copies identical.

## 📄 Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

<div align="center">

**DNA SSL for WISECP** · Domain Name API SSL certificates

[domainnameapi.com](https://www.domainnameapi.com) · [Reseller panel](https://dm.domainnameapi.com)

</div>
