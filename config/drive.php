<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload size
    |--------------------------------------------------------------------------
    |
    | Megabytes. A scanned twenty-page memorandum is a few MB; a scanned
    | ordinance with annexes can reach thirty. Fifty leaves room without
    | inviting somebody to park a video here.
    |
    | This is only the application's limit. PHP has its own, and PHP's wins
    | silently — an upload larger than post_max_size never reaches Laravel at
    | all, so the user sees an empty form rather than an error. Set both on the
    | host, and set them higher than this number:
    |
    |     upload_max_filesize = 64M
    |     post_max_size       = 64M
    |
    */

    'max_upload_mb' => (int) env('DRIVE_MAX_UPLOAD_MB', 50),

    /*
    |--------------------------------------------------------------------------
    | What may be uploaded
    |--------------------------------------------------------------------------
    |
    | A whitelist, not a blacklist. Anything not named here is refused.
    |
    | Notable absences, both deliberate:
    |
    |   svg   is XML that browsers execute. Displaying one inline would be a
    |         stored cross-site-scripting hole, and an LGU records office has
    |         no need for vector graphics.
    |   html  same reasoning, and there is no legitimate reason for a web page
    |         to be filed as an official record.
    |
    | Executables are absent for the obvious reason. Note also that stored files
    | are written without an extension (see FileStorageService) so that even a
    | misconfigured web root cannot be talked into running one.
    |
    */

    'allowed_extensions' => [
        'pdf',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'odt', 'ods', 'odp',
        'txt', 'csv', 'rtf',
        'zip',
    ],

    /*
    |--------------------------------------------------------------------------
    | What may be shown in the browser
    |--------------------------------------------------------------------------
    |
    | Everything else downloads. Content-Disposition: inline hands the file to
    | whatever the browser does with that type, so this list is limited to the
    | two it handles in a sandbox: its PDF viewer and its image decoder.
    |
    */

    'previewable_mimes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ],

];
