<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Agents\Services\DeferredAgent;
use Illuminate\Console\Command;

/**
 * Closes callbacks nobody answered in time.
 *
 * Two things go wrong without this, and the second is the serious one. A screen
 * keeps saying "waiting" for a bot that was switched off on Tuesday — and a
 * pending ask holds a live callback token, which is a key to a write sitting in
 * a system nobody is watching.
 *
 * Nothing is deleted. The question, who asked it and why it was closed all stay
 * on the record, because "the bot never answered" is exactly the thing somebody
 * will want to look up later.
 */
class ExpireAgentAsks extends Command
{
    protected $signature = 'agents:expire-asks';

    protected $description = 'Close agent questions whose callback window has passed.';

    public function handle(DeferredAgent $agent): int
    {
        $closed = $agent->expireLapsed();

        $this->info($closed === 0
            ? 'No asks had lapsed.'
            : sprintf('Closed %d ask%s nobody answered in time.', $closed, $closed === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
