
* [Graylog integration](./docs/Graylog.md)

# Syslog UDP Driver

Add into /config/logging.php

```php
'syslog-udp' => [
    'driver' => 'custom',
    'via'    => \Xakki\LaraLog\Drivers\SyslogUdp::class,
    'host' => env('SYSLOG_JSON_HOST', '127.0.0.1'),
    'port' => (int) env('SYSLOG_JSON_PORT', 5140),
],
```

Recommended use with stack

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['stderr', 'syslog-udp'],
    //'ignore_exceptions' => true,
],
```


