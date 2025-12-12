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
            $sqlSlowLogAll = (int) config('logging.sqlSlowLogAll');
            $sqlSlowLogSelect = (int) config('logging.sqlSlowLogForSelect');
            DB::listen(function (QueryExecuted $query) use ($logStack, $sqlSlowLogAll, $sqlSlowLogSelect) {
                $milliseconds = (int)($query->time * 1000);
                if ($sqlSlowLogAll && $sqlSlowLogAll > $milliseconds) {
                    return;
                }

                if (stripos($query->sql, 'telescope') !== false) {
                    return;
                }
                preg_match('/(UPDATE|DELETE FROM|INSERT INTO|FROM)\s+([\w_]+)/ui', $query->sql, $m);
                $sqlType = $m[1] ?? '';
                $table = $m[2] ?? '';
                if ($sqlType == 'FROM') {
                    $sqlType = 'SELECT';
                    if ($sqlSlowLogSelect && $sqlSlowLogSelect > $milliseconds) {
                        return;
                    }
                } elseif ($sqlType == 'DELETE FROM') {
                    $sqlType = 'DELETE';
                } elseif ($sqlType == 'INSERT INTO') {
                    $sqlType = 'INSERT';
                }

                $bind = '';
                if (env('APP_DEBUG') && count($query->bindings) < 20) {
                    $bind = [];
                    foreach ($query->bindings as $k => $v) {
                        if (is_string($v) && mb_strlen($v) > 512) {
                            $v = '`string(' . mb_strlen($v) . ')`';
                        }
                        $bind[$k] = $v;
                    }
                    $bind = json_encode($query->bindings);
                }

                Log::channel($logStack)->info($query->sql, [
                    'table' => $table,
                    'millisecond' => $milliseconds,
                    'bindings' => $bind,
                    'tag' => 'sql',
                    'sql_type' => $sqlType,
                ]);
            });
        }
    }
}
