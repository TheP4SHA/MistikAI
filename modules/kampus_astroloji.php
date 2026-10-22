<?php
// Kampüs Astrolojisi Modülü - Eklenti Dosyası
// Mevcut Değişkenler: $this (Motor Nesnesi), $clean_input (küçük harf, ASCII katlanmış), $raw_input, $this->db (Veritabanı)

// Anahtarlar ASCII katlanmış burç adlarıdır; $clean_input da katlanmıştır ("Koç" → "koc").
$burclar = [
    'koc' => '♈ Koç', 'boga' => '♉ Boğa', 'ikizler' => '♊ İkizler', 'yengec' => '♋ Yengeç',
    'aslan' => '♌ Aslan', 'basak' => '♍ Başak', 'terazi' => '♎ Terazi', 'akrep' => '♏ Akrep',
    'yay' => '♐ Yay', 'oglak' => '♑ Oğlak', 'kova' => '♒ Kova', 'balik' => '♓ Balık',
];

$hedef_burc = null;

// Burç adı kelime olarak geçiyor mu? ("koç burcu", "burcum yay") — "yayın", "kovalamak" gibi kelimeler eşleşmez.
if (preg_match('/\b(' . implode('|', array_keys($burclar)) . ')(?:um|im|un|in|uyum|iyim|lar|ler)?\b/', $clean_input, $m)) {
    $hedef_burc = $burclar[$m[1]];
}

// Eğer burç adı verilmemişse, kullanıcıdan burcunu seçmesini iste
if (!$hedef_burc) {
    $buttons = [];
    foreach (['Koç', 'Boğa', 'İkizler', 'Yengeç', 'Aslan', 'Başak', 'Terazi', 'Akrep', 'Yay', 'Oğlak', 'Kova', 'Balık'] as $b) {
        // Buton metni (burç adı) mesaj olarak gelir; modul_ komutu doğrudan bu modülü çalıştırır.
        $buttons[] = ['text' => $b, 'payload' => 'modul_kampus_astroloji'];
    }
    return $this->response("🔮 Kampüs falına bakabilmem için bana burcunu söylemelisin:", $buttons);
}

// 15'er cümlelik dev astroloji veritabanımız
$yorumlar = [
    '♈ Koç' => [
        "Sabah dersine son dakika yetişip en öne oturma enerjin bugün herkesi şaşırtacak. Sakin ol şampiyon!",
        "Grup ödevinde liderliği alman an meselesi, kimse itiraz etmeyecek. Sazı eline al ve grubu kurtar.",
        "Kantinde bir anda aklına esip masadaki herkese çay ısmarlayabilirsin. Ay sonu bütçesine dikkat et!",
        "Hocanın sorduğu o zor soruya ilk cevabı sen vereceksin ama doğru mu emin değiliz. Özgüvenin yeter.",
        "Bugün enerjin tavan! O zorlu sınavın notlarına çalışmaya başlamak için harika bir gün, kütüphaneye koş.",
        "Sabırsızlığın bugün başına bela olabilir, yemekhane sırasını sessizce bekle ve sakın kaynak yapma.",
        "İtiraz etme huyun bugün hocayla kısa bir tartışmaya yol açabilir. Haklı bile olsan bugünlük alttan al.",
        "Kampüste yürürken aniden yön değiştirip derse girmek yerine çimlerde oturabilirsin. Bazen mola şarttır.",
        "Vize notunu öğrenince vereceğin ani tepkiye dikkat et, etraftakiler korkmasın. Derin bir nefes al.",
        "Sınavda ilk çıkan sen olacaksın, arkana bile bakmadan sınıfı terk etme isteğin çok yüksek.",
        "Yeni bir öğrenci kulübüne üye olmak için ani ve güçlü bir heves gelebilir. Hemen adını yazdır.",
        "Bugün okulun spor tesislerinde stres atmak sana tahminden çok daha iyi gelecek. Enerjini at.",
        "Kütüphanede çok ses çıkarıp uyarı alma ihtimalin var. Bugünlük o tükenmez kaleminle oynamayı bırak.",
        "Ödevde 'Ben hallederim' deyip tüm yükü sırtlanma, biraz da arkadaşlarına iş bırak. Yıpranacaksın.",
        "Bugün karşına çıkacak akademik fırsatlara balıklama atla, şans tamamen senden yana!"
    ],
    '♉ Boğa' => [
        "Bugün yemekhanedeki menü tam senlik! O upuzun sıraya girmeye kesinlikle değecek, afiyet olsun.",
        "Sabahki derse uyanmak bugün senin için dünyanın en zor işi olabilir. Alarmı üçle ve yorganı fırlat.",
        "Kütüphanede her zamanki favori koltuğunu başkası kapmış olabilir. Kriz çıkarma, yan masaya geç.",
        "Ders çalışırken yanında atıştırmalık olmadan asla odaklanamayacaksın, kantine önden bir uğra.",
        "İnatçılığın bugün grup projesinde kilitlenmelere yol açabilir. Başkalarının fikirlerine biraz esnek ol.",
        "Konfor alanından çıkıp o hiç sevmediğin seçmeli derse gitmen gerek. Dayanabilirsin, sadece 45 dakika!",
        "Kantinde poğaça-çay ikilisine bugün gereğinden fazla para harcayabilirsin. Cüzdanı kontrol et.",
        "Bir arkadaşın senden ders notlarını isteyecek ama paylaşmamak için direneceksin. Biraz cömert ol.",
        "Sınav haftası yaklaşsa da o uykuyu tam almadan asla masaya oturmayacaksın. Uyku senin yakıtın.",
        "Kampüsteki en rahat köşeyi bulup iki ders arası harika bir uyku çekeceksin. Kulaklıklarını unutma.",
        "Maddi konularda bugün biraz hesaplı olman gerekebilir, sürpriz fotokopi masrafları çıkabilir.",
        "Sabrın bugün bir hoca tarafından test edilecek. Derin bir nefes al, gülümse ve başını salla.",
        "Kaliteli bir filtre kahve bugün senin tüm akademik dertlerini çözecek tek şey. Kendini şımart.",
        "Verdiğin bir sözde durmak için bugün kampüste fazlasıyla çaba sarf etmen gerekecek. Üşenme.",
        "Notların gayet iyi ama senin gözün her zaman en yüksekte ve en garantide. Çalışmaya devam!"
    ],
    '♊ İkizler' => [
        "Derste hocayı dinlerken aynı anda 3 farklı WhatsApp grubunda dedikodu yapacaksın. Multitasking kralı!",
        "İki farklı kulüp etkinliği aynı saate denk gelecek, hangisine gideceğini şaşıracaksın. İkisine de uğra.",
        "Kararsızlığın yüzünden bugün kantin sırasında arkandakileri fena çıldırtabilirsin. Önceden karar ver.",
        "Bugün tanışacağın yepyeni biriyle ayaküstü harika bir muhabbet kuracaksın. Kampüs çevren genişliyor.",
        "Ders notlarını okurken aklın bambaşka diyarlarda olacak. Geri dön ve o paragrafı baştan oku!",
        "Sınavdan çıkıp 'Kesin kaldım' derken diğerinden '90 garanti' diyeceksin. Durumun hep çok karışık.",
        "Hocaya sorduğun zekice soruyla tüm sınıfın ufkunu açabilirsin. Zekan bugün ışıl ışıl parlıyor.",
        "Seçmeli dersi değiştirme süresi bitmediyse bugün o kararı aniden verebilirsin. Emin misin?",
        "Elindeki yepyeni kampüs dedikodusunu arkadaşına anlatmak için dersin bitmesini bekleyemeyeceksin.",
        "Bugün öğrenci işlerinde işin varsa çok kolay halledeceksin, dilin bugün çok iyi laf yapıyor.",
        "Aynı anda birden fazla ödeve başlayıp hiçbirini bitirememe riskin yüksek. Tek birine odaklan.",
        "Sıkıcı bir derste yanında oturan kişiyi espirilerinle dersten koparabilirsin. Sessiz gülün!",
        "Bugün kampüste ikizini (ruh eşini) bulmuş gibi hissedeceğin bir tesadüf yaşanacak.",
        "Sürekli değişen fikirlerin yüzünden grup arkadaşlarını bugün biraz yoracaksın. Kararlı ol.",
        "Kütüphanede ders çalışmak yerine kitaplar arasında kaybolup bambaşka şeyler okuyacaksın."
    ],
    '♋ Yengeç' => [
        "Bugün evden veya yurttan getirdiğin yemeği arkadaşlarınla paylaşmak sana çok iyi hissettirecek.",
        "Vizeler yaklaşıyor, aniden gelen 'ben bu bölümü neden okuyorum' melankolisine dikkat!",
        "Geçen dönemki kötü notlarını hatırlayıp bugün durduk yere biraz hüzünlenebilirsin. Geçmişe takılma.",
        "Bir arkadaşının derdine derman olmak bugün senin kendi akademik işlerinden bile önemli olacak.",
        "Kantinde eski bir anıyı hatırlatan o şarkı çalacak ve elindeki çayla uzaklara dalıp gideceksin.",
        "Hocanın sınıf geneline yaptığı küçük bir eleştiriyi bugün fazla kişisel algılayabilirsin, alınma.",
        "Kütüphanede en izole köşeye geçip dış dünyayla iletişimini kesmek isteyeceksin. Odaklanma vakti.",
        "Bugün kampüste favori hocanla yapacağın o samimi sohbet gününü aydınlatacak.",
        "Sınav veya sunum stresi midene vurabilir, bugün yemekhanede daha hafif şeyler tercih et.",
        "Geçmişte kaçırdığın bir fırsat yüzünden bugün 'keşke' diyeceksin, ama önündeki vizelere bak!",
        "Ev veya yurt arkadaşlarınla yapacağın o sıcak kahve sohbeti bugün sana terapi gibi gelecek.",
        "Ders çalışırken sürekli aileni arayıp dertleşme ihtiyacı hissedeceksin. Bir seslerini duy.",
        "Grup ödevinde herkesin işini toparlayan o 'anne/baba' figürü bugün yine sen olacaksın.",
        "Kampüsteki kedilere ve köpeklere bugün ekstra şefkat göstereceğin sevgi dolu bir gün.",
        "Sezgilerin bugün çok kuvvetli; sınavda sallayacağın o C şıkkı kesinlikle doğru çıkacak!"
    ],
    '♌ Aslan' => [
        "Bugün kampüse öyle bir giriş yapacaksın ki, tüm gözler senin o havalı tarzında olacak. Sahne senin!",
        "Derste yaptığın etkili sunumla hocayı bile kendine hayran bırakacaksın. Alkışları topla.",
        "Kantinde bir masaya oturduğunda etrafın hemen kalabalıklaşacak, popülerliğin bugün zirvede.",
        "Eleştirilmeye hiç tahammülün yok, bugün hocayla veya asistanla ters düşmemeye özen göster.",
        "Bir projede adının en başta yazması için tatlı ama sert bir ego savaşına girebilirsin.",
        "Bugün tarzın kusursuz. Kütüphaneye bile defileye çıkar gibi havalı ve özgüvenli gideceksin.",
        "Aldığın o yüksek notu herkese duyurmak isteyeceksin. Haklısın, kutlamak senin işin!",
        "Biri senin parlak fikrini çalmaya çalışırsa bugün kampüste aslan gibi kükreyebilirsin.",
        "Sınavdan düşük alsan bile 'Sorular zaten çok saçmaydı' diyerek duruşundan asla ödün vermeyeceksin.",
        "Liderlik vasfın bugün bir öğrenci kulübünde koca bir krizi çözmeni sağlayacak. Yönetimi devral.",
        "Lüks harcama isteğin kabarıyor, bugün kampüs dışındaki o pahalı kafeye gitmek isteyeceksin.",
        "İltifat duymaya çok ihtiyacın var, bugün en yakın kankandan bunu açıkça isteyebilirsin.",
        "Kütüphanede çalışırken bile o dik duruşunla yan masadakilerin gizli dikkatini çekeceksin.",
        "Dramatik tepkilerin bugün arkadaş grubunda efsaneleşecek bir anıya dönüşecek.",
        "O zor sınavı geçtiğinde kendine çok güzel ve pahalı bir ödül sözü ver, bunu fazlasıyla hak ettin!"
    ],
    '♍ Başak' => [
        "Renkli kalemlerin ve kusursuz düzenlenmiş notların bugün tüm sınıfın ilgi odağı olacak.",
        "Sınavdan 95 alınca o 5 puanın nereden kırıldığını bulmak için hocanın kapısında yatacaksın.",
        "Grup ödevinde kimsenin işini beğenmeyip gece yarısı oturup her şeyi baştan sen yazacaksın.",
        "Ders programındaki ufacık bir değişiklik bile bugün bütün sinirlerini bozabilir. Sakin ol.",
        "Öğrenci işlerindeki memurun yaptığı hatayı anında yakalayacak ve yönetmeliğe göre düzelttireceksin.",
        "Kantindeki masanın temizliğine takılıp kendi ıslak mendilinle masayı köşe bucak silebilirsin.",
        "Eleştirel gözlerin bugün hocanın slaytlarındaki yazım hatalarına (de/da ayrımı) takılacak.",
        "Vizelere en erken sen çalışmaya başlayacaksın ve şu an herkesten tam 10 adım öndesin.",
        "Arkadaşlarına vereceğin o altın değerindeki akademik tavsiyeler bugün hayat kurtaracak.",
        "Planlı çalışman sayesinde final haftasını herkes ağlarken sen kahve içerek rahatça geçireceksin.",
        "Detaylarda o kadar boğulacaksın ki, ödevin asıl konusunu ve amacını kaçırma riskin var.",
        "Kütüphanedeki o sessiz ve düzenli atmosfer bugün sana dünyadaki en güzel yer gibi gelecek.",
        "Harcamalarını kuruşu kuruşuna hesapladığın o tablo bugün bütçeni kurtaracak.",
        "'Ben sana demiştim' cümlesini bugün o tembel arkadaşına kurmak için sabırsızlanıyorsun.",
        "Mükemmeliyetçiliği bugünlük bir kenara bırak ve sadece 'geçer not' almanın tadını çıkar."
    ],
    '♎ Terazi' => [
        "Sabah dolabın önünde 'Bugün kampüse ne giysem?' diye düşünürken ilk dersi kaçırabilirsin.",
        "Kantinde kimin yanına oturacağına bir türlü karar veremeyip elinde çayla ayakta kalma riskin var.",
        "Kütüphanede ders çalışmak yerine yan masadaki o estetik kişinin profilini inceleyeceksin.",
        "Çatışmalardan nefret ettiğin için bugün iki arkadaşın arasındaki kavgada zorunlu hakem olacaksın.",
        "Bugün aldığın o yeni ve estetik kahve termosuyla kampüste çok havalı görüneceksin.",
        "Hocanın sorduğu zor soruya 'Hem evet hem hayır hocam, açıya göre değişir' deyip durumu toparlayacaksın.",
        "Ders çalışmakla sosyalleşmek arasındaki o ince çizgide bugün dengen biraz kayabilir.",
        "Sanatsal ve görsel yeteneklerin bugün o sıkıcı sunum dosyasında harikalar yaratacak.",
        "Grup projelerinde herkesin sevdiği ve 'aman o da bizim grupta olsun' dediği o tatlı insansın.",
        "Seçmeli ders seçiminde o kadar kararsız kalacaksın ki, sistem seni rastgele bir derse atayacak.",
        "Bugün kampüste yapacağın içten bir iltifat, hiç beklemediğin birinin gününü güzelleştirecek.",
        "Estetik takıntın yüzünden sadece notları güzel ve okunaklı yazılmış birinden fotokopi çekeceksin.",
        "Yemekhanede tatlı ile çorba arasında kalıp ikisini de alarak bütçeyi (ve diyeti) bozacaksın.",
        "Adil olma duygun bugün sınıftaki bir haksızlığa (not itirazına) ses çıkarmanı sağlayacak.",
        "Kendini çok yorma, bazen sadece uyumak ve hiçbir şeye karar vermemek de en güzel seçenektir."
    ],
    '♏ Akrep' => [
        "Bütün gece deli gibi çalışıp sabah herkese 'Hiç kapağını açmadım kanka' diyeceksin. Yemezler!",
        "Kampüsteki en büyük dedikoduyu ve o gizli sırrı bugün tesadüfen sadece sen öğreneceksin.",
        "Derste hocanın gözlerinin içine öyle bir bakacaksın ki, hoca konuyu sadece sana anlatıyormuş gibi hissedecek.",
        "Senden zorla not isteyen o kişiye notları vereceksin ama içinde bilerek eksik sayfa bırakabilirsin.",
        "Bir hedefe (o dersten AA almaya) kitlendiğinde seni kimse durduramaz; bugün kütüphaneyi sen kapatacaksın.",
        "Kampüste hoşlandığın kişinin ders programını stalklarken FBI'a taş çıkartacaksın.",
        "Hocanın takıntılı olduğu o detayı fark edip sınavda tam oradan vurarak en yüksek notu sen alacaksın.",
        "Gizemli ve sessiz havan bugün kantinde hiç tanımadığın yeni birinin ilgisini fena halde çekecek.",
        "Bugün güvenin çok zor kazanıldığını o dedikoducu arkadaşına sert bir şekilde gösterebilirsin.",
        "Sınav anında kriz yönetimin efsane. Bilmediğin soruyu öyle bir yorumlayacaksın ki hoca bile doğru sanacak.",
        "Tutkuların bugün çok yüksek; tutkuyla sevdiğin o bölüm dersine tamamen gömüleceksin.",
        "Kimsenin bilmediği o karanlık ve sessiz çalışma alanını bugün keşfedip gizli karargahın yapacaksın.",
        "İntikam soğuk yenen bir yemektir. Geçen dönem seni projede eken arkadaşına bugün yüz çevireceksin.",
        "Duygularını o kadar iyi saklıyorsun ki, vizeden 10 aldığında bile yüzünde tek bir mimik oynamayacak.",
        "Sezgilerine her zaman güven. Bugün o derse girmemek içinden geliyorsa, bil ki yoklama alınmayacaktır."
    ],
    '♐ Yay' => [
        "Derse girmek üzereyken bir anda arkadaşlarına 'Hadi şehre inelim' diyen o özgür ruh sen olacaksın.",
        "Sınavdan 30 almış olabilirsin ama 'Finalde 80 alsam rahat kurtarıyorum' iyimserliğin kimsede yok.",
        "Kampüste bugün yerinde hiç duramayacaksın; kütüphane, kantin, amfi arası sürekli mekik dokuyacaksın.",
        "Hocanın anlattığı basit bir konuya aniden felsefi bir yorum getirip sınıfı derin düşüncelere iteceksin.",
        "Gezme planları yaparken devamsızlık sınırını aşma riskin çok yüksek, OBS'yi bir kontrol et!",
        "Doğallığın ve patavatsızlığın bugün komik bir gafa yol açabilir, hocayla konuşurken kelimelere dikkat.",
        "Vizelere 1 gece kala tüm dönemin konularını bitirebileceğine olan o kör inancın herkesi şaşırtıyor.",
        "Bugün kampüste tamamen farklı bir bölümden, farklı bir şehirden yepyeni bir dost edinebilirsin.",
        "Sınıfın en arka köşesinde hocayı dinliyormuş gibi yapıp uçak/otobüs bileti bakarken yakalanma ihtimalin var.",
        "Abartma huyun yüzünden bugün 'Hoca 1000 sayfa ödev verdi!' diyerek bütün sınıfı paniğe sokacaksın.",
        "Maceracı ruhun, kampüste daha önce hiç gitmediğin o ıssız ve ağaçlık köşeyi bugün keşfedecek.",
        "Etrafına yaydığın o bitmez pozitif enerji bugün sınav stresi yaşayan arkadaşlarının tek ilacı olacak.",
        "Verdiğin o büyük sözleri ('bugün kütüphanede 5 konu bitireceğim' gibi) tutmakta zorlanabilirsin.",
        "Okulun getirdiği rutinden çok fena sıkıldın, bugün ders çıkışı farklı bir yoldan eve/yurda git.",
        "Şansın her zaman yaver gidiyor. Sınavda tam da sadece göz ucuyla baktığın o 2 konudan soru gelecek!"
    ],
    '♑ Oğlak' => [
        "Gelecekteki kariyerin için hocalarla ağ (network) kurma çalışmaların bugün meyvesini verebilir.",
        "Vizelere daha 1 ay var ama sen şimdiden renkli post-it'lerle özet çıkarmaya başladın bile, tebrikler.",
        "Kütüphanede adeta kendine ait özel bir ofis kuracaksın; bilgisayar, kahve, ajanda ve notlar her yerde...",
        "Tembellik yapan grup arkadaşlarına bugün bir CEO gibi resti çekip tüm işleri devralacaksın.",
        "Gelecek kaygın bugün biraz tavan yapabilir, LinkedIn'de mezunların profillerinde fazla vakit geçirme.",
        "Derste en zor soruları hocaya yönelterek sınıfın 'Bu kadarına da gerek yoktu' bakışlarını toplayacaksın.",
        "Disiplinin herkesi kıskandırıyor. Sabah 8 dersine o dondurucu havada bile tam vaktinde gideceksin.",
        "Bugün paranı o kadar iyi ve planlı yöneteceksin ki, ay sonunu getirme garantisini cebine koydun.",
        "Ciddiyetin yüzünden sana yaklaşmaya çekinen o kişi aslında seninle tanışmak için can atıyor, biraz gülümse.",
        "Öğrenci işlerindeki resmi prosedürleri ve yönetmelikleri ezbere bildiğin için bugün herkes sana danışacak.",
        "Kısa vadeli eğlenceler yerine her zaman uzun vadeli başarıya (final ortalamasına) odaklısın. Yola devam.",
        "Omuzlarındaki akademik yük bugün fazla gelebilir. Bugünlük mükemmel olmayı bırakıp kampüste çay iç.",
        "Zaman yönetimin gerçekten muazzam. Bugün 3 ödev, 2 ders ve 1 toplantıyı başarıyla bitireceksin.",
        "Hırslı yapın yüzünden en yakın arkadaşınla bile gizli bir not rekabetine girebilirsin, dozunda bırak.",
        "Hedeflerine adım adım ulaşıyorsun. O onur belgesi ve diploma senin eline kesinlikle geçecek!"
    ],
    '♒ Kova' => [
        "Geleneksel not tutma yöntemlerini çoktan bırakıp o derse özel bir yapay zeka promptu geliştireceksin.",
        "Hocanın anlattığı klasik teoriye öyle marjinal bir itiraz getireceksin ki tüm sınıf 'Ne diyor bu?' diyecek.",
        "Bugün sürüye uymak yerine tek tabanca takılıp kampüs çimlerinde kulaklığın ve kitabınla baş başa kalacaksın.",
        "İsyankar ruhun bugün yoklama sistemine, sınav takvimine veya yemekhane fiyatlarına karşı bir tepki doğurabilir.",
        "Grup projelerinde sunduğun o absürt ama aslında dâhiyane fikir bugün nihayet ekip tarafından kabul görecek.",
        "Arkadaşlarının klasik çalışma taktikleri sana çok sıkıcı geliyor, sen gece 3'te ilhamla çalışıyorsun.",
        "Kimsenin umursamadığı bir kampüs sorununu (örneğin geri dönüşüm kutuları) bugün kendine dert edinebilirsin.",
        "Tarzın, müzik zevkin ve duruşunla kampüsün 'cool ve farklı' kişisisin, bugün bu çok belirginleşecek.",
        "Teknolojik aletlerle aran bugün çok iyi. Derste çöken sunum bilgisayarını 2 dakikada kurtarıp kahraman olacaksın.",
        "Özgürlüğüne çok düşkünsün. Devamsızlık sınırına dayanmak pahasına bugün canının istemediği derse girmeyeceksin.",
        "Kampüste herkese eşit mesafedesin ama kimse senin o derin asıl iç dünyana bir türlü inemiyor.",
        "Kampüsün en entelektüel veya en sıra dışı öğrenci kulübüne katılmak için bugün büyük bir çekim hissedeceksin.",
        "Geleceğe ve vizyonlarına o kadar odaklısın ki, yarınki basit bir vizeyi unutma ihtimalin var. Bugüne dön!",
        "'Neden ve Nasıl?' sorularını bugün hocalara o kadar çok soracaksın ki dersin işleyişi tamamen değişecek.",
        "Orijinal fikirlerle dolu o zihnine güven. Sınavdaki yoruma dayalı uzun sorular tam senin kalemine göre!"
    ],
    '♓ Balık' => [
        "Derste hocayı dinlerken hayallere dalıp o kalemi defterin kenarına karalama yapmakla meşgul olacaksın.",
        "Bir arkadaşının derdine o kadar içten üzüleceksin ki kendi vizelerini ve ödevlerini tamamen unutacaksın.",
        "Romantik havan bugün tam üzerinde. Kütüphanede test çözmekten çok, yan masadaki o estetik anı izleyeceksin.",
        "Teslim tarihini kafanda yanlış kurguladığın o ödev yüzünden bugün ufak çaplı bir panik atağı yaşayabilirsin.",
        "Kampüsün tüm kedileri senin etrafında toplanacak, onlarla uzun uzun dertleşirken birilerine yakalanabilirsin.",
        "Sanatsal ilhamın çok yüksek. Notlarını o kadar güzel ve estetik renklendireceksin ki çalışmaya kıyamayacaksın.",
        "Karar vermekte çok zorlanıyorsun. 'Dersi alttan mı alayım, yaz okuluna mı bırakayım' derken kayıtsız kalacaksın.",
        "Uykuya o kadar düşkünsün ki sabah dersi için kurduğun 5 alarmın 5'ini de kapatıp tatlı rüyana geri dönebilirsin.",
        "Bugün altıncı hissin inanılmaz! Hangi sorunun çıkacağını içine doğduğu gibi çalış, tam oradan vuracaksın.",
        "Sınavdan çıkınca 'Çok kötü geçti, kağıdı boş verdim' deyip kendini harap edecek ama sonuçlar gelince 85 alacaksın.",
        "Müzik kulaklıkların senin kampüsteki kalkanın. Bugün onları takıp herkesten ve her şeyden uzaklaşmak isteyeceksin.",
        "Kantinde otururken hüzünlü bir şarkı duyduğunda elindeki çayla birlikte uzaklara dalıp gidebilirsin.",
        "Merhametli yapın yüzünden, dönem boyu hiç çalışmayan o kişiye tüm notlarını kendi ellerinle fotokopi çektireceksin.",
        "Unutkanlığın bugün zirvede. Öğrenci kartını, flash belleğini veya şemsiyeni nerede bıraktığına çok dikkat et!",
        "Evrenin iyi enerjisi bugün seninle. O beklediğin burs başvurusu veya zorlu sınav sonucu bugün olumlu gelebilir."
    ]
];

$hedef_liste = $yorumlar[$hedef_burc];

// 15 dakikada bir aynı sonucu veren matematiksel tohum (seed)
$zaman_dilimi = floor(date('i') / 15); 
$seed_string = date('Y-m-d-H') . "-" . $zaman_dilimi . "-" . $hedef_burc;
$seed = crc32($seed_string);

srand($seed);
$secilen_yorum = $hedef_liste[array_rand($hedef_liste)];
srand(); // Sistemin rastgeleliğini bozmamak için sıfırladık

$saat_dilimi_bilgisi = date('H:') . sprintf("%02d", $zaman_dilimi * 15);
$cevap = "✨ <b>Günlük Kampüs Falı</b><br><b>{$hedef_burc}</b><br><br>📝 {$secilen_yorum}<br><br><small><i>(Bu fal {$saat_dilimi_bilgisi} itibarıyla güncellenmiştir. 15 dakikada bir yenilenir! 😎)</i></small>";

// Eğer falı gösterdikten sonra bir alt menüye yönlendirmek istersen:
$buttons = [
    ['text' => '🏠 Ana Menüye Dön', 'payload' => 'flow_ana_menu']
];

return $this->response($cevap, $buttons);