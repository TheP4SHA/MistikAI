# MistikAI — Hibrit Yapay Zeka Kampüs Asistanı

<div align="center">

![MistikAI](https://img.shields.io/badge/MistikAI-v1.1-8b5cf6?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![BERT](https://img.shields.io/badge/BERT-Özel_Eğitimli-FF6F00?style=for-the-badge)

**Kural tabanlı anahtar kelime eşleştirme, özel eğitimli BERT niyet algılama ve LLM doğal sohbeti birleştiren 3 katmanlı hibrit motor.**

[Mimari](#3-katmanlı-hibrit-mimari) · [Kurulum](#kurulum) · [Yapılandırma](#yapılandırma) · [Güvenlik](#güvenlik) · [Gizlilik](#gizlilik-kvkk--gdpr) · [English](README.md)

</div>

---

## Ne İşe Yarar?

MistikAI, **Osmaniye Meslek Yüksekokulu (OMYO)** öğrencileri için geliştirilmiş bir kampüs asistanıdır. Öğrenciler sohbet arayüzü üzerinden ders programı, yemekhane menüsü, akademik takvim ve kampüs bilgilerine hızlıca ulaşabilir.

İlki dışındaki katmanlar isteğe bağlıdır; MistikAI Python ya da API anahtarı olmadan sıradan bir paylaşımlı hostingde de çalışır.

## Ekran Görüntüleri

<details open>
<summary><b>Arayüz ve Admin Paneli</b></summary>
<br>

| Sohbet Arayüzü (Karanlık) | Sohbet Arayüzü (Aydınlık) |
|:---:|:---:|
| ![Chat Dark](screenshots/chat/chat-dark.png) | ![Chat Light](screenshots/chat/chat-light.png) |

| Yönetici (Admin) Paneli | Akış (Flow) Tasarımcısı |
|:---:|:---:|
| ![Dashboard](screenshots/admin/dashboard.png) | ![Flow Builder](screenshots/admin/flow-builder-1.png) |

| Niyet Eğitimi (Zeka) | Dinamik İçerik (Kazıma) |
|:---:|:---:|
| ![Intent Lab](screenshots/admin/intent-lab-1.png) | ![Dynamic Content](screenshots/admin/dynamic-content-1.png) |

</details>

## 3 Katmanlı Hibrit Mimari

```
Öğrenci Mesajı  (hız sınırı + uzunluk denetimi)
    │
    ▼
┌─────────────────────────┐
│ 1️⃣ Kural Tabanlı Motor   │  Admin panelinden düzenlenen
│ (Öncelik Skoru ile)      │  anahtar kelimeler
└───────────┬──────────────┘
            │
     Belirsiz mi?
            │
            ▼
┌─────────────────────────┐
│ 2️⃣ BERT Niyet Algılama   │  Eş anlamlılar çakışınca
│ (İsteğe bağlı)           │  devreye girer
└───────────┬──────────────┘
            │
   Kapsam dışı / kapalı mı?
            │
            ▼
┌─────────────────────────┐
│ 3️⃣ LLM Doğal Sohbet     │  Groq (OpenAI uyumlu),
│ (İsteğe bağlı)           │  araç çağrısıyla modül seçer
└──────────────────────────┘
            │
            ▼
   Logger: cevaplayan katman, gecikme, tuzlu IP özeti
```

### Neden 3 Katman?

Projeyi ilk olarak **kural tabanlı** (rule-based) bir motor olarak geliştirdim. Ancak veritabanına eklenen kelime sayısı arttıkça eş anlamlı kelimeler birden fazla modüle aynı anda eşleşmeye başladı. Bu belirsizliği çözmek için **BERT** modelini Türkçe niyet algılama için eğittim. Sohbet ve serbest yazı için de **LLM** (Groq API) katmanını ekledim.

1. **Kural motoru** — Anahtar kelime ve öncelik skoruyla, dış çağrı yapmadan anında cevap verir. Türkçeye duyarlıdır (İ/I, `yarin` → `yarın` gibi ASCII katlama), ekleri (`dersim`, `dersler`) ve küçük yazım hatalarını tolere eder. İki modülün skoru çok yakınsa tahmin yerine aday butonları gösterebilir.
2. **BERT** — Kelimeler modüller arasında çakıştığında (ör. "yarın ne var" hem yemek hem ders olabilir) niyeti seçer. Kapsam dışı (`kapsam_disi`) ya da eşiğin altında kalan mesajlar LLM'e geçer.
3. **LLM** — Serbest sohbet ve hiçbir modüle uymayan sorular için. Model yalnızca aktif modülleri OpenAI uyumlu araç çağrısıyla (tool calling) çalıştırabilir, sistem isteminde bugünün tarihini bilir, birincil model yanıt vermezse yedek modele geçer ve hata durumunda kısa bir mesajla düzgünce geri çekilir.

Ayrıntılar: [ARCHITECTURE.md](ARCHITECTURE.md)

## Özellikler

- 📚 **Ders Programı** — Bölüm ve sınıfa göre günlük/haftalık program
- 🍽️ **Yemekhane Menüsü** — Bugün, yarın veya haftalık menü (kalori bilgisiyle)
- 📅 **Akademik Takvim** — Sınav tarihleri, kayıt dönemleri
- 🏫 **Kampüs Rehberi** — Bina, derslik ve laboratuvar konumları
- 📞 **Telefon Rehberi** — Üniversite personel arama (canlı API)
- 🛡️ **Küfür Filtresi** — Türkçe argo/küfür tespiti
- 📊 **Admin Paneli** — Log analizi (cevaplayan katman, gecikme), modül yönetimi, kelime eğitimi, akış tasarımcısı
- 🌙 **Karanlık/Aydınlık Tema** — Modern ve şık arayüz

## Teknolojiler

| Katman | Teknoloji |
|--------|----------|
| Backend | PHP 8.0+ (OOP, PDO) |
| Veritabanı | MySQL / MariaDB (utf8mb4) |
| AI Katman 1 | Kural motoru (öncelik skoru, Türkçeye duyarlı bulanık eşleştirme) |
| AI Katman 2 | BERT (Türkçe niyet sınıflandırma, Python servisi) |
| AI Katman 3 | Groq API (OpenAI uyumlu; varsayılan `llama-3.3-70b-versatile`, yedek `llama-3.1-8b-instant`) |
| Frontend | Vanilla JS, CSS3 |
| Güvenlik | CSRF, PDO prepared statements, HTML izin listesi süzgeci, CSP, bcrypt |

## Kurulum

Adım adım rehber (XAMPP, Laragon, Nginx): [docs/SETUP_GUIDE.md](docs/SETUP_GUIDE.md)

```bash
git clone https://github.com/TheP4SHA/MistikAI.git
cd MistikAI
cp config/db.php.example config/db.php
# db.php: veritabanı bilgileri, API anahtarları ve en azından $IP_TUZU

mysql -u root -p -e "CREATE DATABASE mistikai_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p mistikai_db < database/schema.sql
mysql -u root -p mistikai_db < database/seed.sql   # isteğe bağlı demo verisi
```

Web sunucusunun kök dizinini `public/` klasörüne yönlendirin.

### İlk Yönetici

**Varsayılan bir yönetici hesabı yoktur.** İlk kurulum sayfası `admin/kurulum.php` üzerinden (ör. `http://mistikai.local/admin/kurulum.php`) ilk tam yetkili yöneticiyi oluşturun. Sayfa yalnızca hiç yönetici yokken çalışır. Diğer yöneticileri tam yetkili bir yönetici **Ayarlar** sayfasından ekler.

### Mevcut Kurulumu Güncelleme

Veritabanını yedekleyin ve geçiş betiğini bir kez çalıştırın:

```bash
mysql -u KULLANICI -p mistikai_db < database/migrations/2026-10-guvenlik.sql
```

Betik yeni sütun ve tabloları ekler (yönetici yetkileri, giriş denemeleri, log katmanı ve gecikmesi), açık panel kaydını kapatır ve **eski seed dosyasındaki parola özetini hâlâ kullanan yöneticiyi siler**. Tek yöneticiniz buysa `admin/kurulum.php` ile yenisini oluşturun. Ardından `config/db.php` dosyanızı `config/db.php.example` ile karşılaştırıp yeni ayarları ekleyin.

## Yapılandırma

Tüm ayarlar `config/db.php` içinde PHP değişkenleri olarak durur (`config/db.php.example` dosyasından kopyalayın).

| Değişken | Varsayılan | Açıklama |
|----------|------------|----------|
| `$DB_HOST` | `'127.0.0.1'` | Veritabanı sunucusu |
| `$DB_NAME` | `'mistikai_db'` | Veritabanı adı |
| `$DB_USER` / `$DB_PASS` | — | Veritabanı kullanıcı bilgileri |
| `$GROQ_API_KEY` | örnek değer | Groq API anahtarı; LLM katmanı olmadan çalışmak için `''` yapın |
| `$BERT_API_URL` | `'http://localhost:8005/niyet-algila'` | BERT servis adresi; boşsa 2. katman kapalı |
| `$BERT_API_KEY` | `''` | `X-Api-Key` başlığıyla gönderilen ortak sır; servisteki `BERT_API_KEY` ile aynı olmalı |
| `$BASE_PATH` | `''` | Alt klasöre kurulduysa URL öneki, ör. `'/MistikAI'` |
| `$ADMIN_TEMIZ_URL` | `false` | `true`: admin bağlantıları `.htaccess` temiz URL'lerini kullanır (`/admin/dashboard`); `false`: doğrudan dosyalar |
| `$ADMIN_KOD_EDITORU` | `false` | Tarayıcı içi PHP modül editörü ve SQL terminali (yalnız tam yetkili). Canlı ortamda `false` bırakın |
| `$GUVENILIR_VEKILLER` | `[]` | `X-Forwarded-For` başlığına güvenilen ters vekil (reverse proxy) IP'leri |
| `$IP_TUZU` | örnek değer | Log ve hız sınırındaki IP özeti için tuz. **Uzun, rastgele bir değer verin** |
| `$LLM_MODEL` | `'llama-3.3-70b-versatile'` | Birincil Groq modeli |
| `$LLM_YEDEK_MODEL` | `'llama-3.1-8b-instant'` | Birincil model sınırlandığında ya da çöktüğünde yedek model |
| `$LLM_MAX_TOKENS` | `400` | LLM cevabı başına en fazla token |
| `$LLM_API_URL` | Groq chat completions | OpenAI uyumlu herhangi bir sohbet ucu |
| `$DAKIKA_ISTEK_SINIRI` | `20` | İstemci başına dakikadaki sohbet isteği |
| `$MESAJ_MAX_UZUNLUK` | `500` | Mesaj başına en fazla karakter |
| `$LOG_SAKLAMA_GUN` | `180` | Bundan eski sohbet logları silinir (gün) |
| `$OKUL_BILGISI` | `'Osmaniye Meslek Yüksekokulu (OMYO)'` | LLM sistem istemindeki kısa okul tanımı |
| `$SCRAPER_IZINLI_HOSTLAR` | `[]` | İçerik kazıyıcının erişebileceği hostlar, ör. `['www.example.edu.tr']`; boşsa kazıyıcı kapalı |

## Admin Paneli

- **Dashboard** — KPI kartları, canlı log akışı, modül başarı oranları
- **Kara Kutu (Logger)** — Her etkileşim; güven skoru, cevaplayan katman (`katman`) ve gecikme ile
- **Modül Yöneticisi** — Modül oluşturma/düzenleme; tarayıcı içi PHP editörü `$ADMIN_KOD_EDITORU = true` olmadıkça kapalı
- **Zeka Laboratuvarı** — Anahtar kelimeler, öncelikler ve varlık/eş anlamlı tabloları
- **Ders Aktarımı** — Ders programını yapılandırılmış metinden toplu içe aktarma
- **İçerik Kazıyıcı** — Yemek menüsü ve akademik takvimi yalnız `$SCRAPER_IZINLI_HOSTLAR` içindeki hostlardan çeker
- **Akış Tasarımcısı** — Görsel diyalog akışı editörü
- **Ayarlar** — Kazıyıcı adresleri; tam yetkili yöneticiler yeni yönetici ekleyebilir

Yöneticiler **tam** (`tam`) ya da **kısıtlı** (`kisitli`) yetkilidir. Yönetici eklemeyi ve (açıksa) kod editörü ile SQL terminalini yalnız tam yetkililer kullanır.

## BERT Modeli

[bert-model/README.md](bert-model/README.md): anahtar kelimelerden Türkçe şablonlarla veri seti üretme, kapsam dışı sınıfı, eğitim (sabit seed, katmanlı bölme, sınıf bazında rapor) ve 8005 portunda API anahtarı, güven eşiği ve `/saglik` uç noktasıyla servis.

## Güvenlik

- ✅ Sohbet API'sinde ve tüm admin formlarında CSRF jetonu
- ✅ Tüm SQL sorgularında PDO prepared statements
- ✅ Modül/akış cevapları için izin listeli HTML süzgeci; LLM çıktısı metin olarak gösterilir (yalnız kalın yazı, liste ve `https` bağlantısı)
- ✅ Content Security Policy (CSP) başlıkları
- ✅ Oturum sertleştirme (`HttpOnly`, `SameSite`, HTTPS'te `Secure`, strict mode, girişte yeni oturum kimliği)
- ✅ Admin girişinde kaba kuvvet freni (IP ve kullanıcı adı başına hatalı deneme)
- ✅ Sohbet API'sinde dakika başına hız sınırı ve mesaj uzunluğu sınırı (ayarlanabilir)
- ✅ Tarayıcı içi PHP editörü ve SQL terminali varsayılan olarak kapalı (`$ADMIN_KOD_EDITORU`)
- ✅ Kazıyıcı `$SCRAPER_IZINLI_HOSTLAR` ile sınırlı, özel/iç ağ adreslerine karşı SSRF koruması
- ✅ `X-Forwarded-For` yalnız `$GUVENILIR_VEKILLER` listesindeki vekillerden kabul edilir
- ✅ Varsayılan yönetici yok; ilk kurulum sayfası, bcrypt ile parola özeti
- ✅ BERT servisi varsayılan olarak `127.0.0.1` üzerinde, isteğe bağlı API anahtarıyla
- ✅ CI: PHP lint, testler ve gitleaks gizli bilgi taraması

Bir güvenlik açığı bulursanız lütfen gizli olarak bildirin: [SECURITY.md](SECURITY.md)

## Gizlilik (KVKK / GDPR)

- Sohbet logları ve hız sınırlayıcı IP adresinin kendisini değil, **tuzlu özetini** (`$IP_TUZU`) saklar.
- Loglar `$LOG_SAKLAMA_GUN` gün sonra silinir (varsayılan 180).
- Yöneticiler anahtar kelimeleri geliştirebilsin diye mesaj metinleri loglarda saklanır. Kullanıcılarınızı bilgilendirin.
- **3. katman kullanıldığında kullanıcının mesajı (ve kısa sohbet geçmişi) Groq'a gönderilir**; bu, Türkiye dışında bulunan üçüncü taraf bir hizmettir. LLM'i açıyorsanız bunu aydınlatma metninizde belirtmek ve hukuki dayanağı sağlamak işletmecinin sorumluluğundadır. Tüm işlemenin kendi sunucunuzda kalması için `$GROQ_API_KEY = ''` yapın.

## Katkı

Katkılar memnuniyetle karşılanır. Issue ya da Pull Request açabilirsiniz; CI (`php -l`, `php tests/run.php`, gitleaks) geçmelidir. Son değişiklikler: [CHANGELOG.md](CHANGELOG.md)

## Yasal Uyarı / Etik Bildirim

> [!WARNING]
> Bu proje, admin panelinde (`icerik.php` içerisinde) Osmaniye Korkut Ata Üniversitesi (osmaniye.edu.tr) web sitesinden veri çekmek (web scraping) için örnek olarak yazılmış betikler barındırmaktadır. Bu betikler tamamen **eğitim ve konsept kanıtlama (Proof of Concept)** amaçlıdır.
>
> Web sitelerinden izinsiz veri çekmek (scraping), ilgili sitenin Kullanım Şartları'nı ihlal edebilir ve yasal/etik sorunlara yol açabilir. Bu kodların kullanımından doğabilecek her türlü hukuki sorumluluk kullanıcıya aittir. Projeyi canlı ortamda (production) kullanmadan önce üçüncü taraf verilerini çekme, işleme ve saklama hakkınız olduğundan emin olunuz.

## Lisans
MIT License — Detaylar için [LICENSE](LICENSE) dosyasına bakın.

---

<div align="center">
<sub>❤️ Pasha tarafından Osmaniye Korkut Ata Üniversitesi'nde geliştirildi</sub>
</div>
