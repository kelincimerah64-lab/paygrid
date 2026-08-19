<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportTransactproAccountsCommand extends Command
{
    protected $signature = 'paygrid:import-transactpro-accounts
        {path : Path to TransactPro accounts export JSON}
        {--commit : Persist changes. Omit for dry-run preview}
        {--allow-readonly-role-downgrade : Map readonly_admin/admin and readonly_cs/cs instead of skipping}';

    protected $description = 'Import TransactPro production merchants, admins, and portal agents into PayGrid safely.';

    private const ROLE_MAP = [
        'admin' => 'admin',
        'finance' => 'finance',
        'cs' => 'cs',
        'support' => 'cs_pusat',
    ];

    private const READONLY_ROLE_MAP = [
        'readonly_admin' => 'readonly_admin',
        'readonly_cs' => 'readonly_cs',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $commit = (bool) $this->option('commit');

        if (! is_file($path)) {
            $this->error('Export JSON file not found.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path));
        if (! $data) {
            $this->error('Export JSON is invalid.');

            return self::FAILURE;
        }

        $agents = collect($data->portal_agents ?? []);
        $merchants = collect($data->merchants ?? []);
        $admins = collect($data->admins ?? []);
        $registrations = collect($data->merchant_registration_requests ?? []);

        $this->info(($commit ? 'Commit' : 'Dry-run').' TransactPro accounts import preview');
        $this->table(['Source table', 'Rows'], [
            ['portal_agents', $agents->count()],
            ['merchants', $merchants->count()],
            ['admins', $admins->count()],
            ['merchant_registration_requests', $registrations->count()],
        ]);

        if ($commit && ! $this->confirm('This will write TransactPro production accounts into the current PayGrid database. Continue?', false)) {
            $this->warn('Import cancelled.');

            return self::SUCCESS;
        }

        $result = $commit
            ? DB::transaction(fn (): array => $this->import($agents, $merchants, $admins, $registrations, true))
            : $this->import($agents, $merchants, $admins, $registrations, false);

        $this->table(['Target', 'Created', 'Updated', 'Skipped'], [
            ['agents', $result['agents_created'], $result['agents_updated'], $result['agents_skipped']],
            ['merchants', $result['merchants_created'], $result['merchants_updated'], $result['merchants_skipped']],
            ['users', $result['users_created'], $result['users_updated'], $result['users_skipped']],
            ['registrations', 0, 0, $result['registrations_seen']],
        ]);

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        if (! $commit) {
            $this->line('No data was written. Re-run with --commit after reviewing the preview.');
        }

        return self::SUCCESS;
    }

    private function import($agents, $merchants, $admins, $registrations, bool $commit): array
    {
        $result = [
            'agents_created' => 0,
            'agents_updated' => 0,
            'agents_skipped' => 0,
            'merchants_created' => 0,
            'merchants_updated' => 0,
            'merchants_skipped' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'users_skipped' => 0,
            'registrations_seen' => $registrations->count(),
            'warnings' => [],
        ];

        $maUser = User::query()->where('role', 'ma')->orderBy('id')->first();
        $sourceMerchantIds = $merchants->map(fn ($merchant): int => (int) ($merchant->id ?? 0))->filter()->values();

        foreach ($agents as $sourceAgent) {
            $code = $this->nullableString($sourceAgent->id ?? null);
            if (! $code) {
                $result['agents_skipped']++;
                continue;
            }

            $existing = Agent::query()->where('code', $code)->first();
            $attributes = [
                'ma_user_id' => $maUser?->id,
                'code' => $code,
                'name' => $this->nullableString($sourceAgent->name ?? null) ?: $code,
                'email' => $this->nullableString($sourceAgent->email ?? null),
                'password_plain' => $this->nullableString($sourceAgent->password ?? null),
                'contact' => $this->nullableString($sourceAgent->phone ?? null),
                'hg_group_id' => $this->nullableString($sourceAgent->hilogate_merchant_group_id ?? null),
                'base_hg_percent' => $sourceAgent->hilogate_base_fee_percent ?? 0,
                'connection_type' => $this->nullableString($sourceAgent->engine_service_type ?? null),
                'connection_fee_percent' => $sourceAgent->engine_service_fee_percent ?? 0,
                'settlement_method' => $this->nullableString($sourceAgent->settlement_method ?? null),
                'settlement_fee_percent' => $sourceAgent->settlement_service_fee_percent ?? 0,
                'ma_fee_percent' => $sourceAgent->ma_fee_percent ?? 0,
                'default_agent_fee_percent' => $sourceAgent->fee_percent ?? 0,
                'is_active' => $this->isActive($sourceAgent->status ?? 'Active'),
            ];

            if ($commit) {
                Agent::query()->updateOrCreate(['code' => $code], $attributes);
            }

            $existing ? $result['agents_updated']++ : $result['agents_created']++;

            $agentUser = $this->agentUserAttributes($sourceAgent, $code, $attributes);
            $existingUser = User::query()
                ->where('username', $code)
                ->orWhere('email', $agentUser['email'])
                ->first();

            if ($commit) {
                $this->upsertAgentUser($code, $agentUser['email'], $agentUser, (bool) $existingUser);
            }

            $existingUser ? $result['users_updated']++ : $result['users_created']++;
        }

        foreach ($merchants as $sourceMerchant) {
            $slug = $this->nullableString($sourceMerchant->slug ?? null) ?: str($sourceMerchant->name ?? 'merchant-'.$sourceMerchant->id)->slug()->toString();
            if (! $slug) {
                $result['merchants_skipped']++;
                continue;
            }

            $existing = Merchant::query()->where('slug', $slug)->first();
            $agent = $this->agentForMerchant($sourceMerchant);
            $attributes = $this->merchantAttributes($sourceMerchant, $agent?->id, $slug);

            if ($commit) {
                Merchant::query()->updateOrCreate(['slug' => $slug], $attributes);
            }

            $existing ? $result['merchants_updated']++ : $result['merchants_created']++;
        }

        foreach ($admins as $sourceAdmin) {
            $role = $this->targetRole((string) ($sourceAdmin->role ?? ''));
            if (! $role) {
                $result['users_skipped']++;
                $result['warnings'][] = 'Skipped admin ID '.$sourceAdmin->id.' with unsupported role '.($sourceAdmin->role ?? '-').'.';
                continue;
            }

            $email = $this->nullableString($sourceAdmin->email ?? null);
            if (! $email) {
                $result['users_skipped']++;
                $result['warnings'][] = 'Skipped admin ID '.$sourceAdmin->id.' without email.';
                continue;
            }

            $sourceMerchantId = (int) ($sourceAdmin->merchant_id ?? 0);
            $merchant = $sourceMerchantId ? $this->merchantForAdmin($sourceMerchantId) : null;
            if (in_array($role, ['admin', 'finance', 'cs', 'readonly_admin', 'readonly_cs'], true) && ! $merchant) {
                if ($commit || ! $sourceMerchantIds->contains($sourceMerchantId)) {
                    $result['users_skipped']++;
                    $result['warnings'][] = 'Skipped store admin ID '.$sourceAdmin->id.' without matching merchant.';
                    continue;
                }
            }

            $existing = User::query()->where('username', 'tp-admin-'.$sourceAdmin->id)->first();
            $attributes = [
                'name' => $this->nameFromEmail($email),
                'email' => $email,
                'username' => 'tp-admin-'.$sourceAdmin->id,
                'role' => $role,
                'is_active' => true,
                'merchant_id' => in_array($role, ['admin', 'finance', 'cs', 'readonly_admin', 'readonly_cs'], true) ? $merchant?->id : null,
                'password' => $sourceAdmin->password_hash,
            ];

            if ($commit) {
                $this->upsertUserPreservingPasswordHash('tp-admin-'.$sourceAdmin->id, $attributes, (bool) $existing);
            }

            $existing ? $result['users_updated']++ : $result['users_created']++;
        }

        return $result;
    }

    private function merchantAttributes(object $sourceMerchant, ?int $agentId, string $slug): array
    {
        $isScript = (bool) ($sourceMerchant->is_script ?? false);

        return [
            'agent_id' => $agentId,
            'slug' => $slug,
            'name' => $this->nullableString($sourceMerchant->name ?? null) ?: $slug,
            'merchant_id' => $this->nullableString($sourceMerchant->hilogate_merchant_id ?? null),
            'merchant_key' => $sourceMerchant->hilogate_secret_key ?? null,
            'merchant_group_name' => $this->nullableString($sourceMerchant->group_name ?? null),
            'merchant_group_id' => $this->nullableString($sourceMerchant->hilogate_merchant_group_id ?? null),
            'merchant_type' => $isScript ? 'script' : 'cm',
            'gateway' => $this->gateway($sourceMerchant->payment_gateway ?? 'hilogate'),
            'approval_status' => $this->isActive($sourceMerchant->status ?? 'active') ? 'approved' : 'draft',
            'topup_enabled' => ! $isScript,
            'minimum_topup_amount' => $sourceMerchant->min_topup_amount ?? null,
            'transaction_callback_url' => $this->nullableString($sourceMerchant->transaction_callback_url ?? null),
            'withdrawal_callback_url' => $this->nullableString($sourceMerchant->withdrawal_callback_url ?? null),
            'merchant_mdr_percent' => $sourceMerchant->merchant_fee_percent ?? 0,
            'base_mdr_percent' => $sourceMerchant->hilogate_base_fee_percent ?? 0,
            'connection_type' => $this->nullableString($sourceMerchant->engine_service_type ?? null),
            'connection_fee_percent' => $sourceMerchant->engine_service_fee_percent ?? 0,
            'settlement_method' => $this->nullableString($sourceMerchant->settlement_method ?? null),
            'settlement_fee_percent' => $sourceMerchant->settlement_service_fee_percent ?? 0,
            'agent_fee_percent' => $sourceMerchant->agent_fee_percent ?? 0,
            'ma_fee_percent' => $sourceMerchant->ma_fee_percent ?? 0,
            'approved_at' => $this->isActive($sourceMerchant->status ?? 'active') ? now() : null,
            'onboarding_payload' => array_filter([
                'source' => 'transactpro_production_import',
                'source_id' => $sourceMerchant->id ?? null,
                'agent_code' => $sourceMerchant->agent_id ?? null,
                'agent_name' => $sourceMerchant->agent_name ?? null,
                'hilogate_environment' => $sourceMerchant->hilogate_environment ?? null,
                'dashboard_ip_whitelist' => $sourceMerchant->dashboard_ip_whitelist ?? null,
                'api_ip_whitelist' => $sourceMerchant->api_ip_whitelist ?? null,
                'ip_dashboard_cs' => $sourceMerchant->ip_dashboard_cs ?? null,
                'ip_finance_withdrawal' => $sourceMerchant->ip_finance_withdrawal ?? null,
            ], fn ($value): bool => $value !== null && $value !== ''),
        ];
    }

    private function agentForMerchant(object $sourceMerchant): ?Agent
    {
        $code = $this->nullableString($sourceMerchant->agent_id ?? null);
        if ($code) {
            return Agent::query()->where('code', $code)->first();
        }

        $name = $this->nullableString($sourceMerchant->agent_name ?? null);

        return $name ? Agent::query()->where('name', $name)->first() : null;
    }

    private function merchantForAdmin(int $sourceMerchantId): ?Merchant
    {
        return Merchant::query()->whereJsonContains('onboarding_payload->source_id', $sourceMerchantId)->first();
    }

    private function targetRole(string $sourceRole): ?string
    {
        if (isset(self::ROLE_MAP[$sourceRole])) {
            return self::ROLE_MAP[$sourceRole];
        }

        if ((bool) $this->option('allow-readonly-role-downgrade') && isset(self::READONLY_ROLE_MAP[$sourceRole])) {
            return self::READONLY_ROLE_MAP[$sourceRole];
        }

        return null;
    }

    private function upsertUserPreservingPasswordHash(string $username, array $attributes, bool $exists): void
    {
        $now = now();
        $attributes['updated_at'] = $now;

        if ($exists) {
            DB::table('users')->where('username', $username)->update($attributes);

            return;
        }

        $attributes['created_at'] = $now;
        DB::table('users')->insert($attributes);
    }

    private function agentUserAttributes(object $sourceAgent, string $code, array $agentAttributes): array
    {
        $email = $this->uniqueAgentEmail($sourceAgent, $code);
        $password = $this->nullableString($sourceAgent->password ?? null) ?: bin2hex(random_bytes(16));

        return [
            'name' => $agentAttributes['name'],
            'email' => $email,
            'username' => $code,
            'role' => 'agent',
            'is_active' => (bool) $agentAttributes['is_active'],
            'merchant_id' => null,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'plain_password' => $password,
        ];
    }

    private function upsertAgentUser(string $username, string $email, array $attributes, bool $exists): void
    {
        $now = now();
        $attributes['updated_at'] = $now;

        if ($exists) {
            DB::table('users')
                ->where(fn ($query) => $query->where('username', $username)->orWhere('email', $email))
                ->update($attributes);

            return;
        }

        $attributes['created_at'] = $now;
        DB::table('users')->insert($attributes);
    }

    private function uniqueAgentEmail(object $sourceAgent, string $code): string
    {
        $email = $this->nullableString($sourceAgent->email ?? null);
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $existing = User::query()->where('email', $email)->first();
            if (! $existing || $existing->username === $code) {
                return $email;
            }
        }

        return strtolower($code).'@agent.paygrid.local';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function isActive(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['active', 'aktif', 'approved'], true);
    }

    private function gateway(mixed $gateway): string
    {
        $value = strtolower((string) $gateway);

        return in_array($value, ['hilogate', 'alpha', 'artageto', 'kingspay'], true) ? $value : 'hilogate';
    }

    private function nameFromEmail(string $email): string
    {
        return str($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString();
    }
}
