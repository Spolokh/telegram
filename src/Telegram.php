<?php

declare(strict_types=1);

namespace Spolokh\Telegram;

use RuntimeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\{
    RequestException,
    ConnectionException,
};

class Telegram
{
    /**
     * @param array<string, mixed> $config   Переопределение config('services.tgbot')
     * @param string|null $apiUrl            Базовый URL API
     * @param string|null $chatId        Чат по умолчанию
     * @param string|null $apiKey            Токен бота
     * @param array<string, mixed>|null $proxys Настройки прокси
     * @param bool|null $verify              Проверка SSL
     * @param int|null $timeout              Таймаут в секундах
     */
    public function __construct(
        private array $config = [], 
        private ?string $apiUrl = null, 
        private ?string $chatId = null, 
        private ?string $apiKey = null,
        private array|string|null $proxys = null,
        private ?bool $verify  = true,
        private ?int  $timeout = 30
    ) {
        $this->apiUrl ??= $this->config['apiUrl'] ?? config('services.tgbot.apiUrl');
		$this->apiKey ??= $this->config['apiKey'] ?? config('services.tgbot.apiKey');
		$this->chatId ??= $this->config['chatId'] ?? config('services.tgbot.chatId');
		$this->proxys ??= $this->config['proxys'] ?? config('services.tgbot.proxys');
        $this->verify ??= $this->config['verify'] ?? config('services.tgbot.verify', true);
        $this->timeout = $this->config['timeout'] ?? config('services.tgbot.timeout', 30);

        if (empty($this->apiKey)) {
            throw new RuntimeException('Telegram Bot API token is required');
        }
    }

    /**
     * Универсальный метод запроса к Telegram API
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|false
     */
    private function apiRequest( string $method, array $data = [] ): array|false
    {
        $request = Http::withOptions([
                'timeout' => $this->timeout,
                'verify'  => $this->verify,
                'proxy'   => $this->buildProxyString()
            ])
            ->retry(3, 100, fn($e) =>
                $e instanceof ConnectionException, throw: false
            );

        try {
            $response = $request->post($this->apiUrl . $this->apiKey . '/' . $method, $data);
            if ($response->failed()) {
                $result = $response->json();
                logger()->warning('HTTP failed', [
                    'method' => $method,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'parsed' => $result,
                ]);

                if (!($result['ok'] ?? false)) {
                    logger()->warning('Telegram API error: ' . ($result['description'] ?? 'Unknown'));
                    return false;
                }
                return false;
            }

            $result = $response->json(); // Если 'result' нет — это ошибка, возвращаем false, а не пустой массив
            if (!isset($result['result'])) {
                logger()->warning("API response missing 'result' key", ['response' => $result]);
                return false;
            }
            return $result['result'];
        } catch(\Throwable $e) {
            logger()->error('Exception in apiRequest', [
                'method' => $method, 'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Отправка текстового сообщения
     *
     * @param string $text Текст сообщения
     * @param string $parseMode Режим парсинга: HTML, Markdown, MarkdownV2
     * @param bool $withPreview Показывать превью ссылок
     * @param string|null $chatId ID чата (переопределяет дефолтный)
     * @return array<string, mixed>|false
     */
    public function sendMessage(string $text, string $parseMode = 'HTML', bool $withPreview = true, string|null $chatId = null): array|false
    {
        /** @var array<string, mixed> $data */
        $data = [
			'chat_id' => $this->chatId,
			'text'	  => $text,
			'parse_mode' => $parseMode,
			'disable_web_page_preview' => $withPreview,
			'disable_notification' => false,
			'reply_to_message_id'  => NULL
		];

        return $this->apiRequest('sendMessage', $data);
    }

    /**
     * Получение информации о боте
     *
     * @return array<string, mixed>|false
     */
    public function getMe(): array|false
    {
        return $this->apiRequest('getMe');
    }

    /**
     * Получение обновлений (long polling)
     *
     * @param array<string, mixed> $params Параметры запроса (offset, limit, timeout, allowed_updates)
     * @return array<int, array<string, mixed>>|false
     */
    public function getUpdates(array $params = []): array|false
    {
        return $this->apiRequest('getUpdates', $params);
    }

    /**
     * Конвертирует настройки прокси в строку для Guzzle
     *
     * @see https://docs.guzzlephp.org/en/stable/request-options.html#proxy
     * @return string|array<string, mixed>|null
     */
    private function buildProxyString(): string|array|null
    {
        $proxy = $this->proxys;
        if (empty($proxy)) {
            return null;
        }
        if (is_string($proxy)) {
            return $proxy;
        }
        if (isset($proxy['http']) || isset($proxy['https'])) {
            return $proxy;
        }

        $scheme = match($proxy['type'] ?? null) {
            0, 'http', 'HTTP' => 'http',
            1, 'https', 'HTTPS' => 'https',
            4, 'socks4', 'SOCKS4' => 'socks4',
            5, 'socks5', 'SOCKS5' => 'socks5',
            7, 'socks5h', 'SOCKS5_HOSTNAME' => 'socks5h',
            default => 'http',
        };

        $host = $proxy['host'] ?? $proxy['url'] ?? null;
        if ($host === null) {
            return null;
        }

        $result = $scheme . '://';
        
        if (!empty($proxy['auth'])) {
            $result.= $proxy['auth'] . '@';
        }
        
        $result.= $host;

        if (isset($proxy['port']) && $proxy['port'] !== '') {
            $result.= ':' . $proxy['port'];
        }
        
        return $result;
    }
}
