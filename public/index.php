<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5, user-scalable=yes">
    <title>MistikAI · OMYO Akıllı Asistan</title>
    <meta name="description" content="MistikAI - Osmaniye Meslek Yüksekokulu akıllı dijital kampüs asistanı. Ders programı, yemekhane menüsü ve akademik takvim bilgilerine anında erişin.">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #0f172a; --bg-panel: #1e293b; --bg-input: #334155;
            --text-main: #f8fafc; --text-muted: #94a3b8; --accent-color: #8b5cf6; 
            --accent-hover: #7c3aed; --user-msg-bg: #8b5cf6; --bot-msg-bg: #1e293b;
            --border-color: #334155; --radius-lg: 1.5rem; --radius-md: 1rem;
            --transition: all 0.3s ease; --imza-renk: #94a3b8;
        }
        body.light-mode {
            --bg-main: #ffffff; --bg-panel: #f1f5f9; --bg-input: #e2e8f0;
            --text-main: #0f172a; --text-muted: #475569; --accent-color: #8b5cf6;
            --accent-hover: #7c3aed; --user-msg-bg: #8b5cf6; --bot-msg-bg: #f1f5f9;
            --border-color: #cbd5e1; --imza-renk: #64748b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-main); color: var(--text-main); height: 100vh; display: flex; flex-direction: column; overflow: hidden; transition: background-color 0.3s, color 0.3s; }
        .main-chat { flex: 1; display: flex; flex-direction: column; width: 100%; height: 100%; position: relative; }
        .top-bar { padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); background-color: var(--bg-main); z-index: 10; flex-shrink: 0; transition: background-color 0.3s, border-color 0.3s; }
        .bot-info { display: flex; align-items: center; gap: 1rem; }
        .bot-avatar { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35); flex-shrink: 0; overflow: hidden; }
        .bot-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .bot-title-area { display: flex; flex-direction: column; justify-content: center; gap: 0.1rem; }
        .bot-title-area h2 { font-size: 1.25rem; font-weight: 600; line-height: 1.2; letter-spacing: -0.5px; }
        .status-indicator { display: flex; align-items: center; gap: 0.4rem; }
        .status-dot { width: 8px; height: 8px; background-color: #10b981; border-radius: 50%; box-shadow: 0 0 6px rgba(16, 185, 129, 0.6); }
        .status-text { font-size: 0.75rem; color: var(--text-muted); font-weight: 500; }
        .theme-btn { background: var(--bg-input); border: 1px solid var(--border-color); color: var(--text-main); width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1.2rem; transition: var(--transition); }
        .theme-btn:hover { background: var(--accent-color); color: white; border-color: var(--accent-color); transform: scale(1.05); }
        .chat-container { flex: 1; overflow-y: auto; padding: 2rem; display: flex; flex-direction: column; gap: 1.5rem; scroll-behavior: smooth; }
        .message-wrapper { display: flex; gap: 1rem; max-width: 800px; width: 100%; margin: 0 auto; animation: fadeIn 0.4s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .message-wrapper.user { flex-direction: row-reverse; }
        .msg-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; }
        .user .msg-avatar { background-color: var(--bg-input); color: var(--text-main); }
        .bot .msg-avatar { background: linear-gradient(135deg, #8b5cf6, #3b82f6); }
        .msg-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .msg-avatar svg { width: 20px; height: 20px; stroke: currentColor; }
        .message-bubble { padding: 1rem 1.2rem; border-radius: var(--radius-md); font-size: 1rem; line-height: 1.5; max-width: 80%; word-break: break-word; }
        .user .message-bubble { background-color: var(--user-msg-bg); color: white; border-top-right-radius: 0; }
        .bot .message-bubble { background-color: var(--bot-msg-bg); border: 1px solid var(--border-color); border-top-left-radius: 0; color: var(--text-main); }
        .chat-buttons-container { display: flex; flex-wrap: wrap; gap: 0.6rem; max-width: 800px; width: 100%; margin: -0.5rem auto 0; padding-left: 46px; animation: fadeIn 0.4s ease; }
        .chat-action-btn { padding: 0.6rem 1.2rem; background-color: transparent; border: 1px solid var(--accent-color); color: var(--text-main); border-radius: 2rem; cursor: pointer; font-size: 0.95rem; font-weight: 500; transition: var(--transition); display: flex; align-items: center; justify-content: center; }
        .chat-action-btn:hover { background-color: var(--accent-color); color: white; box-shadow: 0 4px 10px rgba(139, 92, 246, 0.3); transform: translateY(-2px); }
        .quick-action-btn { background-color: rgba(139, 92, 246, 0.1); border: 1px solid var(--accent-color); color: var(--accent-color); padding: 0.7rem 1.5rem; border-radius: 2rem; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; }
        .quick-action-btn:hover { background-color: var(--accent-color); color: white; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4); }
        .input-area { padding: 1rem 2rem; background: var(--bg-main); border-top: 1px solid var(--border-color); flex-shrink: 0; transition: background-color 0.3s, border-color 0.3s; }
        .input-wrapper { max-width: 800px; margin: 0 auto; background-color: var(--bg-input); border-radius: var(--radius-lg); padding: 0.5rem 1rem; display: flex; align-items: flex-end; gap: 0.8rem; border: 1px solid var(--border-color); transition: var(--transition); }
        .input-wrapper:focus-within { border-color: var(--accent-color); box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.2); }
        .action-btn { background: none; border: none; color: var(--text-muted); font-size: 1.2rem; cursor: pointer; padding: 0.5rem; border-radius: 50%; transition: var(--transition); display: flex; align-items: center; justify-content: center; margin-bottom: 0.2rem; }
        .action-btn:hover { color: var(--text-main); background-color: rgba(255,255,255,0.1); }
        textarea { flex: 1; background: transparent; border: none; color: var(--text-main); font-size: 1rem; resize: none; padding: 0.8rem 0; max-height: 150px; outline: none; line-height: 1.5; }
        textarea::placeholder { color: var(--text-muted); }
        .send-btn { background-color: var(--accent-color); color: white; border: none; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); margin-bottom: 0.3rem; flex-shrink: 0; }
        .send-btn:hover { background-color: var(--accent-hover); transform: scale(1.05); }
        .footer-info { max-width: 800px; margin: 0.5rem auto 0; display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: var(--text-muted); padding: 0 0.5rem; }
        .imza { font-weight: 500; letter-spacing: 0.3px; color: var(--imza-renk); font-style: italic; transition: color 0.3s; }
        .typing-indicator { display: none; gap: 4px; padding: 0.5rem 1rem; }
        .typing-indicator span { width: 8px; height: 8px; background-color: var(--text-muted); border-radius: 50%; animation: bounce 1.4s infinite ease-in-out both; }
        .typing-indicator span:nth-child(1) { animation-delay: -0.32s; }
        .typing-indicator span:nth-child(2) { animation-delay: -0.16s; }
        @keyframes bounce { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
        @media (max-width: 768px) {
            .top-bar { padding: 0.8rem 1rem; } .chat-container { padding: 1rem; gap: 1rem; } .message-wrapper { gap: 0.6rem; max-width: 100%; } .message-bubble { max-width: 85%; font-size: 0.95rem; padding: 0.8rem 1rem; } .input-area { padding: 0.8rem 1rem; } .input-wrapper { padding: 0.3rem 0.8rem; gap: 0.5rem; } .action-btn { font-size: 1.1rem; padding: 0.3rem; } .chat-buttons-container { padding-left: 42px; gap: 0.4rem; } .chat-action-btn { padding: 0.5rem 1rem; font-size: 0.9rem; } .footer-info { flex-direction: column; gap: 0.3rem; align-items: flex-start; text-align: left;} .imza { align-self: flex-end; }
        }
    </style>
</head>
<body>
    <main class="main-chat">
        <header class="top-bar">
            <div class="bot-info">
                <div class="bot-avatar">
                    <img src="assets/mistik.gif" alt="MistikAI" onerror="this.src='https://via.placeholder.com/44/8b5cf6/ffffff?text=M'">
                </div>
                <div class="bot-title-area">
                    <h2>MistikAI</h2>
                    <div class="status-indicator">
                        <span class="status-dot"></span>
                        <span class="status-text">Sistem Aktif</span>
                    </div>
                </div>
            </div>
            <button class="theme-btn" id="themeToggle" title="Tema değiştir"><i class="fas fa-moon"></i></button>
        </header>
        <div class="chat-container" id="chatBox">
            <div class="message-wrapper bot">
                <div class="msg-avatar"><img src="assets/mistik.gif" alt="MistikAI" onerror="this.src='https://via.placeholder.com/36/8b5cf6/ffffff?text=M'"></div>
                <div class="message-bubble">
                    Merhaba! Ben <strong>MistikAI</strong>, OMYO dijital kampüs asistanıyım.<br><br>
                    Ders programı, yemekhane menüsü veya kampüs ile ilgili diğer konularda size yardımcı olabilirim. Ne sormak istersiniz?
                </div>
            </div>
            <div class="chat-buttons-container" id="welcomeBtnContainer">
                <button class="quick-action-btn" onclick="document.getElementById('welcomeBtnContainer').style.display='none'; window.sendMessage('Neler yapabilirsin?', 'flow_ana_menu', true);">
                    <i class="fas fa-magic"></i> Neler yapabilirsin?
                </button>
            </div>
            <div class="message-wrapper bot" id="typingIndicator" style="display: none;">
                <div class="msg-avatar"><img src="assets/mistik.gif" alt="MistikAI" onerror="this.src='https://via.placeholder.com/36/8b5cf6/ffffff?text=M'"></div>
                <div class="message-bubble" style="padding: 0.8rem 1.2rem;">
                    <div class="typing-indicator" style="display: flex;"><span></span><span></span><span></span></div>
                </div>
            </div>
        </div>
        <div class="input-area">
            <div class="input-wrapper">
                <button class="action-btn" title="Dosya Ekle"><i class="fas fa-paperclip"></i></button>
                <textarea id="userInput" rows="1" placeholder="MistikAI'ye bir şeyler sor... (Örn: Bugün dersim var mı?)"></textarea>
                <button class="action-btn" title="Sesli Komut"><i class="fas fa-microphone"></i></button>
                <button class="send-btn" id="sendBtn"><i class="fas fa-paper-plane"></i></button>
            </div>
            <div class="footer-info">
                <span>MistikAI beta aşamasındadır. Resmi işlemler için Öğrenci İşlerini kullanın.</span>
                <span class="imza">Created by Pasha</span>
            </div>
        </div>
    </main>
    <script>
        (function() {
            const userInput = document.getElementById('userInput');
            const sendBtn = document.getElementById('sendBtn');
            const chatBox = document.getElementById('chatBox');
            const typingIndicator = document.getElementById('typingIndicator');
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = themeToggle.querySelector('i');
            let csrfToken = '';
            window.addEventListener('DOMContentLoaded', async () => {
                try {
                    const res = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'get_csrf' }) });
                    const rawText = await res.text();
                    try { const data = JSON.parse(rawText); if(data.csrf_token) csrfToken = data.csrf_token; } catch (e) { appendMessage('bot', `<span style="color:#ff7b72;">⚠️ Connection Error</span>`); }
                } catch(e) { console.error('Fetch Failed:', e); }
            });
            function setTheme(theme) {
                if (theme === 'light') { document.body.classList.add('light-mode'); themeIcon.className = 'fas fa-sun'; localStorage.setItem('mistikai-theme', 'light'); }
                else { document.body.classList.remove('light-mode'); themeIcon.className = 'fas fa-moon'; localStorage.setItem('mistikai-theme', 'dark'); }
            }
            setTheme(localStorage.getItem('mistikai-theme') || 'dark');
            themeToggle.addEventListener('click', () => setTheme(document.body.classList.contains('light-mode') ? 'dark' : 'light'));
            userInput.addEventListener('input', function() { this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'; if (this.value === '') this.style.height = 'auto'; });
            window.sendMessage = async function(text = null, payload = null, isSilent = false) {
                const welcomeContainer = document.getElementById('welcomeBtnContainer');
                if (welcomeContainer) welcomeContainer.style.display = 'none';
                const messageText = text || userInput.value.trim();
                if (!messageText && !payload) return;
                if (!isSilent && messageText) appendMessage('user', messageText);
                userInput.value = ''; userInput.style.height = 'auto';
                typingIndicator.style.display = 'flex'; chatBox.appendChild(typingIndicator); scrollToBottom();
                const apiKodu = payload !== null ? payload : messageText;
                try {
                    const response = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: messageText, payload: apiKodu, csrf_token: csrfToken }) });
                    const rawText = await response.text();
                    let data;
                    try { data = JSON.parse(rawText); } catch (parseError) { typingIndicator.style.display = 'none'; appendMessage('bot', `<span style="color:#ff7b72;">⚠️ System error.</span>`); return; }
                    typingIndicator.style.display = 'none';
                    appendMessage('bot', data.reply);
                    if (data.buttons && data.buttons.length > 0) renderButtons(data.buttons);
                } catch (error) { typingIndicator.style.display = 'none'; appendMessage('bot', '<span style="color:#ff7b72;">Server connection failed.</span>'); }
            };
            function renderButtons(buttons) {
                const btnContainer = document.createElement('div'); btnContainer.className = 'chat-buttons-container';
                buttons.forEach(btn => {
                    const buttonEl = document.createElement('button'); buttonEl.className = 'chat-action-btn'; buttonEl.innerHTML = btn.text;
                    buttonEl.addEventListener('click', () => {
                        btnContainer.style.display = 'none';
                        if (btn.payload && (btn.payload.startsWith('http://') || btn.payload.startsWith('https://'))) { window.open(btn.payload, '_blank'); return; }
                        window.sendMessage(btn.text, btn.payload, true);
                    });
                    btnContainer.appendChild(buttonEl);
                });
                chatBox.insertBefore(btnContainer, typingIndicator); scrollToBottom();
            }
            function appendMessage(sender, text) {
                const wrapper = document.createElement('div'); wrapper.className = `message-wrapper ${sender}`;
                const userAvatarSVG = `<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`;
                const botAvatarGIF = `<img src="assets/mistik.gif" alt="MistikAI" onerror="this.src='https://via.placeholder.com/36/8b5cf6/ffffff?text=M'">`;
                const icon = sender === 'user' ? userAvatarSVG : botAvatarGIF;
                wrapper.innerHTML = `<div class="msg-avatar">${icon}</div><div class="message-bubble">${text}</div>`;
                chatBox.insertBefore(wrapper, typingIndicator); scrollToBottom();
            }
            function scrollToBottom() { chatBox.scrollTop = chatBox.scrollHeight; }
            userInput.addEventListener('keydown', function(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); window.sendMessage(); } });
            sendBtn.addEventListener('click', () => window.sendMessage());
            window.addEventListener('resize', scrollToBottom);
        })();
    </script>
</body>
</html>
