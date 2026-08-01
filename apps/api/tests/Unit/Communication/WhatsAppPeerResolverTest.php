<?php

namespace Tests\Unit\Communication;

use App\Models\CommunicationInbox;
use App\Services\Communication\WhatsAppPeerResolver;
use InvalidArgumentException;
use Tests\TestCase;

class WhatsAppPeerResolverTest extends TestCase
{
    public function test_outbound_keeps_chat_lid_instead_of_session_alternate_pn(): void
    {
        $inbox = new CommunicationInbox([
            'address_encrypted' => '+559981769536',
        ]);

        $peer = app(WhatsAppPeerResolver::class)->resolve([
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

        $peer = app(WhatsAppPeerResolver::class)->resolve([
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

        $resolver = app(WhatsAppPeerResolver::class);
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

        $resolver = app(WhatsAppPeerResolver::class);
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

        app(WhatsAppPeerResolver::class)->resolve([
            'direction' => 'OUTBOUND',
            'from' => '+559981769536',
        ], $inbox);
    }

    public function test_from_is_used_when_source_identity_is_absent(): void
    {
        $peer = app(WhatsAppPeerResolver::class)->resolve([
            'from' => '+5511999991234',
        ]);

        $this->assertSame('+5511999991234', $peer);
    }

    public function test_structured_primary_wins_over__from_without_remote_pn(): void
    {
        $peer = app(WhatsAppPeerResolver::class)->resolve([
            'from' => '+5511999991234',
            'source_identity' => [
                'primary' => 'lid:123456789012345',
                'primary_kind' => 'LID',
            ],
        ]);

        $this->assertSame('lid:123456789012345', $peer);
    }

    public function test_rejects_structured_alias_without_lid_to_pn_evidence(): void
    {
        $resolver = app(WhatsAppPeerResolver::class);
        foreach ([
            [
                'primary' => '+5511999990001',
                'primary_kind' => 'PN',
                'alternate' => '+5511999990002',
                'alternate_kind' => 'PN',
                'evidence' => 'MESSAGE_SOURCE_ALT',
            ],
            [
                'primary' => 'lid:123456789012345',
                'primary_kind' => 'LID',
                'alternate' => '+5511999990002',
                'alternate_kind' => 'PN',
                'evidence' => 'UNVERIFIED_ALIAS',
            ],
        ] as $sourceIdentity) {
            try {
                $resolver->resolve(['source_identity' => $sourceIdentity]);
                $this->fail('Associação estrutural incoerente foi aceita.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('incoerente', $error->getMessage());
            }
        }
    }
}
