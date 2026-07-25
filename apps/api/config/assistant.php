<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Assistente de produto (fail-closed)
    |--------------------------------------------------------------------------
    |
    | Default OFF. Sem OPENAI_API_KEY configurada o assistente permanece
    | indisponível mesmo com ASSISTANT_ENABLED=true.
    |
    */
    'enabled' => filter_var(env('ASSISTANT_ENABLED', false), FILTER_VALIDATE_BOOL),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('ASSISTANT_MODEL', env('OPENAI_ASSISTANT_MODEL', 'gpt-4o-mini')),
        'timeout_seconds' => (int) env('ASSISTANT_OPENAI_TIMEOUT_SECONDS', 60),
    ],

    'system_prompt' => env(
        'ASSISTANT_SYSTEM_PROMPT',
        'Você é o assistente de produto do KontiveHub. Ajude membros do escritório a consultar modelos de processo, departamentos e módulos de monitoramento. Para criar um modelo de processo, use a tool create_process_template e aguarde confirmação explícita do usuário. Responda em pt-BR. Não invente dados fora das tools. Não execute operações fiscais, SERPRO, WhatsApp ou criação de processos operacionais.',
    ),
];
