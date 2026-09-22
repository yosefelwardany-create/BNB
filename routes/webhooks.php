<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inbound webhooks
|--------------------------------------------------------------------------
|
| Mounted at /webhooks. Each provider verifies its own signature inside the
| controller before anything is trusted; there is no shared authentication
| middleware because every provider signs differently.
|
*/
