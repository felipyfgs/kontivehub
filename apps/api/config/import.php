<?php

/**
 * Limites de importação em massa de XML/ZIP (saídas NF-e 55 / NFC-e 65).
 * Valores alinhados a Nginx, PHP-FPM e workers — processing assíncrono
 * só entra com a change add-office-autxml-and-bulk-xml-import.
 *
 * Edge atual (docker):
 *   nginx client_max_body_size = 25M  (margem multipart sobre 20 MiB compactados)
 *   php upload_max_filesize    = 20M
 *   php post_max_size          = 25M
 *   php memory_limit           = 512M
 *
 * @see openspec/changes/add-office-autxml-and-bulk-xml-import design D9
 */
return [
    // Feature: processamento assíncrono por batch (default off até seção 6)
    'async_batches_enabled' => filter_var(env('IMPORT_ASYNC_BATCHES_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Admissão HTTP (top-level)
    'max_top_level_files' => (int) env('IMPORT_MAX_TOP_LEVEL_FILES', 50),
    /** KiB — validação Laravel por arquivo individual (top-level). */
    'max_file_kib' => (int) env('IMPORT_MAX_FILE_KIB', 20480), // 20 MiB
    /** Bytes — total compactado da requisição (soma dos uploads). */
    'max_request_compressed_bytes' => (int) env('IMPORT_MAX_REQUEST_COMPRESSED_BYTES', 20 * 1024 * 1024),

    // Preflight ZIP / streaming
    'max_xml_entries_per_batch' => (int) env('IMPORT_MAX_XML_ENTRIES', 5000),
    'max_xml_bytes' => (int) env('IMPORT_MAX_XML_BYTES', 5 * 1024 * 1024),
    'max_batch_uncompressed_bytes' => (int) env('IMPORT_MAX_BATCH_UNCOMPRESSED_BYTES', 250 * 1024 * 1024),
    'max_compression_ratio' => (float) env('IMPORT_MAX_COMPRESSION_RATIO', 100.0),

    // Parser XML seguro
    'xml_max_depth' => (int) env('IMPORT_XML_MAX_DEPTH', 64),
    'xml_max_nodes' => (int) env('IMPORT_XML_MAX_NODES', 200000),

    // Spool / retenção (segundos)
    'spool_retention_seconds' => (int) env('IMPORT_SPOOL_RETENTION_SECONDS', 7 * 24 * 3600),
    'queue' => env('IMPORT_QUEUE', 'import-xml'),

    // Alinhamento documentado com edge (não reconfigura o container daqui)
    'edge' => [
        'nginx_client_max_body_size' => env('IMPORT_EDGE_NGINX_BODY', '25M'),
        'php_upload_max_filesize' => env('IMPORT_EDGE_PHP_UPLOAD', '20M'),
        'php_post_max_size' => env('IMPORT_EDGE_PHP_POST', '25M'),
        'php_memory_limit' => env('IMPORT_EDGE_PHP_MEMORY', '512M'),
    ],
];
