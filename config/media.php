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

    /*
    |---------------------------------------------------------------------
    | How many files one listing may carry
    |---------------------------------------------------------------------
    |
    | Without a cap here a single seller can fill the disk, and the disk is
    | shared with the database. The per-file size limits in
    | ListingMediaController bound one upload; these bound the listing.
    |
    | The worst case is what these numbers are chosen against, not the
    | typical case. At the per-file maximums that is roughly:
    |
    |     12 images x  8MB  =  96MB
    |      3 audio  x 20MB  =  60MB
    |      2 video  x 50MB  = 100MB
    |                        -------
    |                         256MB per listing, absolute worst case
    |
    | A realistic listing of ten 3MB photos is nearer 30MB, so the 100GB
    | disk holds thousands. The cap exists so that the arithmetic has a
    | ceiling at all, not because the ceiling is expected to be reached.
    |
    | Raise them if sellers complain; that is a better problem than a full
    | disk taking the site down.
    |
    */

    'limits' => [
        'IMAGE' => 12,
        'AUDIO' => 3,
        'VIDEO' => 2,
    ],

];
