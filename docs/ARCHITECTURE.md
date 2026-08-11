# Mimari (üst düzey özet)

> Bu doküman, sistemin genel yaklaşımını ve tasarım ilkelerini anlatır.
> Uygulama detayları, veri modeli ve iş kuralları özeldir; talep üzerine
> (ör. teknik mülakat) paylaşılır.

## Genel yaklaşım

Laravel üzerinde, iş mantığının bağımsız **alanlara (domain)** ayrıldığı **modüler,
DDD-esinli** bir mimari. Her alan kendi servis ve veri-taşıma (DTO) katmanlarıyla izole
edilir; böylece her parça bağımsız test edilebilir, ekip büyüdüğünde çakışmadan
geliştirilebilir ve bağımlılıklar tek yönde (UI → Servis → Model) akar.

## Stack & altyapı

| Katman | Teknoloji |
|--------|-----------|
| Uygulama | PHP 8.3 · Laravel 11 · Livewire 4 |
| Veritabanı | MySQL |
| Arama | Meilisearch (full-text, typo-toleranslı) |
| Cache / kuyruk | Redis + kuyruk işçileri |
| Gerçek-zamanlılık | WebSocket |
| Paketleme | Docker |

## Tasarım ilkeleri

- **Finansal hassasiyet:** Para hesapları `float` ile değil, keyfi-hassasiyetli
  aritmetikle (bcmath) yapılır; yuvarlama hataları kabul edilmez.
- **Modülerlik:** Alanlar arası net sınırlar; her alan kendi içinde tutarlı ve
  test edilebilir.
- **Performans:** Arama, ilişkisel veritabanı yerine ona adanmış bir motora devredildi;
  ağır işler (mail, bildirim, içe aktarma) kuyruğa alınır; arayüz güncellemeleri
  gerçek-zamanlı push edilir.
- **Dayanıklılık:** Hatalar kullanıcıyı düşürmeyecek şekilde ele alınır (güvenli
  varsayılanlar, loglama).
- **Birlikte çalışabilirlik:** İşletmenin mevcut sistemlerinden kademeli geçişi
  destekleyecek şekilde tasarlandı.

## Dağıtım modeli: bir müşteri = bir kurulum

Her müşteri **kendi veritabanı ve kendi uygulama konteynerleriyle** çalışır. Ortak bir
kurulumda satır bazlı filtreyle ayırmak yerine bu yol seçildi.

Gerekçe: bu sektörde bir bayinin diğerinin **maliyet, stok ve fiyatını** görmesi
onarılamaz bir güven kaybıdır. Sorgu filtresi tek bir unutulmuş `where` ile delinir;
ayrı veritabanı delinmez. Yanlış bir sorgu başka müşterinin verisini **döndüremez**,
çünkü o veri başka bir veritabanındadır.

Bedeli açıkça kabul edildi: müşteri başına ~275 MB bellek ve kurulum başına ayrı
güncelleme adımı. Bu bedel, otomatikleştirilerek (tek komutluk kurulum/güncelleme
betikleri) ödenebilir hale getirildi — izolasyonu sorgu diline emanet etmek ise
geri alınamaz.

Paylaşılan altyapı (MySQL sunucusu, Redis, arama motoru) ayrı kullanıcı, ayrı
veritabanı ve ayrı anahtar öneki ile paylaşılır. Gerçek-zamanlı WebSocket sunucusu
**paylaşılmaz**: tek uygulama kimliğiyle çalıştığı ve ayrı veritabanlarında kullanıcı
kimlikleri çakıştığı için, paylaşılsaydı bir müşterinin kullanıcısı diğerinin özel
bildirim kanalına abone olabilirdi.

## Yetki ayrıştırması: yönetim paneli

Tüm kurulumları yöneten bir operasyon paneli var (kurulum açma, güncelleme, yedekleme).
Böyle bir panel doğası gereği sunucu üzerinde ayrıcalıklı iş yapar — ve tam bu yüzden
**paneli ele geçiren kişinin bütün müşterilerin verisine ulaşması** riski taşır.

Tasarım bu riski üç sınırla dağıtır:

1. **Panel hiçbir komut çalıştırmaz.** Yapabildiği tek şey bir iş kaydı yazmaktır.
   Ayrıcalıklı işlemleri, sunucu üzerinde çalışan ayrı bir ajan yürütür. Panelin
   konteynerine yönetim soketi bağlanmaz.
2. **Ajan panele güvenmez.** Kendi izin listesi vardır ve her argümanı yeniden doğrular.
   Panel tamamen ele geçirilse ve kuyruğa keyfi bir istek yazılsa bile ajan izin listesi
   dışına çıkmaz. Argümanlar alt sürece dizi olarak geçirilir; dize birleştirme ile
   kabuk komutu kurulmaz.
3. **Panel dışarıya kapalıdır.** Yalnızca yerel arayüze bağlanır; erişim şifreli bir
   tünel üzerinden olur. Panelin rotaları bir bayrağa bağlıdır ve müşteri kurulumlarında
   **hiç var olmaz**.

Panelin ayrıca **veritabanı yoktur**. Bir yönetim paneli, yönettiği altyapıya bağımlı
olmamalıdır: veritabanı çöktüğünde bunu size söyleyebilmesi gerekir.

## Üretim işletmeciliği

- **Yedekleme:** şifreli otomatik yedek (veritabanları + yüklenen dosyalar + yapılandırma),
  saklama politikası ve düzenli **geri yükleme provası**. Alınmamış bir yedek değil,
  *geri yüklenemeyen* bir yedek asıl risktir.
- **Sağlık izleme:** tüm kurulumları tarayan tek bir kontrol — site yanıtı, kuyruk,
  zamanlanmış işler, yedek tazeliği, sertifika süresi, hata birikimi.
- **Deploy öncesi denetim:** hata ayıklama modu, zayıf parola, eksik yasal alan ve
  **mock kalmış entegrasyon sürücüsü** gibi kalemleri kontrol eden bir komut. "Demoda
  çalışıyordu" ile "müşteride çalışır" arasındaki farkı kapatmak için.
- **Test:** 1342 otomatik test; statik analiz (PHPStan) ve biçim denetimi (Pint) temiz.

---

*Daha ayrıntılı mimari ve örnek kod, mülakatta veya talep üzerine sunulabilir.*
