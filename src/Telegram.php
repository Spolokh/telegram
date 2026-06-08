<?php

declare(strict_types=1);

namespace Spolokh\Telegram;

use CURLFile;
use Throwable;
use RuntimeException;
use InvalidArgumentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\{
    RequestException, ConnectionException,
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
        $url = $this->apiUrl . $this->apiKey . '/' . $method;
        try {

            $fields = [];
            $oFiles = false;
            $client = Http::withOptions([
                'timeout' => $this->timeout,
                'verify' => $this->verify,
                'proxy' => $this->buildProxyString()
            ])->retry(3, 100, fn($e) =>
                $e instanceof ConnectionException, throw: false
            );

            foreach($data as $k => $v) {
                if ($v instanceof CURLFile) {
                    $client->attach($k, file_get_contents($v->getFilename()), basename($v->getFilename()));
                    $oFiles = true;
                } else {
                    $fields[$k] = $v;
                }
            }

            $result = $client->when($oFiles, fn($http) =>
                $http->asMultipart()
            )->post($url, $oFiles ? $fields : $data)
                ->throw()
                ->json();

            return ($result['ok'] ?? false) ? ($result['result'] ?? false) : false;

        } catch (Throwable $e) {
            logger()->error('Telegram API failed', [
                'method'  => $method,
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Отправка текстового сообщения
     *
     * @param string $text Текст сообщения
     * @param string|null $chatId ID чата (переопределяет дефолтный)
     * @param string $parseMode Режим парсинга: HTML, Markdown, MarkdownV2
     * @param bool $withPreview Показывать превью ссылок
     * @return array<string, mixed>|false
     */
    public function sendMessage(string $text, ?string $chatId = null, string $parseMode = 'HTML', bool $withPreview = true): array|false
    {
        return $this->apiRequest('sendMessage', $this->setData($chatId, [
			'text' => $text,
			'parse_mode' => $parseMode,
			'disable_web_page_preview' => $withPreview,
			'disable_notification' => false,
			'reply_to_message_id'  => null
		]));
    }

    /**
     * Отправляет документ (файл) в чат
     *
     * @param string $file File ID, URL или CURLFile для загрузки
     * @param string|null $caption Подпись к документу (0-1024 символа)
     * @param string|null $chatId ID чата (переопределяет дефолтный)
     * @param string $parseMode Режим парсинга (HTML или Markdown)
     * @param bool $disableNotification Отправить без уведомления
     * @return array<string, mixed>|false
     */
    public function sendDocument(string $file, ?string $caption = null, ?string $chatId = null, string $parseMode = 'HTML', bool $disableNotification = false): array|false
    {
        return $this->apiRequest('sendDocument', $this->setData($chatId, [
            'document' => $this->curlFile($file),
            'caption' => $caption,
            'parse_mode' => $parseMode,
            'disable_notification' => $disableNotification,
        ]));
    }

     /**
     * Уведомление о новом посте
     * 
     * @param  $file File ID, URL или CURLFile для загрузки
     * @param  string|null $caption
     * @param  string|null $chatId ID чата (переопределяет дефолтный)
     * @param  list<array{text: string, url: string}> $buttons [['text' => '...', 'url' => '...']]
     * @return array<string, mixed>|false
     */
    public function sendPost(string $file, ?string $caption = null, ?string $chatId = null, array $buttons = []): array|false
    {
        $data = [
            'photo'   => $this->curlFile($file),
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if (!empty($buttons)) {
            $keyboard = array_map(fn($btn) => [
                ['text' => $btn['text'], 'url' => $btn['url']]
            ], $buttons);
    
            $data['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }

        return $this->apiRequest('sendPhoto', $this->setData($chatId, $data));
    }

    /**
     * Отправляет фотографию в чат
     *
     * @param string $file File ID, URL или CURLFile для загрузки
     * @param string|null $caption Подпись к фото (0-1024 символа)
     * @param string|null $chatId ID чата (переопределяет дефолтный)
     * @param string $parseMode Режим парсинга (HTML или Markdown)
     * @param bool $disableNotification Отправить без уведомления
     * @return array<string, mixed>|false
     */
    public function sendPhoto(string $file, ?string $caption = null, ?string $chatId = null, string $parseMode = 'HTML', bool $disableNotification = false): array|false 
    {
        return $this->apiRequest('sendPhoto', $this->setData($chatId, [
            'photo' => $this->curlFile($file),
            'caption' => $caption,
            'parse_mode' => $parseMode,
            'disable_notification' => $disableNotification,
        ]));
    }

    /**
     * Отправляет аудиофайл в чат
     *
     * @param string $file File ID, URL или CURLFile
     * @param string|null $caption Подпись (для голосовых сообщений)
     * @param int|null $duration Длительность в секундах
     * @param string|null $performer Исполнитель
     * @param string|null $title Название трека
     * @param string|null $chatId ID чата
     * @param bool $disableNotification Отправить без уведомления
     * @return array<string, mixed>|false
     */
    public function sendAudio(string $file,
        ?string $title = null,
        ?string $caption = null,
        ?string $performer = null,
        ?string $chatId = null, ?int $duration = null, bool $disableNotification = false): array|false
    {
        return $this->apiRequest('sendAudio', $this->setData($chatId, [
            'audio' => $this->curlFile($file),
            'title' => $title,
            'caption' => $caption,
            'duration' => $duration,
            'performer' => $performer,
            'disable_notification' => $disableNotification,
        ]));
    }

    /**
     * Установить вебхук
     * 
     * @param string $url Публичный HTTPS-адрес обработчика
     * @param list<string>|null $allUpdates
     * @param string|null $ipAddress
     * @return array<string, mixed>|false Массив ответа API или false при ошибке
     */
    public function setWebhook(string $url, array $allUpdates = null, ?string $ipAddress = null): array|false
    {
        return $this->apiRequest('setWebhook', [
            'url' => $url,
            'ip_address' => $ipAddress,
            'allowed_updates' => $allUpdates,
            'drop_pending_updates' => true, // Удалить старые обновления при смене
        ]);
    }

    /**
     * Получить информацию о текущем вебхуке
     * 
     * @return	array
	 * @return array<string, mixed>|false Массив ответа API или false при ошибке
     */
    public function getWebhookInfo(): array|false
    {
        return $this->apiRequest('getWebhookInfo');
    }

    /**
     * 
     * Удалить вебхук (вернуться на getUpdates)
     * @return	array
	 * @return array<string, mixed>|false Массив ответа API или false при ошибке
     */
    public function deleteWebhook(): array|false
    {
        return $this->apiRequest('deleteWebhook');
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
     * Отправляет точку на карте (геолокацию) в чат
     *
     * @param float $latitude Широта
     * @param float $longitude Долгота
     * @param string|null $chatId ID чата (переопределяет дефолтный)
     * @param int|null $livePeriod Время в секундах для live-локации (60–86400)
     * @param bool $disableNotification Отправить без уведомления
     * @return array<string, mixed>|false
     */
    public function sendLocation(float $latitude, float $longitude, ?string $chatId = null, ?int $livePeriod = null, bool $disableNotification = false): array|false 
    {
        return $this->apiRequest('sendLocation', $this->setData($chatId, [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'live_period' => $livePeriod,
            'disable_notification' => $disableNotification,
        ]));
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

    /**
     * Подготавливает файл для отправки (URL или локальный путь)
     * @param string $file Путь к файлу или URL
     * @return string|CURLFile
     */
	private function curlFile(string $file): string|CURLFile
	{
		$file = preg_match('/(www)|(http)|(https)/i', $file)
			? filter_var($file, FILTER_VALIDATE_URL)
			: trim($file);
		return realpath($file) ? new CURLFile(realpath($file)) : $file;
	}

    /**
     * Объединяет chatId с дополнительными данными
     *
     * @param string|null $chatId ID чата (переопределяет дефолтный)
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function setData(?string $chatId = null, array $data = []): array
    {
        $chatId ??= $this->chatId;
        return ['chat_id' => $chatId] + $data;
    }
}
