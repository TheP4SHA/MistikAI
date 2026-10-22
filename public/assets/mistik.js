// MistikAI sohbet istemcisi
// Güvenlik kuralları:
//  - Kullanıcı metni her zaman textContent ile yazılır.
//  - Bot cevabı (reply) sunucuda Guvenlik::temizHtml() ile süzülmüş HTML'dir; yalnız o innerHTML ile yazılır.
//  - Buton metinleri textContent; bağlantı butonları yalnız http(s) adresleriyle <a> olur.
(function () {
    'use strict';

    const API = 'api.php';
    const chatBox = document.getElementById('chatBox');
    const form = document.getElementById('chatForm');
    const userInput = document.getElementById('userInput');
    const sendBtn = document.getElementById('sendBtn');
    const typingIndicator = document.getElementById('typingIndicator');
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = themeToggle.querySelector('i');
    const avatarSablon = document.getElementById('botAvatarSablon');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');

    let csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    let mesgul = false;

    const KULLANICI_AVATARI = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

    // ---- Tema -------------------------------------------------------------
    function depoOku(anahtar) {
        try { return window.localStorage.getItem(anahtar); } catch (e) { return null; }
    }
    function depoYaz(anahtar, deger) {
        try { window.localStorage.setItem(anahtar, deger); } catch (e) { /* gizli pencere vb. */ }
    }
    function setTheme(theme) {
        const acik = theme === 'light';
        document.body.classList.toggle('light-mode', acik);
        themeIcon.className = acik ? 'fas fa-sun' : 'fas fa-moon';
        depoYaz('mistikai-theme', acik ? 'light' : 'dark');
    }
    setTheme(depoOku('mistikai-theme') || 'dark');
    themeToggle.addEventListener('click', function () {
        setTheme(document.body.classList.contains('light-mode') ? 'dark' : 'light');
    });

    // ---- Avatar -----------------------------------------------------------
    function botAvatari() {
        return avatarSablon.content.cloneNode(true);
    }
    document.querySelectorAll('[data-avatar]').forEach(function (yer) {
        yer.appendChild(botAvatari());
    });
    const ustAvatarImg = document.querySelector('.bot-avatar img');
    if (ustAvatarImg) { ustAvatarImg.alt = 'MistikAI'; }

    // ---- Mesajlar ---------------------------------------------------------
    function scrollToBottom() {
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    /**
     * @param {'user'|'bot'} gonderen
     * @param {string} icerik
     * @param {{html?: boolean, hata?: boolean}} secenek html=true yalnız sunucunun temizlediği bot cevabı için
     */
    function appendMessage(gonderen, icerik, secenek) {
        secenek = secenek || {};
        const wrapper = document.createElement('div');
        wrapper.className = 'message-wrapper ' + gonderen;

        const avatar = document.createElement('div');
        avatar.className = 'msg-avatar';
        if (gonderen === 'bot') {
            avatar.appendChild(botAvatari());
        } else {
            avatar.innerHTML = KULLANICI_AVATARI; // sabit SVG, kullanıcı verisi içermez
        }

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble' + (secenek.hata ? ' hata' : '');
        if (gonderen === 'bot' && secenek.html) {
            bubble.innerHTML = icerik;
        } else {
            bubble.textContent = icerik;
        }

        wrapper.appendChild(avatar);
        wrapper.appendChild(bubble);
        chatBox.insertBefore(wrapper, typingIndicator);
        scrollToBottom();
    }

    function guvenliUrl(url) {
        try {
            const u = new URL(url, window.location.href);
            return (u.protocol === 'https:' || u.protocol === 'http:') ? u.href : null;
        } catch (e) {
            return null;
        }
    }

    function renderButtons(buttons) {
        const kap = document.createElement('div');
        kap.className = 'chat-buttons-container';
        buttons.forEach(function (btn) {
            if (!btn || typeof btn.text !== 'string') { return; }
            const url = typeof btn.url === 'string' ? guvenliUrl(btn.url) : null;
            let el;
            if (url) {
                el = document.createElement('a');
                el.href = url;
                el.target = '_blank';
                el.rel = 'noopener noreferrer';
            } else {
                el = document.createElement('button');
                el.type = 'button';
                el.addEventListener('click', function () {
                    if (mesgul) { return; }
                    kap.remove();
                    gonder(btn.text, typeof btn.payload === 'string' ? btn.payload : null);
                });
            }
            el.className = 'chat-action-btn';
            el.textContent = btn.text;
            kap.appendChild(el);
        });
        if (kap.childElementCount > 0) {
            chatBox.insertBefore(kap, typingIndicator);
            scrollToBottom();
        }
    }

    function yaziyor(goster) {
        typingIndicator.classList.toggle('gizli', !goster);
        if (goster) {
            chatBox.appendChild(typingIndicator);
            scrollToBottom();
        }
    }

    function mesgulAyarla(durum) {
        mesgul = durum;
        sendBtn.disabled = durum;
    }

    // ---- API --------------------------------------------------------------
    async function tokenYenile() {
        const res = await fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_csrf' })
        });
        const veri = await res.json().catch(function () { return null; });
        if (veri && typeof veri.csrf_token === 'string') { csrfToken = veri.csrf_token; }
    }

    async function apiGonder(govde, tekrar) {
        const res = await fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(Object.assign({ csrf_token: csrfToken }, govde))
        });
        if (res.status === 403 && tekrar !== false) {
            // Oturum süresi dolmuş olabilir: jetonu yenile ve bir kez tekrar dene.
            await tokenYenile();
            return apiGonder(govde, false);
        }
        const veri = await res.json().catch(function () { return null; });
        return { durum: res.status, veri: veri };
    }

    async function gonder(metin, payload) {
        if (mesgul) { return; }
        const welcome = document.getElementById('welcomeBtnContainer');
        if (welcome) { welcome.remove(); }

        metin = (metin || '').trim();
        if (!metin && !payload) { return; }
        if (metin) { appendMessage('user', metin); }

        userInput.value = '';
        userInput.style.height = 'auto';
        mesgulAyarla(true);
        yaziyor(true);

        try {
            const govde = { message: metin };
            if (payload) { govde.payload = payload; }
            const sonuc = await apiGonder(govde);
            yaziyor(false);
            if (!sonuc.veri || typeof sonuc.veri.reply !== 'string') {
                appendMessage('bot', '⚠️ Sistem hatası. Lütfen tekrar dene.', { hata: true });
                return;
            }
            appendMessage('bot', sonuc.veri.reply, { html: true, hata: sonuc.durum >= 400 });
            if (Array.isArray(sonuc.veri.buttons) && sonuc.veri.buttons.length > 0) {
                renderButtons(sonuc.veri.buttons);
            }
        } catch (e) {
            yaziyor(false);
            appendMessage('bot', 'Sunucuya bağlanılamadı. İnternet bağlantını kontrol edip tekrar dener misin?', { hata: true });
        } finally {
            mesgulAyarla(false);
            userInput.focus();
        }
    }

    // ---- Olaylar ----------------------------------------------------------
    userInput.addEventListener('input', function () {
        this.style.height = 'auto';
        if (this.value !== '') { this.style.height = this.scrollHeight + 'px'; }
    });
    userInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            gonder(userInput.value, null);
        }
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        gonder(userInput.value, null);
    });

    const welcomeBtn = document.getElementById('welcomeBtn');
    if (welcomeBtn) {
        welcomeBtn.addEventListener('click', function () {
            gonder(welcomeBtn.dataset.text, welcomeBtn.dataset.payload);
        });
    }

    window.addEventListener('resize', scrollToBottom);

    if (!csrfToken) {
        tokenYenile().catch(function () { /* ilk mesajda 403 → yeniden denenecek */ });
    }
})();
