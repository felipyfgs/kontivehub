<?php

namespace App\Services\Communication\Contact;

use App\Services\Communication\WhatsAppAddressNormalizer;
use InvalidArgumentException;

final readonly class SharedVCardParser
{
    private const MAX_PHONES = 10;

    public function __construct(private WhatsAppAddressNormalizer $normalizer) {}

    /**
     * @return array{display_name:string,phones:list<array{label:string,phone:string}>}
     */
    public function parse(string $vcard, ?string $fallbackName = null): array
    {
        $vcard = substr(str_replace("\0", '', $vcard), 0, 65_536);
        $rawLines = preg_split('/\r\n|\n|\r/', $vcard) ?: [];
        $lines = [];
        foreach ($rawLines as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($lines !== []) {
                    $lines[array_key_last($lines)] .= substr($line, 1);
                }

                continue;
            }
            $lines[] = $line;
        }

        $displayName = trim((string) $fallbackName);
        $structuredName = '';
        $phones = [];
        $seen = [];
        $insideCard = false;
        $cardComplete = false;
        $invalidCard = false;
        foreach (array_slice($lines, 0, 500) as $line) {
            $boundary = strtoupper(trim($line));
            if ($boundary === 'BEGIN:VCARD') {
                if ($insideCard || $cardComplete) {
                    $invalidCard = true;
                    break;
                }
                $insideCard = true;

                continue;
            }
            if ($boundary === 'END:VCARD') {
                if (! $insideCard || $cardComplete) {
                    $invalidCard = true;
                    break;
                }
                $insideCard = false;
                $cardComplete = true;

                continue;
            }
            if (! $insideCard) {
                if (trim($line) !== '') {
                    $invalidCard = true;
                    break;
                }

                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $property = substr($line, 0, $separator);
            $rawValue = substr($line, $separator + 1);
            $parts = explode(';', $property);
            $name = strtoupper(array_shift($parts) ?? '');
            if ($name === 'FN' && $displayName === '') {
                $displayName = trim($this->unescape($rawValue));
            } elseif ($name === 'N' && $structuredName === '') {
                $components = array_pad($this->splitComponents($rawValue), 5, '');
                $structuredName = trim(implode(' ', array_filter([
                    $components[3],
                    $components[1],
                    $components[2],
                    $components[0],
                    $components[4],
                ], static fn (string $component): bool => $component !== '')));
            }
            if ($name !== 'TEL' || count($phones) >= self::MAX_PHONES) {
                continue;
            }

            $parameters = [];
            foreach ($parts as $part) {
                [$key, $parameterValue] = array_pad(explode('=', $part, 2), 2, '');
                $parameters[strtoupper($key)] = trim($parameterValue, '"');
            }
            $candidate = $parameters['WAID'] ?? $this->unescape($rawValue);
            try {
                $phone = $this->normalizer->normalize($candidate);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (! str_starts_with($phone, '+') || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $label = strtoupper(trim((string) ($parameters['TYPE'] ?? 'WHATSAPP')));
            $phones[] = [
                'label' => mb_substr($label !== '' ? $label : 'WHATSAPP', 0, 40),
                'phone' => $phone,
            ];
        }

        if ($invalidCard || $insideCard || ! $cardComplete) {
            return [
                'display_name' => mb_substr(trim((string) $fallbackName), 0, 160),
                'phones' => [],
            ];
        }

        return [
            'display_name' => mb_substr($displayName !== '' ? $displayName : $structuredName, 0, 160),
            'phones' => $phones,
        ];
    }

    private function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\([nN,;\\\\])/', static fn (array $match): string => match ($match[1]) {
            'n', 'N' => "\n",
            default => $match[1],
        }, $value) ?? $value;
    }

    /** @return list<string> */
    private function splitComponents(string $value): array
    {
        $components = [''];
        $escaped = false;
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $character = $value[$index];
            if ($escaped) {
                $components[array_key_last($components)] .= '\\'.$character;
                $escaped = false;

                continue;
            }
            if ($character === '\\') {
                $escaped = true;

                continue;
            }
            if ($character === ';') {
                $components[] = '';

                continue;
            }
            $components[array_key_last($components)] .= $character;
        }
        if ($escaped) {
            $components[array_key_last($components)] .= '\\';
        }

        return array_map($this->unescape(...), $components);
    }
}
