<?php

namespace App\Services\Communication\Flows;

/**
 * Projeção mascarada do grafo para gestores (PII/segredos).
 */
final class CommunicationFlowGraphPreviewService
{
    /** @var list<string> */
    private const TEXT_KEYS = ['body', 'prompt', 'value', 'text', 'label', 'title', 'description'];

    public function __construct(
        private readonly CommunicationFlowGraphCanonicalizer $canonicalizer,
        private readonly CommunicationFlowTextMasker $masker,
    ) {}

    /**
     * @param  array{nodes?: list<mixed>, edges?: list<mixed>}  $graph
     * @return array{graph: array<string, mixed>, graph_digest: string, masked_paths: list<string>}
     */
    public function preview(array $graph): array
    {
        $digest = $this->canonicalizer->digest($graph);
        $maskedPaths = [];
        $nodes = [];
        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $index => $node) {
            if (! is_array($node)) {
                $nodes[] = $node;

                continue;
            }
            $path = "nodes.$index";
            $clone = $node;
            if (isset($clone['data']) && is_array($clone['data'])) {
                $clone['data'] = $this->maskData($clone['data'], "$path.data", $maskedPaths);
            }
            $nodes[] = $clone;
        }

        $edges = [];
        foreach (is_array($graph['edges'] ?? null) ? $graph['edges'] : [] as $index => $edge) {
            if (! is_array($edge)) {
                $edges[] = $edge;

                continue;
            }
            $path = "edges.$index";
            $clone = $edge;
            foreach (['label', 'branch'] as $key) {
                if (isset($clone[$key]) && is_string($clone[$key]) && $clone[$key] !== '') {
                    $original = $clone[$key];
                    $masked = $this->masker->mask($original);
                    if ($masked !== $original) {
                        $clone[$key] = $masked;
                        $maskedPaths[] = "$path.$key";
                    }
                }
            }
            if (isset($clone['data']) && is_array($clone['data'])) {
                $clone['data'] = $this->maskData($clone['data'], "$path.data", $maskedPaths);
            }
            $edges[] = $clone;
        }

        return [
            'graph' => [
                'nodes' => $nodes,
                'edges' => $edges,
            ],
            'graph_digest' => $digest,
            'masked_paths' => array_values(array_unique($maskedPaths)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $maskedPaths
     * @return array<string, mixed>
     */
    private function maskData(array $data, string $path, array &$maskedPaths): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyStr = is_string($key) ? $key : (string) $key;
            $childPath = "$path.$keyStr";
            if (is_array($value)) {
                if (array_is_list($value) && $keyStr === 'options') {
                    $out[$key] = [];
                    foreach ($value as $i => $option) {
                        if (is_string($option)) {
                            $masked = $this->masker->mask($option);
                            $out[$key][] = $masked;
                            if ($masked !== $option) {
                                $maskedPaths[] = "$childPath.$i";
                            }
                        } else {
                            $out[$key][] = $option;
                        }
                    }

                    continue;
                }
                $out[$key] = $this->maskData($value, $childPath, $maskedPaths);

                continue;
            }
            if (is_string($value) && $this->shouldMaskKey($keyStr)) {
                $masked = $this->masker->mask($value);
                $out[$key] = $masked;
                if ($masked !== $value || $this->looksSensitive($value)) {
                    $maskedPaths[] = $childPath;
                    if ($masked === $value && $this->looksSensitive($value)) {
                        $out[$key] = $this->masker->preview($value);
                    }
                }

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function shouldMaskKey(string $key): bool
    {
        $lower = strtolower($key);

        return in_array($lower, self::TEXT_KEYS, true);
    }

    private function looksSensitive(string $value): bool
    {
        if (preg_match('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', $value) === 1) {
            return true;
        }
        if (preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $value) === 1) {
            return true;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 8;
    }
}
