# PartCore — B2B Otomotiv Yedek Parça Platformu

> **Showcase / vitrin reposu.** Otomotiv yedek parça toptancıları için geliştirdiğim
> **B2B sipariş + cari + stok + satınalma + fiyatlandırma** platformunun mimarisini ve
> seçilmiş kod örneklerini sergiler. Çalışan ürünün tamamı değildir; amaç çözülen
> mühendislik problemlerini ve kod kalitesini göstermektir. Kaynak kod özeldir
> (bkz. [LICENSE](LICENSE)).

**Stack:** PHP 8.3 · Laravel 11 · Livewire 4 · MySQL 8 · Meilisearch · Redis · Laravel Horizon · Soketi (WebSocket) · Docker

---

## TL;DR (for reviewers)

Geleneksel bir masaüstü ERP'nin modern, web tabanlı yeni nesli. Tek geliştirici olarak
**modüler (DDD esinli) bir Laravel mimarisi** ile kurdum: **29 bağımsız modül**
(Catalog, Pricing, Order, Purchasing, Finance, Banking, Stock, Search, Invoicing,
CustomerPortal, Ocr, Fleet…), her biri kendi Action / Service / DTO / Exception
katmanlarıyla.

Bu bir "tutorial projesi" değil, **sahada çalışan bir üründür**: iki canlı kurulum,
şifreli otomatik yedekleme, geri yükleme provası, sağlık izleme ve **1342 otomatik
test** ile ayakta duruyor.

| Ne kanıtlıyor | Nerede |
|---------------|--------|
| Finansal hassasiyet (bcmath) + kredi-risk maruziyeti hesabı | [CreditLimitChecker](code-highlights/credit-limit-checker.php) |
| Meilisearch tam-metin arama + highlight'lı autocomplete | [PartSearchService](code-highlights/part-search-meilisearch.php) |
| Belirsizlik altında karar: banka ekstresi → müşteri eşleştirme (güven skoru + insana bırakma) | [CustomerMatcher](code-highlights/bank-statement-matcher.php) |
| Modüler mimari, dağıtım modeli, yetki ayrıştırması | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |

**Ölçek:** 29 modül · 120 migration · 113 Livewire bileşeni · ~900 PHP dosyası ·
1342 test (PHPStan + Pint temiz).

---

## Öne çıkan özellikler

**Satış tarafı**
- **Akıllı katalog & arama** — Meilisearch + Livewire ile sayfa yenilenmeden anlık arama;
  OEM kodu, parça adı, çapraz referans üzerinden; yazım hatası toleransı; araç bazlı filtre
  (Marka → Model → Motor → Yıl). 100 bin parçada ölçülen arama süresi ~120 ms.
- **B2B fiyatlandırma** — Müşteri segmentine göre çok katmanlı fiyatlandırma ve indirim
  motoru; toplu (yüzdesel) fiyat güncelleme; tüm para hesapları `bcmath` ile (float yok).
- **Sipariş & sepet** — Canlı slide-over sepet, stok rezervasyonu (TTL'li), sipariş durum
  makinesi, elden teslim / kargo ayrımı, iade ve **ürün değişim** akışları.
- **Cari & finans** — Cari mizan, yaşlandırma, **dinamik kredi limiti** (mevcut risk =
  açık siparişler + bakiye), limit aşımında onay akışı; müşteri kârlılık raporu; ekstre PDF.
- **Bayi self-servis portalı** — Müşteri kendi siparişlerini, cari ekstresini görür,
  sipariş verir, stokta olmayan parça için **talep/teklif** akışını yürütür.

**Satınalma tarafı**
- **Tedarikçi siparişi → mal kabul → alış faturası** zinciri; kısmi mal kabul, kalan
  miktar takibi, otomatik durum roll-up.
- **Gelen e-Fatura kutusu** — UBL-TR belgelerini ayrıştırır, tedarikçiyi VKN'den çözer,
  kalemleri katalogla eşleştirir, taslak alış faturasına dönüştürür.
- **Tedarikçi stok beslemesi** — Toptancı listeleri ayrı bir alanda tutulur; **kendi
  depo sayımına asla dokunmaz** (bilinçli sınır).

**Finans & entegrasyon**
- **Banka ekstresi mutabakatı** — MT940 / CSV içe aktarma, IBAN / müşteri kodu / VKN /
  bulanık unvan eşleştirmesi, güven skoru eşiğinin altında insana bırakma.
- **E-Fatura / E-Arşiv (GİB)** — UBL-TR 1.2 XML üretimi; teslimde otomatik kesim.
- **AI/OCR fatura okuma** — Kağıt/PDF fatura → kalem kalem ayrıştır → stok & cari girişi.
- **Legacy köprü** — Eski masaüstü ERP ile delta senkronizasyon (kademeli geçiş için).
- **Bildirimler** — E-posta, SMS, WhatsApp (Meta Cloud API) ve WebSocket ile anlık
  bildirim merkezi.

**Platform**
- **Çok kurulumlu dağıtım** — Her müşteri kendi veritabanı ve kendi konteynerleriyle;
  izolasyon sorgu filtresiyle değil **yapısal** olarak sağlanır ([neden?](docs/ARCHITECTURE.md#dağıtım-modeli-bir-müşteri--bir-kurulum)).
- **Filo yönetim paneli** — Tüm kurulumların sağlığı, tek tuşla yeni müşteri kurulumu,
  güncelleme ve yedekleme; **yetki ayrıştırmalı** tasarım
  ([neden?](docs/ARCHITECTURE.md#yetki-ayrıştırması-yönetim-paneli)).
- **Veri aktarım sihirbazı** — Müşterinin mevcut Excel/CSV verisini (ürün, stok, fiyat,
  cari) doğrulayarak aktarır.
- **Roller & yetki** — Spatie Permission ile granular yetki; sistem + bayi rolleri; 2FA.
- **PWA** + KVKK/yasal metinler + açık rıza kaydı.
- **Üretim işletmeciliği** — Şifreli otomatik yedek, geri yükleme provası, sağlık
  kontrolü, deploy öncesi ortam denetimi (`prod:check`), Sentry ile hata izleme.

---

## Mimari

Kısa özet → [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

---

## Notlar

- Katalogdaki marka adları (Bosch, Mann vb.) bir yedek parça bayisinin meşru sattığı
  ürün markalarıdır; nominatif/tanımlayıcı kullanımdır.
- Kaynak kodun tamamı özeldir; tam koda erişim **talep üzerine** (ör. teknik mülakat)
  sağlanabilir.

---

## İletişim

**Yavuz Selim Canpolat** · [LinkedIn](https://www.linkedin.com/in/yavuz-selim-canpolat-/) · [GitHub @yavuzscnplt](https://github.com/yavuzscnplt) · yavuz7500@gmail.com
