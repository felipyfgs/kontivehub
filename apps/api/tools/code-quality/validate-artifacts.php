#!/usr/bin/env php
<?php

use Tools\CodeQuality\ArtifactSetManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['root:', 'final']);
$root = (string) ($options['root'] ?? getenv('CODE_QUALITY_ARTIFACT_ROOT') ?: '');
if ($root === '') {
    fwrite(STDERR, "Informe --root ou CODE_QUALITY_ARTIFACT_ROOT.\n");
    exit(64);
}

$result = (new ArtifactSetManager)->loadAndValidate($root, array_key_exists('final', $options));
fwrite(STDOUT, sprintf(
    "Artefatos válidos %s: API %d/%d, Web %d/%d.\n",
    $result['summary']['combinedDigest'],
    $result['summary']['api']['files'],
    $result['summary']['api']['symbols'],
    $result['summary']['web']['files'],
    $result['summary']['web']['symbols'],
));
