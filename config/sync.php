<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance Identity
    |--------------------------------------------------------------------------
    |
    | The central installation owns the authoritative data. An offline
    | installation uses its own LOCAL_SERVER_UUID and records that UUID as the
    | origin of locally created rows. The fallback UUID is only for tests and
    | fresh local setup; production central servers must set their own value.
    |
    */

    'instance_mode' => env('INSTANCE_MODE', 'online'),

    'central_server_uuid' => env('CENTRAL_SERVER_UUID', '00000000-0000-4000-8000-000000000001'),

    'local_server_uuid' => env('LOCAL_SERVER_UUID'),

    /*
    |--------------------------------------------------------------------------
    | Protocol Compatibility
    |--------------------------------------------------------------------------
    */

    'protocol_version' => (int) env('SYNC_PROTOCOL_VERSION', 1),

    'minimum_protocol_version' => (int) env('SYNC_MIN_PROTOCOL_VERSION', 1),

    'schema_version' => (int) env('SYNC_SCHEMA_VERSION', 1),

    'minimum_schema_version' => (int) env('SYNC_MIN_SCHEMA_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Enrollment and Server Tokens
    |--------------------------------------------------------------------------
    */

    'enrollment_token_ttl_minutes' => (int) env('SYNC_ENROLLMENT_TOKEN_TTL_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Installation Authorization Period
    |--------------------------------------------------------------------------
    |
    | The authorization period is always set explicitly by an administrator when
    | an enrollment or reauthorization token is issued. These bounds only
    | validate the administrator-supplied value.
    |
    */

    'authorization_min_days' => (int) env('SYNC_AUTHORIZATION_MIN_DAYS', 1),

    'authorization_max_days' => (int) env('SYNC_AUTHORIZATION_MAX_DAYS', 3650),

    'authorization_clock_skew_minutes' => (int) env('SYNC_AUTHORIZATION_CLOCK_SKEW_MINUTES', 5),

    'allow_upload_after_expiry' => (bool) env('SYNC_ALLOW_UPLOAD_AFTER_EXPIRY', false),

    'allow_download_after_expiry' => (bool) env('SYNC_ALLOW_DOWNLOAD_AFTER_EXPIRY', false),

    /*
    |--------------------------------------------------------------------------
    | Trusted Central Server Bootstrap
    |--------------------------------------------------------------------------
    |
    | The enrollment token proves the device is allowed to join, but it cannot
    | by itself identify which server to talk to. The bootstrap URL is the
    | deployment-level trusted address of the Central Server. It is written
    | into the installation package's .env.example when the package is built,
    | so the installer never types a Central Server URL per device. Onboarding
    | verifies the server identity through /api/sync/v1/compatibility before
    | accepting it.
    |
    */

    'bootstrap_url' => env('SYNC_BOOTSTRAP_URL'),

    'token_prefix' => env('SYNC_TOKEN_PREFIX', 'tims_sync_'),

    'server_token_header' => env('SYNC_SERVER_TOKEN_HEADER', 'X-TIMS-Server-Token'),

    'protocol_header' => 'X-TIMS-Protocol-Version',

    'schema_header' => 'X-TIMS-Schema-Version',

    'token_last_used_throttle_seconds' => (int) env('SYNC_TOKEN_LAST_USED_THROTTLE', 60),

    /*
    |--------------------------------------------------------------------------
    | Snapshot and Delta Limits
    |--------------------------------------------------------------------------
    */

    'snapshot_page_size' => (int) env('SYNC_SNAPSHOT_PAGE_SIZE', 500),

    'delta_page_size' => (int) env('SYNC_DELTA_PAGE_SIZE', 200),

    'upload_batch_size' => (int) env('SYNC_UPLOAD_BATCH_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Offline Installation Package
    |--------------------------------------------------------------------------
    |
    | The package contains the application code, the Composer dependencies, and
    | the setup scripts. Credentials, logs, backups, and generated caches are
    | always excluded, and the .env file is never copied.
    |
    */

    'installation_package' => [
        'directory' => env('SYNC_INSTALLATION_PACKAGE_DIRECTORY'),

        'directories' => [
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
            'scripts',
            'vendor',
        ],

        'files' => [
            'artisan',
            'composer.json',
            'composer.lock',
            '.env.example',
        ],

        'max_packages' => (int) env('SYNC_INSTALLATION_PACKAGE_LIMIT', 10),

        /**
         * Build the archive in a detached background process so the browser is
         * not held open for the whole build. When disabled the archive is
         * written inside the request that asked for it.
         */
        'background' => (bool) env('SYNC_INSTALLATION_PACKAGE_BACKGROUND', true),

        /**
         * Whether to include vendor/ in the package. If false, the target device
         * must run `composer install --no-dev` after extraction.
         */
        'include_vendor' => (bool) env('SYNC_INSTALLATION_PACKAGE_INCLUDE_VENDOR', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Application Updates
    |--------------------------------------------------------------------------
    |
    | Where the offline updater looks for PHP, MySQL, and the project root when
    | it applies a published package. Defaults match a standard XAMPP install.
    |
    */

    'xampp_root' => env('XAMPP_ROOT', 'C:\\xampp'),

];
