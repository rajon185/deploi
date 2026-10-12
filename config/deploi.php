<?php

return [
    // Base URL used for deploy webhooks. Must be reachable by GitHub/GitLab/Bitbucket.
    'webhook_url' => env('DEPLOI_WEBHOOK_URL', env('APP_URL')),

    // Connect timeout (seconds) for interactive SSH actions.
    'ssh_timeout' => (int) env('DEPLOI_SSH_TIMEOUT', 15),

    // Registration is closed once the first (owner) account exists unless this is true.
    'allow_registration' => (bool) env('DEPLOI_ALLOW_REGISTRATION', false),
];
