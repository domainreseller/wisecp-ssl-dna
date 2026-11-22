<div align="center">
  <a href="README-TR.md">TR <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/TR.png" alt="TR" height="20" /></a>
  <a href="README.md"> | EN <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/US.png" alt="EN" height="20" /></a>
</div>

<div align="center">

# WISECP için DNA SSL

**Domain Name API SSL sertifikalarını WISECP 5 üzerinden satın.**

Domain Name API bayi hesabınızdaki DV, OV, EV, wildcard ve çoklu alan adı sertifikaları:
ürün olarak aktarılır, otomatik sipariş edilir, müşteri panelinden doğrulanır ve teslim edilir.

![WISECP](https://img.shields.io/badge/WISECP-5-2C3E50?style=flat-square)

</div>

> WHMCS mi kullanıyorsunuz? [whmcs-ssl-dna](https://github.com/domainreseller/whmcs-ssl-dna) reposuna bakın.

---

## 📦 İndirme

[Son sürümden](https://github.com/domainreseller/wisecp-ssl-dna/releases/latest) `DNASSL-WiseCP5.zip`
dosyasını indirip WISECP kök dizinine açın; `coremio/modules/Product/DNASSL` klasörü oluşur.

## ✨ Neler yapar

WISECP 5'in kendi SSL altyapısı üzerine kuruludur; hazır gelen SSL modülleri gibi görünür ve çalışır:

- **Katalog aktarımı:** her Domain Name API sertifikası *SSL Sertifikaları* altında, bayi
  maliyetiniz + kâr marjınızla ürün olur
- **Otomatik fiyat güncelleme:** günlük görev maliyet değişikliklerini izler, marjı yeniden uygular
- Sipariş, sipariş formundaki CSR, doğrulama yöntemi ve onay e-postasıyla otomatik açılır ve yapılandırılır
- Standart SSL müşteri paneli: durum, doğrulama talimatları (e-posta, HTTP dosyası veya DNS
  CNAME), sertifika indirme, yeniden düzenleme
- Sipariş formunda paket limitine kadar ek alan adı (SAN)
- Yenileme, mevcut sertifikaya bağlı yenileme siparişi olarak yenileme fiyatıyla açılır
- Domain Name API hesabınızda zaten bulunan bir sertifikayı alan adı veya sertifika ID'siyle içe aktarma
- İptalde isteğe bağlı sertifika iptali (revoke)

## 📋 Gereksinimler

- SSL ürünleri tanımlı bir **Domain Name API bayi hesabı**, **Bayi ID** ve **API Anahtarı**
- WISECP 5
- `curl` ve `json` eklentili PHP (Türkçe karakterli alan adları için `intl` önerilir)

## 🚀 Kurulum

1. `DNASSL-WiseCP5.zip` dosyasını WISECP kök dizinine açın.
2. **Ürünler → Ürün Grubu Modülleri → Tüm Modüller → DNA SSL**: **Bayi ID** ve **API
   Anahtarı**nı girin, test ortamı için **Test Modu**'nu işaretleyin (bu durumda test API
   anahtarını kullanın), **Bağlantıyı Test Et**, kaydedin.
3. **Katalog** sekmesi: kâr marjınızı girip **Kataloğu İçe Aktar**'a basın. Maliyet
   değişikliklerini her gün izlemek için **Otomatik Fiyat Güncelleme**'yi açın.
4. İsterseniz ayarlarda **İptalde Sertifikayı Geri Al**'ı işaretleyin.

## 🧩 Bilinmesi gerekenler

- **Sertifika, sertifika ID'siyle tanınır.** Domain Name API'de bir alan adının birden fazla
  sertifikası olabilir (yenileme ile yenilenen sertifika yan yana durur); modül hizmetin
  sertifika ID'sini saklar. Yalnızca alan adını bilen bir hizmet, o alan adındaki en yeni
  geçerli sertifikayla eşleştirilir; yönetici alan adını hizmet sayfasında görüp
  düzeltebilir.
- **Yenileme, abonelik bitimine 30 gün kala açılır.** Daha erken denenen bir yenilemeyi
  Domain Name API reddeder ve ücret kesilmez. Eski dönemin kullanılmayan günleri yeni
  döneme aktarılmaz.
- **Ek alan adları siparişle birlikte alınır.** API mevcut bir sertifikaya SAN ekleyemez;
  panelin SAN ekleme işlemi bunu açıklayan bir mesaj gösterir.
- **Doğrulama e-postası istek üzerine yeniden gönderilemez,** açık bir siparişte yöntem de
  değiştirilemez. Yeniden düzenleme (reissue) doğrulamayı baştan başlatır.
- **Doğrulama yöntemleri** e-posta, HTTP dosyası veya DNS CNAME kaydıdır. API'de HTTPS
  dosyası ya da TXT doğrulaması yoktur; HTTPS seçilen sipariş HTTP dosyası yöntemini kullanır.
- **Web sunucusu tipi** *Apache* (nginx, LiteSpeed, cPanel, Plesk dahil) veya
  *Microsoft IIS* olabilir: API'nin kabul ettiği iki değer bunlar.
- **İletişim telefonunun ülke kodu en fazla iki haneli olabilir** (API sınırı). Üç haneli
  kodlu müşteriler (ör. +994, +380) API'nin doğrulama mesajını görür.
- **Onay e-postası** yalnızca sertifika alan adının kendi posta kutularından biri olabilir.
- **Fiyatlar** bayi hesabınızın fiyat grubundan (Reseller, Premium, Platinum, VIP) alınır.
- **Bazı paketler iptal edilemez** (API "Package is not supported" der); iptal yine
  tamamlanır ve deneme modül loguna yazılır.

## 🔍 Loglar

*Araçlar → Loglar → Modül Logları*. API anahtarı ve özel anahtarlar log'a yazılmadan önce
`***` ile değiştirilir.

## 🛠 Geliştirme

```
coremio/modules/Product/DNASSL/             modül
coremio/modules/Product/DNASSL/lib/DnaSsl/  Domain Name API SSL istemcisi (PHP 7.4+)
tools/build-release-zip.sh [ref|WORKTREE] [çıktı-dizini]
tests/                                      sahte API ile smoke testleri
```

```bash
php tests/smoke-shared.php
```

`tests/smoke-wisecp5.php` bir WISECP 5 test kurulumunda çalışır (dosya başlığına bakın).
`lib/DnaSsl`, [whmcs-ssl-dna](https://github.com/domainreseller/whmcs-ssl-dna) içindekiyle aynı
istemcidir; iki kopya birebir aynı tutulmalıdır.

## 📄 Değişiklik kaydı

[CHANGELOG.md](CHANGELOG.md) dosyasına bakın.

---

<div align="center">

**WISECP için DNA SSL** · Domain Name API SSL sertifikaları

[domainnameapi.com](https://www.domainnameapi.com) · [Bayi paneli](https://dm.domainnameapi.com)

</div>
