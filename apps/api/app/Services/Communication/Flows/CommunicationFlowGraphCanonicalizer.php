<?php

namespace App\Services\Communication\Flows;

/**
 * Digest SHA-256 sobre JSON canônico (chaves ordenadas recursivamente).
 */
final class CommunicationFlowGraphCanonicalizer
{
    /**
     * @param  array<string, mixed>  $graph
     */
    public function digest(array $graph): string
    {
        return hash('sha256', $this->canonicalJson($graph));
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    public function canonicalJson(array $graph): string
    {
        $normalized = $this->normalize($graph);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Não foi possível serializar o grafo canônico.');
        }

        return $encoded;
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if ($isList) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $this->normalize($item);
        }

        return $out;
    }
}
