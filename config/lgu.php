<?php

/*
|--------------------------------------------------------------------------
| LGU identity
|--------------------------------------------------------------------------
|
| Kept in config rather than hardcoded so this system can be handed to another
| municipality without touching Blade templates. The code in particular is the
| leading segment of every tracking number (BGB-MO-2026-08-0001) and must not
| change once real documents exist.
|
*/

return [
    'code' => env('LGU_CODE', 'BGB'),
    'name' => env('LGU_NAME', 'Municipality of Bongabong'),
    'province' => env('LGU_PROVINCE', 'Oriental Mindoro'),
];
