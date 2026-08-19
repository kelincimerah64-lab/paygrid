<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportDashboardStateCommand extends Command
{
    protected $signature = 'paygrid:import-dashboard-state
        {path : Path to dashboard_new live-state JSON}
        {--commit : Persist changes. Omit for dry-run preview}';

    protected $description = 'Import dashboard_new portal_state JSON users, agents, and shops into PayGrid safely.';

    private const ROLE_MAP = [
        'Superadmin' => 'superadmin',
        'MA' => 'ma',
        'Agen' => 'agent',
        'Toko Admin' => 'admin',
        'Toko Finance' => 'finance',
        'Toko CS' => 'cs',
        'CS Pusat' => 'cs_pusat',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $commit = (bool) $this->option('commit');

        if (! is_file($path)) {
            $this->error('State JSON file not found.');

            return self::FAILURE;
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path));
        $decoded = json_decode((string) $json);
        if (! $decoded) {
            $this->error('State JSON is invalid.');

            return self::FAILURE;
        }

        $state = $decoded->state ?? $decoded;
        $users = collect($state->users ?? []);
        $agents = collect($state->agents ?? []);
        $shops = collect($state->shops ?? []);

        if ($users->isEmpty() && $agents->isEmpty() && $shops->isEmpty()) {
            $this->error('State JSON has no users, agents, or shops.');

            return self::FAILURE;
        }

        if ($commit && ! $this->confirm('This will write dashboard state users, agents, and shops into PayGrid. Continue?', false)) {
            $this->warn('Import cancelled.');

            return self::SUCCESS;
        }

        $this->info(($commit ? 'Commit' : 'Dry-run').' dashboard state import preview');
        $this->table(['Source key', 'Rows'], [
            ['users', $users->count()],
            ['agents', $agents->count()],
            ['shops', $shops->count()],
        ]);

        $duplicateEmails = $users
            ->map(fn ($user): string => Str::lower(trim((string) ($user->email ?? ''))))
            ->filter()
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys();

        if ($duplicateEmails->isNotEmpty()) {
            $this->warn('Detected '.$duplicateEmails->count().' duplicate source email value(s). Login usernames will use source IDs to keep accounts unique.');
        }

        $result = $commit
            ? DB::transaction(fn (): array => $this->import($users, $agents, $shops, $duplicateEmails, true))
            : $this->import($users, $agents, $shops, $duplicateEmails, false);

        $this->table(['Target', 'Created', 'Updated', 'Skipped'], [
            ['agents', $result['agents_created'], $result['agents_updated'], 0],
            ['merchants', $result['merchants_created'], $result['merchants_updated'], $result['merchants_skipped']],
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

    private function import($users, $agents, $shops, $duplicateEmails, bool $commit): array
    {
        $result = [
            'agents_created' => 0,
            'agents_updated' => 0,
            'merchants_created' => 0,
            'merchants_updated' => 0,
            'merchants_skipped' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'users_skipped' => 0,
            'warnings' => [],
        ];

        $maUser = User::query()->where('role', 'ma')->orderBy('id')->first();
        $usersById = $users->keyBy(fn ($user): string => (string) ($user->id ?? ''));

        foreach ($agents as $sourceAgent) {
            $code = trim((string) ($sourceAgent->id ?? ''));
            if ($code === '') {
                $result['warnings'][] = 'Skipped agent without ID.';
                continue;
            }

            $existing = Agent::query()->where('code', $code)->first();
            $attributes = [
                'ma_user_id' => $maUser?->id,
                'code' => $code,
                'name' => trim((string) ($sourceAgent->name ?? $code)),
                'email' => $this->nullableString($sourceAgent->email ?? null),
                'contact' => $this->nullableString($sourceAgent->phone ?? null),
                'default_agent_fee_percent' => $sourceAgent->feePercent ?? 0,
                'is_active' => $this->isActive($sourceAgent->status ?? 'Active'),
            ];

            if ($commit) {
                Agent::query()->updateOrCreate(['code' => $code], $attributes);
            }

            $existing ? $result['agents_updated']++ : $result['agents_created']++;
        }

        foreach ($shops as $sourceShop) {
            $slug = Str::slug((string) ($sourceShop->shopName ?? $sourceShop->id ?? '')) ?: Str::lower((string) ($sourceShop->id ?? ''));
            if ($slug === '') {
                $result['merchants_skipped']++;
                $result['warnings'][] = 'Skipped shop without name/ID.';
                continue;
            }

            $existing = Merchant::query()->where('slug', $slug)->first();
            $agent = $this->agentForShop($sourceShop);
            $attributes = $this->merchantAttributes($sourceShop, $agent?->id, $slug, $usersById, $duplicateEmails);

            if ($commit) {
                Merchant::query()->updateOrCreate(['slug' => $slug], $attributes);
            }

            $existing ? $result['merchants_updated']++ : $result['merchants_created']++;
        }

        foreach ($users as $sourceUser) {
            $role = self::ROLE_MAP[(string) ($sourceUser->role ?? '')] ?? null;
            if (! $role) {
                $result['users_skipped']++;
                $result['warnings'][] = 'Skipped user with unsupported role: '.(string) ($sourceUser->role ?? '-');
                continue;
            }

            $sourceId = trim((string) ($sourceUser->id ?? ''));
            $username = $sourceId !== '' ? $sourceId : Str::lower((string) ($sourceUser->email ?? ''));
            if ($username === '') {
                $result['users_skipped']++;
                $result['warnings'][] = 'Skipped user without ID/email.';
                continue;
            }

            $email = $this->emailForUser($sourceUser, $username, $duplicateEmails);
            $existing = User::query()->where('username', $username)->orWhere('email', $email)->first();
            $merchant = $this->merchantForUser($sourceUser, $role);
            $attributes = [
                'name' => trim((string) ($sourceUser->name ?? $username)),
                'email' => $email,
                'username' => $username,
                'role' => $role,
                'is_active' => $this->isActive($sourceUser->status ?? 'Active'),
                'merchant_id' => in_array($role, ['admin', 'finance', 'cs'], true) ? $merchant?->id : null,
                'password' => Hash::make((string) ($sourceUser->password ?? Str::random(32))),
                'plain_password' => $sourceUser->password ?? null,
            ];

            if ($commit) {
                $user = User::query()->updateOrCreate(['username' => $username], $attributes);
                if ($role === 'ma') {
                    Agent::query()->whereNull('ma_user_id')->update(['ma_user_id' => $user->id]);
                }
            }

            $existing ? $result['users_updated']++ : $result['users_created']++;
        }

        return $result;
    }

    private function merchantAttributes(object $sourceShop, ?int $agentId, string $slug, $usersById, $duplicateEmails): array
    {
        $approved = (string) ($sourceShop->approvalStatus ?? '') === 'Approved';

        return [
            'agent_id' => $agentId,
            'slug' => $slug,
            'name' => trim((string) ($sourceShop->shopName ?? $slug)),
            'merchant_id' => $this->nullableString($sourceShop->merchantId ?? null),
            'merchant_key' => $sourceShop->merchantKey ?? null,
            'merchant_group_name' => $this->nullableString($sourceShop->merchantGroupName ?? $sourceShop->groupName ?? $sourceShop->merchantGroup ?? null),
            'merchant_group_id' => $this->nullableString($sourceShop->groupId ?? null),
            'merchant_type' => $this->merchantType($sourceShop),
            'gateway' => 'hilogate',
            'approval_status' => $approved ? 'approved' : 'pending_ma',
            'topup_enabled' => $this->merchantType($sourceShop) === 'cm',
            'transaction_callback_url' => $this->nullableString($sourceShop->callbackUrl ?? null),
            'pic_email' => $this->nullableString($sourceShop->email ?? null),
            'finance_email' => $this->emailForStateUser($sourceShop->financeUserId ?? null, $usersById, $duplicateEmails),
            'cs_email' => $this->emailForStateUser($sourceShop->csUserId ?? null, $usersById, $duplicateEmails),
            'merchant_mdr_percent' => $sourceShop->merchantFeePercent ?? $sourceShop->shopFeePercent ?? 0,
            'base_mdr_percent' => $sourceShop->hilogateBaseFeePercent ?? 0,
            'connection_type' => $this->nullableString($sourceShop->engineServiceType ?? null),
            'connection_fee_percent' => $sourceShop->engineServiceFeePercent ?? 0,
            'settlement_method' => $this->nullableString($sourceShop->settlementMethod ?? null),
            'settlement_fee_percent' => $sourceShop->settlementServiceFeePercent ?? 0,
            'ma_fee_percent' => $sourceShop->maFeePercent ?? 0,
            'approved_at' => $approved ? now() : null,
            'onboarding_payload' => array_filter([
                'source' => 'dashboard_state_import',
                'source_id' => $sourceShop->id ?? null,
                'source_status' => $sourceShop->shopStatus ?? null,
                'owner' => $sourceShop->owner ?? null,
                'environment' => $sourceShop->environment ?? null,
                'phone' => $sourceShop->phone ?? null,
                'agent_name' => $sourceShop->agentName ?? null,
            ], fn ($value): bool => $value !== null && $value !== ''),
        ];
    }

    private function agentForShop(object $sourceShop): ?Agent
    {
        $agentId = trim((string) ($sourceShop->agentId ?? ''));
        if ($agentId !== '') {
            return Agent::query()->where('code', $agentId)->first();
        }

        $agentName = trim((string) ($sourceShop->agentName ?? ''));
        return $agentName !== '' ? Agent::query()->where('name', $agentName)->first() : null;
    }

    private function merchantForUser(object $sourceUser, string $role): ?Merchant
    {
        if (! in_array($role, ['admin', 'finance', 'cs'], true)) {
            return null;
        }

        $shopId = trim((string) ($sourceUser->shopId ?? ''));
        if ($shopId === '') {
            return null;
        }

        return Merchant::query()->whereJsonContains('onboarding_payload->source_id', $shopId)->first();
    }

    private function emailForStateUser(?string $sourceUserId, $usersById, $duplicateEmails): ?string
    {
        if (! $sourceUserId) {
            return null;
        }

        $sourceUser = $usersById->get($sourceUserId);
        if ($sourceUser) {
            return $this->emailForUser($sourceUser, $sourceUserId, $duplicateEmails);
        }

        return User::query()->where('username', $sourceUserId)->value('email');
    }

    private function emailForUser(object $sourceUser, string $username, $duplicateEmails): string
    {
        $email = trim((string) ($sourceUser->email ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! $duplicateEmails->contains(Str::lower($email))) {
            return $email;
        }

        return Str::lower($username).'@dashboard-state.local';
    }

    private function merchantType(object $sourceShop): string
    {
        $type = Str::lower((string) ($sourceShop->merchantType ?? $sourceShop->type ?? ''));

        return str_contains($type, 'script') ? 'script' : 'cm';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function isActive(mixed $status): bool
    {
        return in_array(Str::lower((string) $status), ['active', 'aktif', 'approved'], true);
    }
}
