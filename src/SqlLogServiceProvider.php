<?php

namespace Xakki\LaraLog;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class SqlLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($logStack = config('logging.dailySqlStack')) {
            $sqlSlowLogMs = (int) config('logging.sqlSlowLogMs');
            DB::listen(function (QueryExecuted $query) use ($logStack, $sqlSlowLogMs) {
                $mcTime = (int)($query->time * 1000);
                if ($sqlSlowLogMs && $sqlSlowLogMs > $mcTime) {
                    return;
                }

                if (stripos($query->sql, 'telescope') !== false) {
                    return;
                }

                $bind = '';
                if (env('APP_DEBUG') && is_array($query->bindings) && count($query->bindings) < 20) {
                    $bind = [];
                    foreach ($query->bindings as $k => $v) {
                        if (is_string($v) && mb_strlen($v) > 512) {
                            $v = '`string(' . mb_strlen($v) . ')`';
                        }
                        $bind[$k] = $v;
                    }
                    $bind = json_encode($query->bindings);
                }

                $sqlType = 'SELECT';
                if (stripos($query->sql, 'insert ') === 0) {
                    $sqlType = 'INSERT';
                } elseif (stripos($query->sql, 'delete ') === 0) {
                    $sqlType = 'DELETE';
                } elseif (stripos($query->sql, 'update ') === 0) {
                    $sqlType = 'UPDATE';
                }
                Log::channel($logStack)->info($query->sql, [
                    'ms_time' => $mcTime,
                    'bindings' => $bind,
                    'tag' => 'sql',
                    'sqlType' => $sqlType,
                ]);
            });
        }
    }
}
