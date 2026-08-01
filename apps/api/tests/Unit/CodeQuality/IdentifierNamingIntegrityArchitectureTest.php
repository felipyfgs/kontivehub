<?php

namespace Tests\Unit\CodeQuality;

use App\Enums\Communication\AvailabilityFailure;
use App\Enums\Communication\FlowFailure;
use App\Enums\Communication\OperationFailure;
use App\Jobs\Communication\AdvanceFlowRunJob;
use App\Jobs\Communication\CorrelateFlowEventJob;
use App\Jobs\Communication\DeleteMediaObjectJob;
use App\Jobs\Communication\DispatchOutboxJob;
use App\Jobs\Communication\ReconcileInboxIdentityProfilesJob;
use App\Jobs\Communication\RefreshProfilePictureJob;
use App\Models\PagtoWebArrecadacaoReceipt;
use App\Models\PagtoWebPaymentCountObservation;
use App\Models\PagtoWebPaymentCountProjection;
use App\Models\PagtoWebPaymentListItem;
use App\Models\PagtoWebPaymentListObservation;
use App\Models\PagtoWebPaymentListProjection;
use PHPUnit\Framework\TestCase;

final class IdentifierNamingIntegrityArchitectureTest extends TestCase
{
    public function test_contextual_api_identifiers_do_not_repeat_their_namespace(): void
    {
        $apiRoot = dirname(__DIR__, 3).'/app';

        $forbidden = [
            'Http/Requests/Communication' => '/\b(?:Communication[A-Za-z]*Request|prepareCommunicationValidation)\b/',
            'Http/Requests/Tenant' => '/\b(?:[A-Za-z]*Tenant[A-Za-z]*Request)\b/',
            'Http/Requests/Work' => '/\b(?:Work[A-Za-z]*Request|prepareWorkValidation)\b/',
            'Actions/Tenant' => '/\b(?:[A-Za-z]*Tenant[A-Za-z]*Action)\b/',
            'Actions/Work' => '/\b(?:Work[A-Za-z]*Action)\b/',
            'Http/Controllers/Api/V1/Work' => '/\b(?:Work[A-Za-z]*Controller)\b/',
            'Http/Resources/Work' => '/\b(?:Work[A-Za-z]*(?:Resource|Collection))\b/',
            'Enums/Communication' => '/\bCommunication[A-Za-z]*Failure\b/',
            'Jobs/Communication' => '/\b[A-Za-z]*Communication[A-Za-z]*Job\b/',
        ];

        foreach ($forbidden as $relativePath => $pattern) {
            foreach (glob($apiRoot.'/'.$relativePath.'/*.php') ?: [] as $file) {
                self::assertDoesNotMatchRegularExpression($pattern, (string) file_get_contents($file), $file);
            }
        }
    }

    public function test_pagto_web_models_keep_their_existing_tables_explicitly(): void
    {
        $models = [
            PagtoWebArrecadacaoReceipt::class => 'pagtoweb_arrecadacao_receipts',
            PagtoWebPaymentCountObservation::class => 'pagtoweb_payment_count_observations',
            PagtoWebPaymentCountProjection::class => 'pagtoweb_payment_count_projections',
            PagtoWebPaymentListItem::class => 'pagtoweb_payment_list_items',
            PagtoWebPaymentListObservation::class => 'pagtoweb_payment_list_observations',
            PagtoWebPaymentListProjection::class => 'pagtoweb_payment_list_projections',
        ];

        foreach ($models as $model => $table) {
            self::assertSame($table, (new $model)->getTable());
        }
    }

    public function test_pagto_web_casing_is_canonical_in_api_code_and_filenames(): void
    {
        $apiRoot = dirname(__DIR__, 3);

        foreach (['app', 'tests'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($apiRoot.'/'.$directory),
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if (stripos($file->getFilename(), 'pagtoweb') !== false) {
                    self::assertStringContainsString('PagtoWeb', $file->getFilename(), $file->getPathname());
                }
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (! is_array($token) || ! in_array($token[0], [T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        continue;
                    }

                    $identifier = ltrim($token[1], '$\\');
                    if (stripos($identifier, 'pagtoweb') === false) {
                        continue;
                    }

                    $isDescriptiveTestMethod = str_starts_with($file->getPathname(), $apiRoot.'/tests/')
                        && str_starts_with($identifier, 'test_');
                    $canonical = $token[0] === T_VARIABLE
                        ? str_starts_with($identifier, 'pagtoWeb')
                        : (str_contains($identifier, 'PagtoWeb')
                            || str_starts_with($identifier, 'pagtoWeb')
                            || preg_match('/^pagtoweb_[a-z0-9_]+$/', $identifier) === 1
                            || $isDescriptiveTestMethod);
                    self::assertTrue($canonical, "Casing PagtoWeb inválido em {$file->getPathname()}: {$identifier}");
                }
            }
        }
    }

    public function test_renamed_enum_and_job_fqcns_are_the_only_api_symbols_used(): void
    {
        foreach ([
            AvailabilityFailure::class,
            FlowFailure::class,
            OperationFailure::class,
            AdvanceFlowRunJob::class,
            CorrelateFlowEventJob::class,
            DeleteMediaObjectJob::class,
            DispatchOutboxJob::class,
            ReconcileInboxIdentityProfilesJob::class,
            RefreshProfilePictureJob::class,
        ] as $class) {
            self::assertTrue(class_exists($class));
        }

        foreach ([
            'App\\Jobs\\Communication\\AdvanceCommunicationFlowRunJob',
            'App\\Jobs\\Communication\\CorrelateCommunicationFlowEventJob',
            'App\\Jobs\\Communication\\DeleteCommunicationMediaObjectJob',
            'App\\Jobs\\Communication\\DispatchCommunicationOutboxJob',
            'App\\Jobs\\Communication\\ReconcileCommunicationInboxIdentityProfilesJob',
            'App\\Jobs\\Communication\\RefreshCommunicationProfilePictureJob',
        ] as $Job) {
            self::assertFalse(class_exists($Job, false));
        }
    }

    public function test_private_identifiers_do_not_reintroduce_known_verbose_names(): void
    {
        $apiRoot = dirname(__DIR__, 3).'/app';
        $forbidden = [
            'assertDatabaseArtifactLooksRestorable',
            'invalidateMismatchedCredentialsInTransaction',
            'markExistingExpectedProjectionUnverified',
            'authorizeAndDebitBeforeRemoteDispatch',
        ];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($apiRoot));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $identifier) {
                self::assertStringNotContainsString($identifier, $source, $file->getPathname());
            }
        }
    }

    public function test_api_has_no_transitional_compatibility_terms_in_code_or_filenames(): void
    {
        $apiRoot = dirname(__DIR__, 3);
        $forbiddenTermStems = [
            'english_compatibility_marker' => 'leg'.'acy',
            'portuguese_compatibility_marker' => 'leg'.'ad',
        ];
        $textExtensions = ['css', 'go', 'js', 'json', 'md', 'mjs', 'php', 'sql', 'ts', 'txt', 'vue', 'yaml', 'yml'];
        $preservedLiteral = 'leg'.'acy_provisional';
        $preservedLiteral = strtoupper($preservedLiteral);

        foreach ([
            'app',
            'config',
            'database',
            'routes',
            'tests',
        ] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($apiRoot.'/'.$directory),
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $textExtensions, true)) {
                    continue;
                }

                $relativePath = str_replace($apiRoot.'/', '', $file->getPathname());
                $path = strtolower($relativePath);
                $source = (string) file_get_contents($file->getPathname());

                if ($file->getExtension() === 'php') {
                    $source = collect(token_get_all($source))
                        ->map(function (array|string $token) use ($preservedLiteral): string {
                            if (! is_array($token)) {
                                return $token;
                            }

                            if ($token[0] === T_CONSTANT_ENCAPSED_STRING
                                && trim($token[1], "'\"") === $preservedLiteral) {
                                return '';
                            }

                            return $token[1];
                        })
                        ->implode('');
                }
                $source = strtolower($source);
                foreach ($forbiddenTermStems as $term) {
                    self::assertStringNotContainsString($term, $path, $file->getPathname());
                    self::assertStringNotContainsString($term, $source, $file->getPathname());
                }
            }
        }
    }
}
