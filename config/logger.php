<?php

declare(strict_types=1);

/**
 * LaraLog configuration. Publish with:
 *   php artisan vendor:publish --tag=laralog-config
 *
 * Reading env() HERE (not at log-time) is deliberate: values are captured into the
 * compiled config by `php artisan config:cache`, so they survive in production —
 * unlike env() called from runtime code, which returns null once config is cached.
 */
return [

    // Max message length (chars) kept on the log record.
    'message_limit' => (int) env('LOG_MESSAGE_LIMIT', 3024),

    // Attach memory_get_usage()/peak to every record.
    'allow_memory' => (bool) env('LOG_ALLOW_MEMORY', false),

    // ---- Stable-per-process fields (spec §4.2 extra). ExtraProcessor dumps this whole
    //      array onto every record (empty values dropped). Add your own keys freely. ----
    'extra' => [
        'app_name'       => env('APP_NAME'),
        'app_env'        => env('APP_ENV'),
        'app_ver'        => env('APP_VERSION'),
        'log_ver'        => LOGGER_VER,
        'release_tag'    => env('RELEASE_TAG'),
        'release_time'   => env('RELEASE_TIME'),
    ],

    // ---- Stack trace (spec §3.7 / §4.6) ----
    'trace' => [
        // Frames whose path contains any of these are stripped from file/trace.
        'excluded_partials' => ['Monolog', 'Illuminate/Log/', 'vendor/'],
        // Trace depth (frames) per level; trace is attached at >= warning.
        'depth' => [
            'warning'  => 5,
            'error'    => 10,
            'critical' => 20, // also alert/emergency
        ],
        // Max chars per stringified trace argument.
        'arg_limit' => 128,
    ],

    // ---- Credential redaction (spec §2 / §2.1) ----
    // Built-in needles are ALWAYS applied; this list is MERGED on top (case-insensitive,
    // substring match against the key/argument name). Values matched are replaced with ***.
    'redact' => [
        // 'x_internal_token', ...
    ],

    // ---- Field naming (spec §4.7) ----
    // When true, context keys are lowercased + snake_cased. Default off = no breaking change.
    'snake_case' => (bool) env('LOG_SNAKE_CASE', false),

    // ---- log_type classification (spec §4.3.1, Option A) ----
    // When true, LaraLogServiceProvider installs error/exception/shutdown handlers
    // (chained to the framework's) so logs get log_type = trigger|exception|fatal.
    // OFF by default: installing global handlers can interfere with the host app.
    'capture_handlers' => (bool) env('LOG_CAPTURE_HANDLERS', false),
];
