<?php

namespace App\Contracts;

use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use Psr\Http\Message\StreamInterface;

interface CommunicationTransport
{
    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt;

    /** @return array<string, mixed> */
    public function query(GatewayQueryData $query): array;

    /** @return array{session_id:string,status:string,desired_connected:bool,reconnect_count:int,connected:bool,logged_in:bool,ready:bool,has_credentials:bool} */
    public function sessionStatus(string $sessionId): array;

    public function downloadMedia(string $spoolId): StreamInterface;
}
