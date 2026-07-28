<?php

namespace Tests\Unit\Communication;

use App\Models\CommunicationInbox;
use App\Services\Communication\WhatsappPeerResolver;
use InvalidArgumentException;
use Tests\TestCase;

class WhatsappPeerResolverTest extends TestCase
{
    public function test_outbound_keeps_chat_lid_instead_of_session_alternate_pn(): void
    {
        $inbox = new CommunicationInbox([
            'address_encrypted' => '+559981769536',
        ]);

        $peer = app(WhatsappPeerResolver::class)->resolve([
            'direction' => 'OUTBOUND',
            'source_identity' => [
                'primary' => 'lid:132366714564657',
                'primary_kind' => 'LID',
                'alternate' => '+559981769536',
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
        ], $inbox);

        $this->assertSame('lid:132366714564657', $peer);
    }

    public function test_inbound_promotes_remote_pn_when_primary_is_lid(): void
    {
        $inbox = new CommunicationInbox([
            'address_encrypted' => '+559981769536',
        ]);

        $peer = app(WhatsappPeerResolver::class)->resolve([
            'direction' => 'INBOUND',
            'source_identity' => [
                'primary' => 'lid:149865032093945',
                'primary_kind' => 'LID',
                'alternate' => '+559992032709',
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
        ], $inbox);

        $this->assertSame('+559992032709', $peer);
    }

    public function test_structured_remote_pn_wins_even_when_from_contains_the_chat_lid(): void
    {
        $inbox = new CommunicationInbox([
            'address_encrypted' => '+559981769536',
        ]);
        $payload = [
            'direction' => 'OUTBOUND',
            'from' => 'lid:149865032093945',
            'source_identity' => [
                'primary' => 'lid:149865032093945',
                'primary_kind' => 'LID',
                'alternate' => '+559992032709',
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
        ];

        $resolver = app(WhatsappPeerResolver::class);
        $peer = $resolver->resolve($payload, $inbox);

        $this->assertSame('+559992032709', $peer);
        $this->assertSame(
            ['+559992032709', 'lid:149865032093945'],
            $resolver->aliases($payload, $peer, $inbox),
        );
    }

    public function test_session_address_is_removed_from_aliases(): void
    {
        $inbox = new CommunicationInbox([
            'address_encrypted' => '+559981769536',
        ]);
        $payload = [
            'direction' => 'OUTBOUND',
            'from' => 'lid:149865032093945',
            'source_identity' => [
                'primary' => 'lid:149865032093945',
                'primary_kind' => 'LID',
                'alternate' => '+559981769536',
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
        ];

        $resolver = app(WhatsappPeerResolver::class);
        $peer = $resolver->resolve($payload, $inbox);

        $this->assertSame('lid:149865032093945', $peer);
        $this->assertSame(
            ['lid:149865032093945'],
            $resolver->aliases($payload, $peer, $inbox),
        );
    }

    public function test_rejects_self_chat_when_only_session_address_is_available(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('self-chat');

        $inbox = new CommunicationInbox([
            'address_encrypted' => '+559981769536',
        ]);

        app(WhatsappPeerResolver::class)->resolve([
            'direction' => 'OUTBOUND',
            'from' => '+559981769536',
        ], $inbox);
    }

    public function test_legacy_from_is_preferred_when_not_session(): void
    {
        $peer = app(WhatsappPeerResolver::class)->resolve([
            'from' => '+5511999991234',
            'source_identity' => [
                'primary' => 'lid:1',
                'primary_kind' => 'LID',
            ],
        ]);

        $this->assertSame('+5511999991234', $peer);
    }
}
