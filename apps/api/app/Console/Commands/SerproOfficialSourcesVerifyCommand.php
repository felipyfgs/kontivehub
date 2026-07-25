<?php

namespace App\Console\Commands;

use App\Services\Serpro\SerproOfficialSourceHttpVerifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verificação documental explícita, read-only e sem egress fiscal de negócio.
 */
final class SerproOfficialSourcesVerifyCommand extends Command
{
    protected $signature = 'serpro:official-sources-verify';

    protected $description = 'Compara hashes das fontes documentais SERPRO allowlisted sem persistir conteúdo';

    public function handle(SerproOfficialSourceHttpVerifier $verifier): int
    {
        try {
            $result = $verifier->verify();
        } catch (Throwable) {
            $result = [
                'status' => 'REVIEW_REQUIRED',
                'results' => [[
                    'source_key' => '_manifest',
                    'result' => 'VERIFICATION_FAILED',
                    'http_status' => null,
                    'hash_result' => 'NOT_COMPUTED',
                    'expected_sha256' => null,
                    'observed_sha256' => null,
                ]],
            ];
        }

        $this->line((string) json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return $result['status'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }
}
