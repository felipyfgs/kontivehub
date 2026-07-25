<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Onboarding inicial da plataforma
    |--------------------------------------------------------------------------
    |
    | Default OFF. O token deve ter alta entropia e nunca deve ser exposto no
    | runtimeConfig/bundle do frontend, logs ou persistência.
    |
    */
    // filter_var: string "false" (Docker/env) NÃO pode habilitar onboarding.
    'enabled' => filter_var(env('INITIAL_ONBOARDING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'token' => (string) env('INITIAL_ONBOARDING_TOKEN', ''),
];
