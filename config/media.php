<?php

return [

    /*
    |---------------------------------------------------------------------
    | Disk
    |---------------------------------------------------------------------
    |
    | Where uploaded listing photos, audio and video are kept.
    |
    | The default is the local public disk, so a fresh clone works with no
    | cloud account. Anywhere with a container filesystem this must point at
    | object storage instead: local disk does not survive a restart, and a
    | seller's photos disappearing on the next deploy is data loss the app
    | has no way to detect.
    |
    | Any configured disk works. The s3 driver covers both S3 and Google
    | Cloud Storage, the latter through its S3 compatible endpoint.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

];
