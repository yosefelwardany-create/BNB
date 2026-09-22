<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public surfaces
|--------------------------------------------------------------------------
|
| Mounted at /api/public. Nothing here is authenticated with a user account:
| the guest portal identifies a reservation by an unguessable token, and the
| booking engine is open to the internet. Both are therefore rate limited and
| return deliberately narrow representations.
|
*/
