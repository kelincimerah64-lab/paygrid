<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantDailyMetric;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PayGridDemoSeeder extends Seeder
{
    public function run(): void
    {
        $demoPassword = config('paygrid.demo_password');

        User::query()->create([
            'name' => 'Superadmin',
            'email' => 'superadmin@paygrid.local',
            'role' => 'superadmin',
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        $maUser = User::query()->create([
            'name' => 'michael',
            'email' => 'michael@paygrid.local',
            'role' => 'ma',
            'contact' => '081200000001',
            'base_hg_percent' => 0.8,
            'connection_type' => 'cm',
            'connection_fee_percent' => 0.05,
            'settlement_method' => 'h_plus_1',
            'settlement_fee_percent' => 0.05,
            'ma_fee_percent' => 0.15,
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        User::query()->create([
            'name' => 'CS Pusat',
            'email' => 'cs-pusat@paygrid.local',
            'role' => 'cs_pusat',
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        $csUser = User::query()->create([
            'name' => 'CS BJ',
            'email' => 'cs-bj@paygrid.local',
            'role' => 'cs',
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        $epc = Agent::query()->create([
            'ma_user_id' => $maUser->id,
            'code' => 'AG-EPC',
            'name' => 'EPC',
            'email' => 'epc@paygrid.local',
            'password_plain' => $demoPassword,
            'hg_group_id' => 'fabe45a6-9a2c-4ee9-a36a-e337aca2142a',
            'base_hg_percent' => 0.8,
            'connection_type' => 'cm',
            'connection_fee_percent' => 0.05,
            'settlement_method' => 'h_plus_1',
            'settlement_fee_percent' => 0.05,
            'ma_fee_percent' => 0.15,
            'is_active' => true,
        ]);

        $other = Agent::query()->create([
            'ma_user_id' => $maUser->id,
            'code' => 'AG-OTHER',
            'name' => 'others',
            'email' => 'others@paygrid.local',
            'password_plain' => $demoPassword,
            'base_hg_percent' => 0.8,
            'connection_type' => 'script',
            'connection_fee_percent' => 0.05,
            'settlement_method' => 'h_plus_1',
            'settlement_fee_percent' => 0.05,
            'ma_fee_percent' => 0.15,
            'is_active' => true,
        ]);

        foreach ([$epc, $other] as $agent) {
            User::query()->create([
                'name' => $agent->name,
                'email' => $agent->email ?: strtolower($agent->code).'@paygrid.local',
                'username' => $agent->code,
                'role' => 'agent',
                'password' => Hash::make($demoPassword),
                'plain_password' => $demoPassword,
            ]);
        }

        $bj = $this->merchant($epc, 'nnp CM - BJ', 'nnp-cm-bj', 'cm', 'hilogate', 'a7a2a8ca-fbd6-4691-9d4f-44f6a01fd6fc', 1.2, 0.8, 0.15);
        $bl77 = $this->merchant($epc, 'BL77', 'bl77', 'cm', 'hilogate', '5b8e16c0-0bf3-4d79-82fb-36dc6321584d', 1.2, 0.8, 0.15);
        $gate = $this->merchant($epc, 'gate69', 'gate69', 'script', 'alpha', '83e6ec18-a8ed-4f10-84c8-f7cb7af588b1', 1.2, 0.8, 0.15);
        $tiktok = $this->merchant($epc, 'Tiktok5000', 'tiktok5000', 'script', 'hilogate', 'fc017072-b215-4959-8fc8-1fffed505870', 1.2, 0.8, 0.15);
        $valo = $this->merchant($other, 'Valohoki [1LG]', 'valohoki-1lg', 'script', 'hilogate', 'ba33c709-dc60-4801-8d41-demo', 1.2, 0.8, 0.15);

        $csUser->forceFill(['merchant_id' => $bj->id])->save();

        User::query()->create([
            'name' => 'Admin BJ',
            'email' => 'admin@nnp-cm-bj.local',
            'role' => 'admin',
            'merchant_id' => $bj->id,
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        User::query()->create([
            'name' => 'Finance BJ',
            'email' => 'finance@nnp-cm-bj.local',
            'role' => 'finance',
            'merchant_id' => $bj->id,
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        User::query()->create([
            'name' => 'gate69 CS',
            'email' => 'bisnisasall1111@gmail.com',
            'role' => 'cs',
            'merchant_id' => $gate->id,
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        User::query()->create([
            'name' => 'Tiktok5000 CS',
            'email' => 'tes@mail.com',
            'role' => 'cs',
            'merchant_id' => $tiktok->id,
            'password' => Hash::make('tes123'),
            'plain_password' => 'tes123',
        ]);

        User::query()->create([
            'name' => 'Tiktok5000 Admin',
            'email' => 'admin@tiktok5000.local',
            'role' => 'admin',
            'merchant_id' => $tiktok->id,
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        User::query()->create([
            'name' => 'Tiktok5000 Finance',
            'email' => 'finance@tiktok5000.local',
            'role' => 'finance',
            'merchant_id' => $tiktok->id,
            'password' => Hash::make($demoPassword),
            'plain_password' => $demoPassword,
        ]);

        $this->request($bj, 'success', 50000, true, $csUser->email, 'BJ0001');
        $this->request($bj, 'success', 99000, false, null, 'BJ0002');
        $this->request($bj, 'pending', 25000, false, null, 'BJ0003');
        $this->request($bl77, 'success', 120000, true, $csUser->email, 'BL77001');
        $this->request($gate, 'expired', 40000, false, null, 'G69001');
        $this->request($valo, 'success', 202300, false, null, 'VALO01');

        SupportTicket::query()->create([
            'merchant_id' => $bj->id,
            'topup_request_id' => $bj->topupRequests()->where('status', 'pending')->first()->id,
            'ticket_no' => 'TCK-'.now()->format('His').'-BJ',
            'reference' => 'BJ0003',
            'client_reference' => 'BJ0003',
            'issue' => 'Payment pending',
            'status' => 'not_started',
            'note' => 'Ticket otomatis dari transaksi pending.',
        ]);

        foreach ([$bj, $bl77, $gate, $tiktok, $valo] as $merchant) {
            MerchantDailyMetric::query()->create([
                'merchant_id' => $merchant->id,
                'agent_id' => $merchant->agent_id,
                'metric_date' => now('Asia/Jakarta')->toDateString(),
                'gateway' => $merchant->gateway,
                'data_source' => $merchant->gateway === 'alpha' ? 'alpha_pull' : 'gateway_pull',
                'trx_total' => $merchant->topupRequests()->count(),
                'trx_success' => $merchant->topupRequests()->where('status', 'success')->count(),
                'trx_pending' => $merchant->topupRequests()->where('status', 'pending')->count(),
                'trx_expired' => $merchant->topupRequests()->where('status', 'expired')->count(),
                'amount_success' => $merchant->topupRequests()->where('status', 'success')->sum('amount'),
                'net_success' => $merchant->topupRequests()->where('status', 'success')->sum('net_amount'),
                'settled_total' => $merchant->topupRequests()->where('status', 'success')->sum('net_amount'),
                'ticket_total' => $merchant->tickets()->count(),
            ]);
        }
    }

    private function merchant(Agent $agent, string $name, string $slug, string $type, string $gateway, string $merchantId, float $mdr, float $base, float $ma): Merchant
    {
        return Merchant::query()->create([
            'agent_id' => $agent->id,
            'slug' => $slug,
            'name' => $name,
            'merchant_id' => $merchantId,
            'merchant_key' => null,
            'merchant_group_name' => $type === 'cm' ? 'nnp DEMO GROUP' : 'Script',
            'merchant_group_id' => $agent->hg_group_id,
            'merchant_type' => $type,
            'gateway' => $gateway,
            'approval_status' => 'approved',
            'topup_enabled' => $type === 'cm',
            'topup_url' => $type === 'cm' ? "http://{$slug}.15.232.137.74.nip.io/topup" : null,
            'pic_email' => "admin@{$slug}.local",
            'finance_email' => "finance@{$slug}.local",
            'cs_email' => "cs@{$slug}.local",
            'merchant_mdr_percent' => $mdr,
            'base_mdr_percent' => $base,
            'connection_fee_percent' => 0.05,
            'settlement_method' => 'h_plus_1',
            'settlement_fee_percent' => 0.05,
            'ma_fee_percent' => $ma,
            'agent_fee_percent' => 0,
            'toko_fee_percent' => max(0, $mdr - $base - 0.05 - 0.05 - $ma),
            'approved_at' => now(),
        ]);
    }

    private function request(Merchant $merchant, string $status, int $amount, bool $processed, ?string $checkedBy, string $trx): void
    {
        TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'data_source' => $merchant->gateway === 'alpha' ? 'alpha_pull' : 'gateway_pull',
            'payment_id' => 'qris_'.strtolower(Str::random(18)),
            'gateway_ref_id' => (string) Str::uuid(),
            'rrn' => $status === 'success' ? strtoupper(Str::random(10)) : null,
            'transaction_id' => $trx,
            'status' => $status,
            'amount' => $amount,
            'net_amount' => (int) floor($amount * .989),
            'fee_amount' => $amount - (int) floor($amount * .989),
            'is_processed' => $processed,
            'checked_by_email' => $checkedBy,
            'checked_by_role' => $checkedBy ? 'cs' : null,
            'processed_at' => $processed ? now() : null,
            'submitted_at' => now('Asia/Jakarta')->subMinutes(rand(5, 90)),
            'expires_at' => now('Asia/Jakarta')->addMinutes(30),
            'gateway_payload' => ['seed' => true],
        ]);
    }
}
