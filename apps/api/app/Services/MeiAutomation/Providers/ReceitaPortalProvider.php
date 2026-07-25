<?php

namespace App\Services\MeiAutomation\Providers;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Exceptions\MeiAutomationTransportException;
use App\Models\MeiAutomationAttempt;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\MeiAutomation\MeiAutomationAttemptRepository;
use App\Services\MeiAutomation\MeiAutomationAttemptService;
use App\Services\MeiAutomation\MeiAutomationClient;
use App\Services\MeiAutomation\MeiAutomationSyncService;
use App\Services\MeiAutomation\MeiPortalResultTranslator;
use Throwable;

final class ReceitaPortalProvider implements MeiOperationProvider
{
    /** @var list<string> */
    private const FALLBACK_CODES = [
        'PORTAL_UNAVAILABLE',
        'PORTAL_DRIFT',
        'CAPTCHA_EXHAUSTED',
        'PORTAL_CNPJ_FORMAT_UNSUPPORTED',
    ];

    public function __construct(
        private readonly MeiAutomationAttemptRepository $attempts,
        private readonly MeiAutomationAttemptService $attemptService,
        private readonly MeiAutomationClient $client,
        private readonly MeiAutomationSyncService $sync,
        private readonly ContributorCnpjResolver $contributors,
        private readonly MeiPortalResultTranslator $translator,
    ) {}

    public function execute(FiscalAdapterRequest $request, string $operationKey): MeiProviderOutcome
    {
        if (! (bool) config('mei_automation.fixture_enabled', false)
            && ! (bool) config('mei_automation.live_egress_enabled', false)) {
            return $this->unavailable('Provider portal está desabilitado.');
        }

        try {
            $operationKey = $this->effectiveOperationKey($request, $operationKey);
            $input = $this->input($request, $operationKey);
            $attempt = $this->resolveAttempt($request, $operationKey, $input);
        } catch (Throwable) {
            return new MeiProviderOutcome(
                result: FiscalAdapterResult::failed(
                    'Não foi possível montar o contrato fiscal do portal.',
                    'FISCAL_VALIDATION_ERROR',
                ),
                provider: MeiProvider::ReceitaPortal,
            );
        }

        if ($attempt->external_job_id === null) {
            try {
                $job = $this->client->create($this->attemptService->jobRequest($attempt, $input));
                $attempt = $this->attempts->synchronize($attempt, $job);
                if ($attempt->status->shouldPoll()) {
                    $this->sync->schedule($attempt);
                }
            } catch (MeiAutomationTransportException) {
                return $this->unavailable('Sidecar MEI indisponível antes da execução.', $attempt);
            }
        }

        return $this->outcome($request, $attempt);
    }

    /** @param array<string, mixed> $input */
    private function resolveAttempt(
        FiscalAdapterRequest $request,
        string $operationKey,
        array $input,
    ): MeiAutomationAttempt {
        $attemptId = $request->progress['mei_automation_attempt_id'] ?? null;
        if (is_int($attemptId) || (is_string($attemptId) && ctype_digit($attemptId))) {
            $attempt = $this->attempts->findForOffice((int) $request->office->id, (int) $attemptId);
            if ((int) $attempt->client_id !== (int) $request->client->id
                || (string) $attempt->operation_key !== $operationKey) {
                throw new \RuntimeException('Tentativa portal incompatível com a execução fiscal.');
            }

            return $attempt;
        }

        $provider = (bool) config('mei_automation.fixture_enabled', false)
            && ! (bool) config('mei_automation.live_egress_enabled', false)
            ? MeiProvider::Fixture
            : MeiProvider::ReceitaPortal;

        return $this->attemptService->start(
            office: $request->office,
            client: $request->client,
            operationKey: $operationKey,
            provider: $provider,
            idempotencyKey: 'portal:'.hash(
                'sha256',
                (string) $request->run->idempotency_key.'|'.$operationKey,
            ),
            input: $input,
            run: $request->run,
        );
    }

    private function outcome(
        FiscalAdapterRequest $request,
        MeiAutomationAttempt $attempt,
    ): MeiProviderOutcome {
        $provider = $attempt->provider ?? MeiProvider::ReceitaPortal;
        if ($attempt->status->shouldPoll()) {
            return new MeiProviderOutcome(
                result: new FiscalAdapterResult(
                    result: FiscalRunResult::Requeued,
                    situation: FiscalSituation::Processing,
                    coverage: FiscalCoverage::Unknown,
                    shouldRequeue: true,
                    progress: [
                        ...$request->progress,
                        'mei_automation_attempt_id' => $attempt->id,
                        'mei_automation_provider' => $provider->value,
                    ],
                    requeueAfterSeconds: $this->sync->pollIntervalSeconds(),
                ),
                provider: $provider,
                attempt: $attempt,
            );
        }

        if ($attempt->status === MeiAutomationStatus::Succeeded) {
            return new MeiProviderOutcome(
                result: $this->translator->translate($request, $attempt),
                provider: $provider,
                attempt: $attempt,
            );
        }

        $submitted = $attempt->submitted_at !== null
            || $attempt->status === MeiAutomationStatus::Uncertain;
        $errorCode = (string) ($attempt->error_code ?: 'PORTAL_UNAVAILABLE');
        $fallbackEligible = ! $submitted && in_array($errorCode, self::FALLBACK_CODES, true);

        return new MeiProviderOutcome(
            result: FiscalAdapterResult::failed(
                $submitted
                    ? 'O portal pode ter concluído a operação; reconciliação é obrigatória.'
                    : 'O portal MEI não concluiu a operação.',
                $submitted ? 'PORTAL_RESULT_UNCERTAIN' : $errorCode,
            ),
            provider: $provider,
            fallbackEligible: $fallbackEligible,
            submitted: $submitted,
            fallbackReason: $fallbackEligible ? $errorCode : null,
            attempt: $attempt,
        );
    }

    /** @return array<string, mixed> */
    private function input(FiscalAdapterRequest $request, string $operationKey): array
    {
        $input = ['cnpj' => $this->contributors->resolve($request->client)];
        if ($operationKey === 'pgmei.dividaativa') {
            $input['calendar_year'] = $this->calendarYear($request);
        } elseif (in_array($operationKey, ['pgmei.gerardaspdf', 'pgmei.gerardascodbarra'], true)) {
            $competencies = $request->context['competencies']
                ?? $request->progress['competencies']
                ?? null;
            if (! is_array($competencies) || ! array_is_list($competencies)) {
                throw new \RuntimeException('Competências DAS ausentes.');
            }
            $input['competencies'] = $competencies;
            $dueDate = $request->context['due_date']
                ?? $request->progress['due_date']
                ?? null;
            if (is_string($dueDate) && $dueDate !== '') {
                $input['due_date'] = $dueDate;
            }
        } elseif ($operationKey === 'dasnsimei.consultimadecrec') {
            $year = $this->calendarYear($request, required: false);
            if ($year !== null) {
                $input['calendar_year'] = $year;
            }
            $input['include_full_receipt'] = ($request->context['include_full_receipt']
                ?? $request->progress['include_full_receipt']
                ?? false) === true;
        }

        return $input;
    }

    private function effectiveOperationKey(
        FiscalAdapterRequest $request,
        string $operationKey,
    ): string {
        if ($operationKey !== 'pgmei.gerardaspdf') {
            return $operationKey;
        }
        $format = strtoupper((string) ($request->context['output_format']
            ?? $request->progress['output_format']
            ?? 'PDF'));

        return $format === 'BARCODE' ? 'pgmei.gerardascodbarra' : $operationKey;
    }

    private function calendarYear(
        FiscalAdapterRequest $request,
        bool $required = true,
    ): ?int {
        $raw = $request->context['calendar_year']
            ?? $request->context['anoCalendario']
            ?? $request->context['ano_calendario']
            ?? $request->progress['calendar_year']
            ?? $request->progress['anoCalendario']
            ?? $request->progress['ano_calendario']
            ?? $request->progress['period_key']
            ?? null;
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';
        if (strlen($digits) >= 4) {
            $year = (int) substr($digits, 0, 4);
            if ($year >= 2009 && $year <= 2100) {
                return $year;
            }
        }
        if ($required) {
            throw new \RuntimeException('Ano-calendário ausente.');
        }

        return null;
    }

    private function unavailable(
        string $message,
        ?MeiAutomationAttempt $attempt = null,
    ): MeiProviderOutcome {
        return new MeiProviderOutcome(
            result: FiscalAdapterResult::failed($message, 'PORTAL_UNAVAILABLE'),
            provider: MeiProvider::ReceitaPortal,
            fallbackEligible: true,
            submitted: false,
            fallbackReason: 'PORTAL_UNAVAILABLE',
            attempt: $attempt,
        );
    }
}
