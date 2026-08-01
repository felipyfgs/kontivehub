<?php

namespace Tests\Feature;

use App\Enums\Communication\AvailabilityFailure;
use App\Enums\Communication\FlowFailure;
use App\Exceptions\AssistantUnavailableException;
use App\Exceptions\CommunicationFlowException;
use App\Exceptions\CommunicationUnavailableException;
use Illuminate\Routing\Router;
use Tests\TestCase;

class ApiDomainExceptionRenderingTest extends TestCase
{
    public function test_typed_api_exceptions_use_stable_safe_envelopes(): void
    {
        $router = app(Router::class);
        $router->get(
            '/api/_test/exceptions/assistant',
            static fn () => throw new AssistantUnavailableException,
        );
        $router->get(
            '/api/_test/exceptions/communication',
            static fn () => throw new CommunicationUnavailableException(
                AvailabilityFailure::InboxNotConnected,
            ),
        );
        $router->get(
            '/api/_test/exceptions/flow',
            static fn () => throw new CommunicationFlowException(
                FlowFailure::RunTerminal,
            ),
        );

        $this->getJson('/api/_test/exceptions/assistant')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'message' => 'Assistente indisponível.',
                'code' => 'ASSISTANT_DISABLED',
            ]);

        $this->getJson('/api/_test/exceptions/communication')
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Canal de comunicação indisponível.',
                'code' => 'INBOX_NOT_CONNECTED',
            ]);

        $this->getJson('/api/_test/exceptions/flow')
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Operação de run de fluxo rejeitada.',
                'code' => 'FLOW_RUN_TERMINAL',
            ]);
    }
}
