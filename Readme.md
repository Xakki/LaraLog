
# Integration

* [Graylog](./docs/Graylog.md)


# Requires

* php: ^8.3|^8.4
* laravel/framework: ^10|^11
* psr/log: ^3.0


# Install

`composer require xakki/laralog`

# Configure

1. Add `Providers/AppServiceProvider.php`

```php

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ...
        $this->app->singleton('log', fn ($app) => new \Xakki\LaraLog\LogManager($app));
        ...
    }
}
```

or `bootstrap/app.php`

```php
    $app->singleton(
        \Illuminate\Log\LogManager::class,
        \Xakki\LaraLog\LogManager::class
    );
```
### Slow SQL query collect

1. Add `Providers/AppServiceProvider.php`
```php

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ...
        $this->app->register(\Xakki\LaraLog\SqlLogServiceProvider::class);
        ...
    }
}
```
2. Add into config/logging.php
```php
    'dailySqlStack' => env('LOG_DAILY_SQL'),
    'sqlSlowLogMs' => env('SQL_SLOW_LOG', 500),
```


### Syslog UDP channel

1. Add into config/logging.php (channels)
```php
        'syslog-udp' => [
            'driver' => 'monolog',
            'handler'    => \Monolog\Handler\SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('SYSLOG_JSON_HOST', '127.0.0.1'),
                'port' => (int) env('SYSLOG_JSON_PORT', 5140),
                'facility' => LOG_LOCAL0,
                'level' => env('LOG_LEVEL', 'info'),
            ],
            'formatter' => \Xakki\LaraLog\Formatters\CustomFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d\TH:i:s.uP',
            ],
            'processors' => [
                \Xakki\LaraLog\Processor\ExtraProcessor::class, 
                \Monolog\Processor\LoadAverageProcessor::class,
                \Monolog\Processor\WebProcessor::class
            ],
        ],
```

By default, the log size is limited to 1400 characters - a longer log will be lost.


## Json channel

1. Add into config/logging.php (channels)
```php
        'json' => [
            'driver' => 'monolog',
            'handler'    => \Monolog\Handler\StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/laravel.json'),
                'level' => env('LOG_LEVEL', 'info'),
            ],
            'formatter'    => \Monolog\Formatter\JsonFormatter::class,
            'processors' => [
                \Xakki\LaraLog\Processor\ExtraProcessor::class, 
                \Xakki\LaraLog\Processor\LoadAverageProcessor::class,
                \Xakki\LaraLog\Processor\WebProcessor::class
            ],
        ],
```



## Redis channel

1. Add into config/logging.php (channels)
```php
        'redis' => [
            'driver' => 'custom',
            'via'    => \Xakki\LaraLog\Drivers\RedisLogger::class,
            'connection'   => env('LOG_REDIS_CONNECTION', 'default'),
            'level' => env('LOG_LEVEL', 'debug'),
            'capSize' => env('REDIS_LOG_CAP_SIZE', 10000),
            'processors' => [
                \Xakki\LaraLog\Processor\ExtraProcessor::class, 
                \Xakki\LaraLog\Processor\LoadAverageProcessor::class,
                \Xakki\LaraLog\Processor\WebProcessor::class
            ],
        ],
```

### If u want log to another redis connection

1. Add config into config/database.php (redis)
```php
        'log-redis' => [
            'url' => env('LOG_REDIS_URL'),
            'host' => env('LOG_REDIS_HOST', '127.0.0.1'),
            'username' => env('LOG_REDIS_USERNAME'),
            'password' => env('LOG_REDIS_PASSWORD'),
            'port' => env('LOG_REDIS_PORT', '6379'),
            'database' => env('LOG_REDIS_DB', '0'),
            'prefix' => env('LOG_REDIS_PREFIX', 'common:'),
        ],
```

2. Add to .env
```
LOG_REDIS_CONNECTION=log-redis
```
U can add custom  options, like `LOG_REDIS_PREFIX`, `LOG_REDIS_PORT` and etc

## Telegram channel

1. Add into config/logging.php (channels)
```php
        'telegram' => [
            // https://api.telegram.org/bot[BOT_TOKEN]/sendMessage?chat_id=@[USERNAME_CHANNEL]&text=тест
            'driver'  => 'monolog',
            'formatter' => Monolog\Formatter\LineFormatter::class,
            'formatter_with' => [
                'format' => env('APP_ENV') . ". %message%",
//                'format' => "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            ],
            'handler' => \Monolog\Handler\TelegramBotHandler::class,
            'handler_with' => [
                'apiKey' => env('TELEGRAM_API_KEY'),
                'channel' => env('TELEGRAM_CHANNEL'),
                'parseMode' => 'Markdown',
            ],
        ],
```

## Recommended stack

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['stderr', 'syslog-udp'],
    //'ignore_exceptions' => true,
],
```
