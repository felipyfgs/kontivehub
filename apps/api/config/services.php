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

    'cnpj_public_lookup' => [
        'url' => env('CNPJ_PUBLIC_LOOKUP_URL', 'https://publica.cnpj.ws/cnpj'),
        'timeout_seconds' => (int) env('CNPJ_PUBLIC_LOOKUP_TIMEOUT_SECONDS', 5),
        'cache_seconds' => (int) env('CNPJ_PUBLIC_LOOKUP_CACHE_SECONDS', 86400),
        'ccmei_enrichment' => (bool) env('CNPJ_LOOKUP_CCMEI_ENRICHMENT', false),
    ],

    /*
    | API Consulta CNPJ (SERPRO) — produto separado do Integra Contador.
    | Tier QSA por padrão. Desligado até contratar e configurar chaves.
    */
    'cnpj_serpro_consulta' => [
        'enabled' => (bool) env('CNPJ_LOOKUP_SERPRO_CONSULTA', false),
        'base_url' => env('CNPJ_SERPRO_CONSULTA_BASE_URL', 'https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df/v2'),
        'qsa_path' => env('CNPJ_SERPRO_CONSULTA_QSA_PATH', '/qsa'),
        'token_url' => env('CNPJ_SERPRO_CONSULTA_TOKEN_URL', 'https://gateway.apiserpro.serpro.gov.br/token'),
        'consumer_key' => env('CNPJ_SERPRO_CONSULTA_CONSUMER_KEY'),
        'consumer_secret' => env('CNPJ_SERPRO_CONSULTA_CONSUMER_SECRET'),
        'timeout_seconds' => (int) env('CNPJ_SERPRO_CONSULTA_TIMEOUT_SECONDS', 8),
        'cache_seconds' => (int) env('CNPJ_SERPRO_CONSULTA_CACHE_SECONDS', 86400),
    ],

];
