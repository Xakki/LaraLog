<?php declare(strict_types=1);

namespace Xakki\LaraLogTests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = new Application(
            $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
        );

        $app->singleton(
            \Illuminate\Log\LogManager::class,
            \Xakki\LaraLog\LogManager::class
        );

        $app->bind(
            Kernel::class,
            \Illuminate\Foundation\Console\Kernel::class
        );

        $app->make(Kernel::class)->bootstrap();

        if (isset($_SERVER['XDEBUG_SESSION'])) {
            $this->withUnencryptedCookie('XDEBUG_SESSION', $_SERVER['XDEBUG_SESSION']);
        }

        return $app;
    }

    protected function getLogPath(): string
    {
        return realpath(__DIR__ . '/../bootstrap/cache') . '/single.log';
    }

    protected function clearLog(): void
    {
        $f = $this->getLogPath();
        if (file_exists($f)) {
            file_put_contents($f, '');
        }
    }

    protected function getLog(): string
    {
        $f = $this->getLogPath();
        if (file_exists($f)) {
            return file_get_contents($f);
        }
        return '';
    }


}
