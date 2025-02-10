<?php

namespace Xakki\LaraLog;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class SlowQueryLog
{
    public function serviceProviderBoot(): void
    {
        if (env('SQL_SLOW_LOG')) {
            DB::listen(function (QueryExecuted $query) {
                if (stripos($query->sql, 'telescope') !== false) {
                    return;
                }
                if (env('SQL_SLOW_LOG') > $query->time) {
                    return;
                }

                $bind = '';

                if (env('APP_DEBUG') && is_array($query->bindings) && count($query->bindings) < 100) {
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

                logger()?->info($query->sql, [
                    \LOGGER_MCTIME => $query->time / 1000,
                    \LOGGER_MONITORING => 'slow-log',
                    'sqlType' => $sqlType,
                    'bindings' => $bind,
                    'connectionName' => $query->connectionName,
                ]);
            });
        }
    }
}
