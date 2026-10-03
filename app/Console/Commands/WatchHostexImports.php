<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/** Dedicated inbound clock; never invokes the general/outbound scheduler. */
class WatchHostexImports extends Command
{
    protected $signature = 'channels:watch {--once : Run one due-account check and exit}';

    protected $description = 'Continuously import due Hostex accounts without requiring a browser or cron service.';

    public function handle(): int
    {
        $stopping = false;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$stopping): void {
                $stopping = true;
            });
        }
        do {
            try {
                $this->call('channels:pull', ['--automatic' => true]);
            } catch (Throwable $exception) {
                // Keep the next check alive after a database/network outage;
                // never log credentials or the source response body here.
                $this->error('Automatic Hostex import could not run ('.class_basename($exception).'). Retrying on the next check.');
            }
            if ($this->option('once') || $stopping) {
                break;
            }
            sleep(60);
        } while (! $stopping);

        return self::SUCCESS;
    }
}
