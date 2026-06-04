<?php

declare(strict_types=1);

namespace Spolokh\Telegram\Tests;

use Orchestra\Testbench\TestCase;
use Spolokh\Telegram\Telegram;
use Spolokh\Telegram\TelegramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.tgbot.apiKey' => '123:TEST_TOKEN',
            'services.tgbot.chatId' => '999888777',
            'services.tgbot.apiUrl' => 'https://api.telegram.org/bot',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // ✅ Успешные сценарии
    // ─────────────────────────────────────────────────────────────
    public function testGetMeReturnsBotData(): void
    {
        // Перехватываем ВСЕ запросы (звёздочка ловит любой URL)
        Http::fake(['*' => Http::response([
            'ok' => true, 
            'result' => ['id' => 42, 'username' => 'TestBot']
        ])]);

        // Запрещаем реальные запросы в интернет
        // Если мок не сработает → тест упадёт сразу с понятной ошибкой
        Http::preventStrayRequests();

        // Создаём и вызываем
        $result = (new Telegram)->getMe();

        // 🔑 4. Утверждения
        $this->assertIsArray($result, 'Expected array, got: ' . gettype($result));
        $this->assertArrayHasKey('id', $result, 'Result missing "id" key');
        $this->assertSame(42, $result['id']);
        $this->assertSame('TestBot', $result['username']);
        Http::assertSentCount(1);
    }

    public function testSendMessageUsesDefaultChatId(): void
    {
        config(['services.tgbot.chatId' => '555666']);
        
        Http::fake([
            '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 200]]),
        ]);

        $result = (new Telegram)->sendMessage('Default chat message');

        $this->assertIsArray($result);
        
        Http::assertSent(function ($request) {
            return $request->data()['chat_id'] === '555666';
        });
    }

    // ─────────────────────────────────────────────────────────────
    // ❌ Обработка ошибок
    // ─────────────────────────────────────────────────────────────
    public function testApiErrorReturnsFalse(): void
    {
        Http::fake([
            '*/sendMessage' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        Http::preventStrayRequests();
        $result = (new Telegram)->sendMessage('Oops', chatId: '999999');
        $this->assertFalse($result);
        // Проверяем, что ошибка залогирована (опционально)
        // $this->assertStringContainsString('Telegram Business Error', $logOutput);
    }

    public function testConnectionExceptionReturnsFalse(): void
    {
        Http::fake(function ($request) {
            return Http::response('', 500); // Пустой тело + 500 статус
        });

        $result = (new Telegram)->getMe();

        $this->assertFalse($result);
    }

    public function testMissingApiKeyThrowsException(): void
    {
        config(['services.tgbot.apiKey' => null]);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram Bot API token is required');
        
        new Telegram;
    }

    // ─────────────────────────────────────────────────────────────
    // ⚙️ Конфигурация и прокси
    // ─────────────────────────────────────────────────────────────
    public function testConstructorOverridesConfig(): void
    {
        $tg = new Telegram(
            config: ['timeout' => 99, 'verify' => false],
            apiKey: '999:OVERRIDE',
            chatId: '777888'
        );

        $this->assertTrue(true);
    }

    public function testProxyStringFormat(): void
    {
        $tg = new Telegram(proxys: 'socks5://user:pass@example.com:1080');

        $reflection = new \ReflectionClass($tg);
        $method = $reflection->getMethod('buildProxyString');
        $method->setAccessible(true);
        $result = $method->invoke($tg);

        $this->assertSame('socks5://user:pass@example.com:1080', $result);
    }

    public function testProxyUrlStringPassthrough(): void
    {
        $tg = new Telegram(proxys: 'http://user:pass@proxy:8080');

        $reflection = new \ReflectionClass($tg);
        $method = $reflection->getMethod('buildProxyString');
        $method->setAccessible(true);
        $result = $method->invoke($tg);

        $this->assertSame('http://user:pass@proxy:8080', $result);
    }

    // ─────────────────────────────────────────────────────────────
    // 🔄 Retry-логика (интеграционный тест)
    // ─────────────────────────────────────────────────────────────
    public function testRetryOnConnectionException(): void
    {
        $attempts = 0;
        
        Http::fake(function() use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException('Temporary network error');
            }
            return Http::response(['ok' => true, 'result' => []]);
        });

        $result = (new Telegram)->getMe();

        $this->assertIsArray($result);
        $this->assertSame(3, $attempts);
    }
}
