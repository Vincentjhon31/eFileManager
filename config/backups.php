<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many backups of each type (database, files) to keep on disk. Older
    | ones are deleted automatically the moment a newer one of the same type
    | finishes — there is no scheduled job, since a Hostinger-style shared host
    | has no persistent process to run one. A small number on purpose: these
    | live on the same disk as the application, so backups are a safety net for
    | "I broke a table" and "I fat-fingered a delete", not a substitute for
    | downloading a copy somewhere else entirely.
    |
    */

    'keep_per_type' => (int) env('BACKUPS_KEEP_PER_TYPE', 5),

];
