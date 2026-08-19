<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class ImportBillwayAccountsCommand extends Command
{
    protected $signature = 'paygrid:import-billway
        {--driver= : Source DB driver: mysql, pgsql, or sqlite}
        {--host= : Source DB host}
        {--port= : Source DB port}
        {--database= : Source DB name or SQLite path}
        {--username= : Source DB username}
        {--password= : Source DB password}
        {--commit : Persist changes. Omit for dry-run preview}
        {--allow-readonly-role-downgrade : Map readonly_admin/admin and readonly_cs/cs instead of failing}';

    protected $description = 'Import Billway users, roles, agents, and merchants into PayGrid safely.';

    private const ROLE_MAP = [
        'superadmin' => 'superadmin',
        'ma' => 'ma',
        'agent' => 'agent',
        'admin_toko' => 'admin',
        'cs_toko' => 'cs',
        'finance_toko' => 'finance',
        'support_pusat' => 'cs_pusat',
    ];

    private const READONLY_ROLE_MAP = [
        'readonly_admin' => 'admin',
        'readonly_cs' => 'cs',
    ];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        if ($commit && ! $this->confirm('This will write imported Billway accounts into the current PayGrid database. Continue?', false)) {
            $this->warn('Import cancelled.');

            return self::SUCCESS;
        }

        $source = $this->sourceConnection();
        if (! $source) {
            return self::FAILURE;
        }

        $missing = collect(['users', 'roles', 'user_roles', 'portal_agents', 'merchants'])
            ->reject(fn (string $table): bool => $this->sourceTableExists($source, $table));

        if ($missing->isNotEmpty()) {
            $this->error('Source DB is missing required table(s): '.$missing->implode(', '));

            return self::FAILURE;
        }

        $roleByUserId = $this->sourceRolesByUserId($source);
        $allowReadonlyDowngrade = (bool) $this->option('allow-readonly-role-downgrade');
        $unsupportedRoles = $roleByUserId
            ->flatten()
            ->unique()
            ->filter(fn (string $role): bool => ! isset(self::ROLE_MAP[$role]) && ! ($allowReadonlyDowngrade && isset(self::READONLY_ROLE_MAP[$role])));

        if ($unsupportedRoles->isNotEmpty()) {
            $this->error('Unsupported Billway role(s): '.$unsupportedRoles->implode(', '));
            $this->line('Re-run with --allow-readonly-role-downgrade only if readonly roles may become normal admin/cs in PayGrid.');

            return self::FAILURE;
        }

        $counts = [
            'agents' => (int) $source->table('portal_agents')->count(),
            'merchants' => (int) $source->table('merchants')->count(),
            'users' => (int) $source->table('users')->count(),
        ];

        $this->info(($commit ? 'Commit' : 'Dry-run').' Billway import preview');
        $this->table(['Source table', 'Rows'], collect($counts)->map(fn (int $count, string $table): array => [$table, $count])->values()->all());

        $result = $commit
            ? DB::transaction(fn (): array => $this->import($source, $roleByUserId, $allowReadonlyDowngrade, true))
            : $this->import($source, $roleByUserId, $allowReadonlyDowngrade, false);

        $this->table(['Target', 'Created', 'Updated', 'Skipped'], [
            ['agents', $result['agents_created'], $result['agents_updated'], 0],
            ['merchants', $result['merchants_created'], $result['merchants_updated'], 0],
            ['users', $result['users_created'], $result['users_updated'], $result['users_skipped']],
        ]);

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        if (! $commit) {
            $this->line('No data was written. Re-run with --commit after reviewing the preview.');
        }

        return self::SUCCESS;
    }

    private function sourceConnection(): ?Connection
    {
        $driver = (string) ($this->option('driver') ?: env('BILLWAY_DB_DRIVER', 'mysql'));
        $database = (string) ($this->option('database') ?: env('BILLWAY_DB_DATABASE', ''));

        if ($database === '') {
            $this->error('Source database is required. Set BILLWAY_DB_DATABASE or pass --database=.');

            return null;
        }

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            $this->error('Unsupported source driver. Use mysql, pgsql, or sqlite.');

            return null;
        }

        $name = 'billway_import_source';
        $config = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]
            : [
                'driver' => $driver,
                'host' => (string) ($this->option('host') ?: env('BILLWAY_DB_HOST', '127.0.0.1')),
                'port' => (string) ($this->option('port') ?: env('BILLWAY_DB_PORT', $driver === 'pgsql' ? '5432' : '3306')),
                'database' => $database,
                'username' => (string) ($this->option('username') ?: env('BILLWAY_DB_USERNAME', '')),
                'password' => (string) ($this->option('password') ?: env('BILLWAY_DB_PASSWORD', '')),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];

        config(["database.connections.{$name}" => $config]);
        DB::purge($name);

        try {
            /** @var Connection $connection */
            $connection = DB::connection($name);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $exception) {
            $this->error('Could not connect to Billway source DB. Check driver/host/database/username/password.');

            return null;
        }
    }

    private function sourceTableExists(Connection $source, string $table): bool
    {
        return $source->getSchemaBuilder()->hasTable($table);
    }

    private function sourceRolesByUserId(Connection $source): \Illuminate\Support\Collection
    {
        return $source->table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->select(['user_roles.user_id', 'roles.name'])
            ->orderBy('roles.id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('name')->values());
    }

    private function import(Connection $source, \Illuminate\Support\Collection $roleByUserId, bool $allowReadonlyDowngrade, bool $commit): array
    {
        $result = [
            'agents_created' => 0,
            'agents_updated' => 0,
            'merchants_created' => 0,
            'merchants_updated' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'users_skipped' => 0,
            'warnings' => [],
        ];

        $agentIdMap = [];
        foreach ($source->table('portal_agents')->orderBy('id')->cursor() as $sourceAgent) {
            $existing = Agent::query()->where('code', $sourceAgent->code)->first();
            $attributes = [
                'code' => $sourceAgent->code,
                'name' => $sourceAgent->name,
                'email' => $sourceAgent->email ?? null,
                'contact' => $sourceAgent->contact_number ?? null,
                'default_agent_fee_percent' => $sourceAgent->fee_percent ?? 0,
                'is_active' => (bool) ($sourceAgent->is_active ?? true),
            ];

            if ($commit) {
                $agent = Agent::query()->updateOrCreate(['code' => $sourceAgent->code], $attributes);
                $agentIdMap[(int) $sourceAgent->id] = $agent->id;
            } else {
                $agentIdMap[(int) $sourceAgent->id] = $existing?->id;
            }

            $existing ? $result['agents_updated']++ : $result['agents_created']++;
        }

        foreach ($source->table('merchants')->orderBy('id')->cursor() as $sourceMerchant) {
            $existing = Merchant::query()->where('slug', $sourceMerchant->slug)->first();
            $attributes = $this->merchantAttributes($sourceMerchant, $agentIdMap[(int) ($sourceMerchant->portal_agent_id ?? 0)] ?? null);

            if ($commit) {
                Merchant::query()->updateOrCreate(['slug' => $sourceMerchant->slug], $attributes);
            }

            $existing ? $result['merchants_updated']++ : $result['merchants_created']++;
        }

        foreach ($source->table('users')->orderBy('id')->cursor() as $sourceUser) {
            $sourceRoles = $roleByUserId->get($sourceUser->id, collect());
            $role = $this->targetRole($sourceRoles->all(), $allowReadonlyDowngrade);

            if (! $role) {
                $result['users_skipped']++;
                $result['warnings'][] = "Skipped user ID {$sourceUser->id}: no importable role.";
                continue;
            }

            if ($sourceRoles->contains(fn (string $sourceRole): bool => isset(self::READONLY_ROLE_MAP[$sourceRole]))) {
                $result['warnings'][] = "Readonly role downgraded for user ID {$sourceUser->id}.";
            }

            $sourceMerchantSlug = $sourceUser->merchant_id ? $this->sourceMerchantSlug($source, (int) $sourceUser->merchant_id) : null;
            $sourceAgentCode = $sourceUser->portal_agent_id ? $this->sourceAgentCode($source, (int) $sourceUser->portal_agent_id) : null;
            $merchant = $sourceMerchantSlug ? Merchant::query()->where('slug', $sourceMerchantSlug)->first() : null;
            $agent = $sourceAgentCode ? Agent::query()->where('code', $sourceAgentCode)->first() : null;
            $existing = User::query()->where('email', $sourceUser->email)->first();

            $attributes = [
                'name' => $sourceUser->name,
                'email' => $sourceUser->email,
                'username' => $role === 'agent' ? ($agent?->code ?: $sourceAgentCode ?: $sourceUser->email) : ($sourceUser->username ?? null),
                'role' => $role,
                'is_active' => (bool) ($sourceUser->is_active ?? true),
                'merchant_id' => in_array($role, ['admin', 'finance', 'cs'], true) ? $merchant?->id : null,
                'password' => $sourceUser->password,
            ];

            if ($commit) {
                $this->upsertUserPreservingPasswordHash($sourceUser->email, $attributes, (bool) $existing);
            }

            $existing ? $result['users_updated']++ : $result['users_created']++;
        }

        return $result;
    }

    private function merchantAttributes(object $sourceMerchant, ?int $agentId): array
    {
        return [
            'agent_id' => $agentId,
            'slug' => $sourceMerchant->slug,
            'name' => $sourceMerchant->name,
            'merchant_id' => $sourceMerchant->hilogate_merchant_id ?? null,
            'merchant_key' => $sourceMerchant->merchant_key ?? null,
            'merchant_group_name' => $sourceMerchant->group_name ?? null,
            'merchant_type' => ($sourceMerchant->is_script ?? false) ? 'script' : 'cm',
            'gateway' => $sourceMerchant->payment_gateway ?? 'hilogate',
            'approval_status' => ($sourceMerchant->status ?? 'active') === 'active' ? 'approved' : 'draft',
            'topup_enabled' => ! (bool) ($sourceMerchant->is_script ?? false),
            'pic_email' => $sourceMerchant->email_pic ?? null,
            'pic_telegram' => $sourceMerchant->telegram_pic ?? null,
            'finance_email' => $sourceMerchant->email_admin ?? null,
            'finance_telegram' => $sourceMerchant->telegram_admin ?? null,
            'merchant_mdr_percent' => $sourceMerchant->merchant_fee_percent ?? 0,
            'base_mdr_percent' => $sourceMerchant->hilogate_base_fee_percent ?? 0,
            'connection_type' => $sourceMerchant->engine_service_type ?? null,
            'connection_fee_percent' => $sourceMerchant->engine_service_fee_percent ?? 0,
            'settlement_method' => $sourceMerchant->settlement_method ?? null,
            'settlement_fee_percent' => $sourceMerchant->settlement_service_fee_percent ?? 0,
            'agent_fee_percent' => $sourceMerchant->agent_fee_percent ?? 0,
            'ma_fee_percent' => $sourceMerchant->ma_fee_percent ?? 0,
            'approved_at' => ($sourceMerchant->status ?? 'active') === 'active' ? now() : null,
            'onboarding_payload' => array_filter([
                'source' => 'billway_import',
                'source_id' => $sourceMerchant->id,
                'transaction_source' => $sourceMerchant->transaction_source ?? null,
                'contact_number' => $sourceMerchant->contact_number ?? null,
                'ip_dashboard' => $sourceMerchant->ip_dashboard ?? null,
            ], fn ($value): bool => $value !== null && $value !== ''),
        ];
    }

    private function targetRole(array $sourceRoles, bool $allowReadonlyDowngrade): ?string
    {
        $mapped = collect($sourceRoles)
            ->map(fn (string $role): ?string => self::ROLE_MAP[$role] ?? ($allowReadonlyDowngrade ? (self::READONLY_ROLE_MAP[$role] ?? null) : null))
            ->filter()
            ->values();

        return collect(['superadmin', 'ma', 'agent', 'admin', 'finance', 'cs', 'cs_pusat'])
            ->first(fn (string $role): bool => $mapped->contains($role));
    }

    private function upsertUserPreservingPasswordHash(string $email, array $attributes, bool $exists): void
    {
        $now = now();
        $attributes['updated_at'] = $now;

        if ($exists) {
            DB::table('users')->where('email', $email)->update($attributes);

            return;
        }

        $attributes['created_at'] = $now;
        DB::table('users')->insert($attributes);
    }

    private function sourceMerchantSlug(Connection $source, int $id): ?string
    {
        return $source->table('merchants')->where('id', $id)->value('slug');
    }

    private function sourceAgentCode(Connection $source, int $id): ?string
    {
        return $source->table('portal_agents')->where('id', $id)->value('code');
    }
}
