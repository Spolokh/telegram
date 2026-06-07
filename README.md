# Laravel Telegram Bot API Wrapper

[![CI](https://github.com/spolokh/telegram/actions/workflows/ci.yml/badge.svg)](https://github.com/spolokh/telegram/actions)
[![License](https://img.shields.io/github/license/spolokh/telegram)](LICENSE)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/spolokh/telegram.svg)](https://packagist.org/packages/spolokh/telegram)

Лёгкая обёртка над Telegram Bot API для Laravel v. 10/11/12 с использованием `Http::` facade.
Будет развиваться. Минимальная версия PHP 8.2.* . 

## Установка

```bash
composer require spolokh/telegram
```

## Пример использования

Передать сообщение :

```php
<?php
include (__DIR__ . '/vendor/autoload.php');

use Spolokh\Telegram\Telegram;

$config = [
  'apiUrl' => env('TG_APIURL'),
  'apiKey' => env('TG_APIKEY'),
  'chatId' => env('TG_CHATID'),
];

$telegram = (new Telegram($config))->sendMessage('Hallo world!');
```

Поделиться постом :

```php
<?php
include (__DIR__ . '/vendor/autoload.php');

use Spolokh\Telegram\Telegram;

app(Telegram::class)->sendPost(
  public_path('uploads/posts/' . $post->image),
  $post->excerpt,
  buttons: [
    ['text' => '📖 Подробнее',  'url' => route('blog.show', $post)],
    ['text' => '🔗 Поделиться', 'url' => 'https://t.me/share/url?url=' . urlencode(route('blog.show', $post))]
  ]
);
```
