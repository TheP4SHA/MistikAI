# MistikAI — Hibrit Yapay Zeka Kampüs Asistanı

<div align="center">

![MistikAI](https://img.shields.io/badge/MistikAI-v1.0-8b5cf6?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![BERT](https://img.shields.io/badge/BERT-Özel_Eğitimli-FF6F00?style=for-the-badge)

**Kural tabanlı eşleştirme, özel eğitimli BERT niyet algılama ve LLM doğal sohbeti birleştiren 3 katmanlı hibrit AI motoru.**

</div>

---

## Ne İşe Yarar?

MistikAI, **Osmaniye Meslek Yüksekokulu (OMYO)** öğrencileri için geliştirilmiş akıllı bir kampüs asistanıdır. Öğrenciler sohbet arayüzü üzerinden ders programı, yemekhane menüsü, akademik takvim ve kampüs bilgilerine anında ulaşabilir.
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
Öğrenci Mesajı
    │
    ▼
┌─────────────────────────┐
│ 1️⃣ Kural Tabanlı Motor   │  700+ anahtar kelime
│ (Öncelik Skoru ile)      │  ile hızlı eşleştirme
└───────────┬──────────────┘
            │
     Belirsiz mi?
            │
            ▼
┌─────────────────────────┐
│ 2️⃣ BERT Niyet Algılama   │  Eş anlamlılar çakışınca
│ (Özel Eğitimli Model)    │  devreye girer
└───────────┬──────────────┘
            │
     Bilinmiyor mu?
            │
            ▼
┌─────────────────────────┐
│ 3️⃣ LLM Doğal Sohbet     │  Groq / Llama 3.1
│ (Serbest Konuşma)        │  ile samimi sohbet
└──────────────────────────┘
```

### Neden 3 Katman?

Projeyi ilk olarak **kural tabanlı** (rule-based) bir motor olarak geliştirdim. Ancak veritabanına eklenen kelime sayısı arttıkça eş anlamlı kelimeler birden fazla modüle aynı anda eşleşmeye başladı. Bu belirsizliği çözmek için **BERT** modelini Türkçe niyet algılama için eğittim. Sohbet ve serbest yazı için de **LLM** (Groq API) katmanını ekledim.

## Özellikler

- 📚 **Ders Programı** — Bölüm ve sınıfa göre günlük/haftalık program
- 🍽️ **Yemekhane Menüsü** — Bugün, yarın veya haftalık menü (kalori bilgisiyle)
- 📅 **Akademik Takvim** — Sınav tarihleri, kayıt dönemleri
- 🏫 **Kampüs Rehberi** — Bina, derslik ve laboratuvar konumları
- 📞 **Telefon Rehberi** — Üniversite personel arama (canlı API)
- 🛡️ **Küfür Filtresi** — Türkçe argo/küfür tespiti
- 📊 **Admin Paneli** — Log analizi, modül yönetimi, kelime eğitimi, akış tasarımcısı
- 🌙 **Karanlık/Aydınlık Tema** — Modern ve şık arayüz

## Teknolojiler

| Katman | Teknoloji |
|--------|----------|
| Backend | PHP 8.0+ (OOP, PDO) |
| Veritabanı | MySQL / MariaDB |
| AI Katman 1 | Özel Kural Motoru (Öncelik Skorlu) |
| AI Katman 2 | BERT (Türkçe Niyet Algılama) |
| AI Katman 3 | Groq API (Llama 3.1 8B) |
| Frontend | Vanilla JS, CSS3 |
| Güvenlik | CSRF, PDO Prepared Statements, bcrypt |

## Kurulum

Detaylı kurulum rehberi için [docs/SETUP_GUIDE.md](docs/SETUP_GUIDE.md) dosyasına bakın.

```bash
git clone https://github.com/yourusername/MistikAI.git
cd MistikAI
cp config/db.php.example config/db.php
# db.php dosyasını düzenleyin
mysql -u root -p mistikai_db < database/schema.sql
mysql -u root -p mistikai_db < database/seed.sql
```

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
