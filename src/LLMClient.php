<?php
// src/LLMClient.php - LLM Sohbet İstemcisi (Groq / Llama)
// Rule-Based ve BERT başarısız olduğunda doğal sohbet için kullanılır.
// Ayrıca LLM'in [MODULE:x] veya [FLOW:x] komutu döndürmesi durumunda modül yönlendirmesi yapar.

class LLMClient {
    private string $apiKey;
    private string $model;
    private string $apiUrl;
    private int $timeout;

    public function __construct(string $apiKey, string $model = 'llama-3.1-8b-instant', int $timeout = 8) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->apiUrl = 'https://api.groq.com/openai/v1/chat/completions';
        $this->timeout = $timeout;
    }

    /**
     * LLM'e sohbet isteği gönder.
     * 
     * @param string $userInput Kullanıcı mesajı
     * @param array $chatHistory Önceki sohbet geçmişi
     * @param array $availableModules Mevcut modül listesi
     * @return array ['reply' => string, 'command' => string|null, 'command_type' => 'module'|'flow'|null]
     */
    public function chat(string $userInput, array $chatHistory, array $availableModules): array {
        // Modül açıklamalarını hazırla
        $moduleDescription = "";
        foreach ($availableModules as $m) {
            $moduleDescription .= "- [MODULE:" . $m['modul_kodu'] . "] -> " . $m['modul_adi'] . "\n";
        }

        $systemPrompt = "Adın: MistikAI. Sen Osmaniye Meslek Yüksekokulu (OMYO) resmi dijital kampüs asistanısın.
        Kullanıcı 'selam', 'merhaba' gibi girişler yaptığında sadece kısa, sıcak bir şekilde selam al ve OMYO öğrencilerine nasıl yardımcı olabileceğini sor. Asla lafı uzatma.
        Kullanıcı küfür veya hakaret ederse seviyeyi düşürmeden nazikçe uyar.

        KURAL 1: Kullanıcı belirli bir hizmet istiyorsa, Modül gereken yerde SADECE VE SADECE [MODULE:kod] yaz. Yetenekler için [FLOW:ana_menu] yaz. Başka hiçbir şey yazma.
        KURAL 2: Sohbet ediyorsan kısa, samimi ve Türkçe cevap ver.
        MODÜLLER:\n$moduleDescription";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        
        // Sohbet geçmişini ekle
        foreach ($chatHistory as $h) {
            $messages[] = $h;
        }
        $messages[] = ['role' => 'user', 'content' => $userInput];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => 0.4
            ]),
            CURLOPT_TIMEOUT    => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            return ['reply' => 'Üzgünüm, şu an bağlantı kuramıyorum.', 'command' => null, 'command_type' => null];
        }

        $result = json_decode($response, true);
        $aiReply = $result['choices'][0]['message']['content'] ?? 'Üzgünüm, şu an bağlantı kuramıyorum.';

        // <think> etiketlerini temizle (bazı modellerde oluşabiliyor)
        $aiReply = preg_replace('/<think>.*?<\/think>/s', '', $aiReply);

        // Komut algılama
        $command = null;
        $commandType = null;

        if (preg_match('/\[FLOW:([a-z0-9_]+)\]/', $aiReply, $matches)) {
            $command = $matches[1];
            $commandType = 'flow';
        } elseif (preg_match('/\[MODULE:([a-z0-9_]+)\]/', $aiReply, $matches)) {
            $command = $matches[1];
            $commandType = 'module';
        }

        return [
            'reply'        => $aiReply,
            'command'      => $command,
            'command_type' => $commandType
        ];
    }
}
