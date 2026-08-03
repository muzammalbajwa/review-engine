<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Google Business Profile connect flow (.claude/ROADMAP.md Phase 3).
    // redirect must be registered exactly in the Google Cloud Console —
    // this points at the backend API, not the frontend, since the
    // authorization code exchange happens server-side.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Claude API — compliance checks + review reply drafting
    // (.claude/CLAUDE.md, .claude/ROADMAP.md Phase 3 Step 3).
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    ],

    // .claude/QUEUE.md: "Heartbeat alert if the worker dies... This alert
    // ships in Phase 2, not later." Blank is safe (CheckWorkerHeartbeat
    // still logs critical) but means nobody actually gets paged.
    'ops' => [
        'alert_email' => env('OPS_ALERT_EMAIL'),
    ],

];
