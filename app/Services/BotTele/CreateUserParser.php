<?php

namespace App\Services\BotTele;

/**
 * 1:1 port of the n8n "Code - Validate Create User" node. Two input formats:
 * four unlabelled lines (merchant, group, email line(s), role line) or
 * "Label: value" lines where unlabelled lines after Email/Role continue that field.
 */
class CreateUserParser
{
    // JavaScript's \s and String.prototype.trim() set, which PCRE's \s does not fully cover.
    private const WS = '\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const ALIASES = [
        'merchant' => ['nama merchant', 'merchant name', 'merchant'],
        'group' => ['nama grup merchant', 'merchant group', 'nama group merchant', 'grup merchant'],
        'email' => ['user email', 'email user', 'email'],
        'role' => ['role', 'user role'],
    ];

    /**
     * @return array{valid: bool, missing: string, description: string, emails: list<string>, roles: list<string>}
     */
    public function parse(string $text): array
    {
        $lines = array_values(array_filter(
            array_map(fn (string $line): string => $this->trim($line), preg_split('/\r?\n/', $this->trim($text))),
            fn (string $line): bool => $line !== '',
        ));

        $merchant = '';
        $group = '';
        $emailInputs = [];
        $roleInputs = [];
        $labelled = collect($lines)->contains(fn (string $line): bool => $this->labelOf($line) !== null);

        if ($labelled) {
            $active = '';
            foreach ($lines as $line) {
                $hit = $this->labelOf($line);
                if ($hit) {
                    $active = $hit['key'];
                    if ($active === 'merchant') {
                        $merchant = $hit['value'];
                    } elseif ($active === 'group') {
                        $group = $hit['value'];
                    } elseif ($active === 'email' && $hit['value'] !== '') {
                        $emailInputs[] = $hit['value'];
                    } elseif ($active === 'role' && $hit['value'] !== '') {
                        $roleInputs[] = $hit['value'];
                    }
                } elseif ($active === 'email') {
                    $emailInputs[] = $line;
                } elseif ($active === 'role') {
                    $roleInputs[] = $line;
                }
            }
        } elseif (count($lines) >= 4) {
            $merchant = $lines[0];
            $group = $lines[1];
            $emailInputs = array_slice($lines, 2, -1);
            $roleInputs = [$lines[count($lines) - 1]];
        }

        $emailTokens = $this->tokens($emailInputs, '/['.self::WS.';,]+/u');
        $invalid = array_values(array_filter($emailTokens, fn (string $email): bool => ! preg_match('/^[^'.self::WS.'@]+@[^'.self::WS.'@]+\.[^'.self::WS.'@]+$/uD', $email)));
        // Invalid tokens still count as emails here, exactly like the JS Map did.
        $emails = $this->dedupe($emailTokens);
        $roles = $this->dedupe($this->tokens($roleInputs, '/[,;]+/u'));

        $errors = [];
        if ($merchant === '') {
            $errors[] = 'Nama Merchant';
        }
        if ($group === '') {
            $errors[] = 'Nama Grup Merchant';
        }
        if ($emails === []) {
            $errors[] = 'User Email';
        }
        if ($invalid !== []) {
            $errors[] = 'Email tidak valid: '.implode(', ', $invalid);
        }
        if ($roles === []) {
            $errors[] = 'Role';
        }
        if (! $labelled && count($lines) < 4) {
            $errors[] = 'Minimal 4 baris';
        }

        $valid = $errors === [];

        return [
            'valid' => $valid,
            'missing' => implode('; ', $errors),
            'description' => $valid
                ? 'Nama Merchant : '.$merchant."\nNama Grup Merchant : ".$group."\nUser Email : ".implode(', ', $emails)."\nRole : ".implode(', ', $roles)
                : '',
            'emails' => $emails,
            'roles' => $roles,
        ];
    }

    /** @return array{key: string, value: string}|null */
    private function labelOf(string $line): ?array
    {
        if (! preg_match('/^([^:=]+)['.self::WS.']*[:=]['.self::WS.']*(.*)$/u', $line, $match)) {
            return null;
        }

        $key = preg_replace('/['.self::WS.']+/u', ' ', $this->trim(mb_strtolower($match[1])));
        foreach (self::ALIASES as $field => $aliases) {
            if (in_array($key, $aliases, true)) {
                return ['key' => $field, 'value' => $this->trim($match[2])];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function tokens(array $inputs, string $separator): array
    {
        $tokens = [];
        foreach ($inputs as $input) {
            foreach (preg_split($separator, $input) as $token) {
                $token = $this->trim($token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * Case-insensitive dedupe that keeps the FIRST position but the LAST casing,
     * like `[...new Map(tokens.map(t => [t.toLowerCase(), t])).values()]`.
     *
     * @return list<string>
     */
    private function dedupe(array $tokens): array
    {
        $byKey = [];
        foreach ($tokens as $token) {
            // Prefixed so numeric-looking tokens stay string keys.
            $byKey['k'.mb_strtolower($token)] = $token;
        }

        return array_values($byKey);
    }

    private function trim(string $value): string
    {
        return preg_replace('/^['.self::WS.']+|['.self::WS.']+$/u', '', $value);
    }
}
