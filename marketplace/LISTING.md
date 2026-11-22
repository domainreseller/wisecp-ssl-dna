# WISECP Marketplace listing — DNA SSL for WISECP

Everything for https://wisecp.com/tr/developer/products/new.

| Field | Value |
|---|---|
| Name | DNA SSL - Domain Name API SSL Certificates |
| Category | Provizyon (SSL) |
| Price | Ücretsiz / Free |
| License check | None |
| Version | 1.0.1 |
| Minimum WISECP version | 5.0-beta.3 |
| Package | `DNASSL-WiseCP5-v1.0.1.zip` from the V1.0.1 release |
| Icon | `icon-512.png` |

> Draft: https://wisecp.com/tr/developer/products/207 — manifest.json (id 207) ships since V1.0.1;
> the uploaded package is `DNASSL-WiseCP5-v1.0.1.zip`, minimum WISECP 5.0-beta.3.

## Lede / Kısa açıklama

**EN:** Sell Domain Name API SSL certificates in WISECP 5: catalogue import, daily price sync, automatic ordering and the standard SSL client dashboard.

**TR:** Domain Name API SSL sertifikalarını WISECP 5'te satın: katalog aktarımı, günlük fiyat senkronu, otomatik sipariş ve standart SSL müşteri paneli.

## Description / Açıklama

**EN**

DNA SSL is the official WISECP 5 module for selling the SSL certificates of your Domain Name API reseller account (Sectigo, Comodo, GeoTrust, RapidSSL, Thawte, GlobalSign, AlphaSSL, PrimeSSL). It is built on WISECP's own SSL framework, so it looks and works like the bundled SSL modules.

- One-click catalogue import: every certificate becomes a product under SSL Certificates, priced at your reseller cost plus your margin, in the catalogue currency
- Automatic price update: a daily job follows cost changes and re-applies your margin
- Orders open and configure themselves from the CSR, validation method and approver e-mail on the order form
- Standard SSL client dashboard: status, e-mail / HTTP file / DNS (CNAME) validation instructions, certificate download, reissue, key size
- Additional domains (SAN) on the order form up to the package limit
- Import certificates that already exist in your Domain Name API account, by domain or certificate ID
- Test Mode for the Domain Name API test environment; optional revoke on cancellation
- English and Turkish language files

**TR**

DNA SSL, Domain Name API bayi hesabınızdaki SSL sertifikalarını (Sectigo, Comodo, GeoTrust, RapidSSL, Thawte, GlobalSign, AlphaSSL, PrimeSSL) satmanız için resmi WISECP 5 modülüdür. WISECP'nin kendi SSL altyapısı üzerine kuruludur; hazır gelen SSL modülleri gibi görünür ve çalışır.

- Tek tıkla katalog aktarımı: her sertifika SSL Sertifikaları altında, bayi maliyetiniz + kâr marjınızla, katalog para biriminde ürün olur
- Otomatik fiyat güncelleme: günlük görev maliyet değişikliklerini izler ve marjı yeniden uygular
- Siparişler, sipariş formundaki CSR, doğrulama yöntemi ve onay e-postasıyla otomatik açılır ve yapılandırılır
- Standart SSL müşteri paneli: durum, e-posta / HTTP dosyası / DNS (CNAME) doğrulama talimatları, sertifika indirme, yeniden düzenleme, anahtar boyutu
- Sipariş formunda paket limitine kadar ek alan adı (SAN)
- Domain Name API hesabınızda zaten bulunan sertifikaları alan adıyla içe aktarma
- Domain Name API test ortamı için Test Modu; iptalde isteğe bağlı sertifika iptali
- İngilizce ve Türkçe dil dosyaları

## Requirements / Gereksinimler

**EN:** A Domain Name API reseller account with SSL products (Reseller ID and API Key); WISECP 5; PHP curl and json.

**TR:** SSL ürünleri tanımlı bir Domain Name API bayi hesabı (Bayi ID ve API Anahtarı); WISECP 5; PHP curl ve json eklentileri.

## Screenshots (`screenshots/`, no sensitive data — Reseller ID masked)

| File | Content |
|---|---|
| `1-catalog.png` | Catalogue tab: import, margin, automatic price update, certificate list with cost and sale prices |
| `2-settings.png` | Settings tab: Reseller ID (masked), API Key, Test Mode, revoke on cancellation |
| `3-ssl-dashboard.png` | SSL dashboard of a certificate: days left, DV type, key size, validation status, quick actions |
