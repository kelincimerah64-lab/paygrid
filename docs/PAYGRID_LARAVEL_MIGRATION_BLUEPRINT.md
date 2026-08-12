# PayGrid Laravel Migration Blueprint

Dokumen ini adalah susunan target PayGrid versi Laravel untuk migrasi pelan-pelan dari project Node/embedded lama. Prinsipnya: Laravel menjadi sistem baru yang lebih aman, lebih ringan, dan lebih mudah dirawat tanpa memindahkan semua fitur sekaligus.

## Tujuan

- Semua data dashboard dibaca dari database lokal.
- Gateway seperti Hilogate hanya dipakai oleh backend melalui callback, queue job, dan command sync.
- Tidak ada data penting dari `localStorage`, dummy state, atau hardcoded fallback.
- CM dan Script tetap satu aplikasi, dibedakan oleh `merchants.merchant_type`.
- Query dashboard memakai summary table, bukan agregasi raw transaksi setiap render.
- Setiap write penting idempotent, punya audit trail, dan tidak overwrite field manual seperti checklist.

## Susunan Folder Target

```text
app/
  Actions/
    Agents/
    Auth/
    Fees/
    Gateway/
    Merchants/
    Metrics/
    Topups/
  DTO/
    Gateway/
    Reports/
  Enums/
  Http/
    Controllers/
      Admin/
      Agent/
      Gateway/
      MA/
      Merchant/
      PublicTopup/
    Middleware/
    Requests/
  Jobs/
    Gateway/
    Metrics/
    Notifications/
  Models/
  Policies/
  Queries/
  Services/
    Gateway/
    Navigation/
  Support/
  ViewModels/
config/
  paygrid.php
database/
  migrations/
  seeders/
docs/
tests/
  Feature/
    Gateway/
    Merchant/
    Reports/
  Unit/
    Fees/
    Gateway/
```

Folder existing tetap boleh dipakai. Pindah ke susunan ini dilakukan bertahap saat fitur disentuh, bukan refactor besar sekali jalan.

## Boundary Modul

### Actions

Isi proses bisnis yang mengubah data.

Contoh target:

- `Actions\Gateway\IngestGatewayTransaction`
- `Actions\Gateway\SyncMerchantTransactions`
- `Actions\Merchants\ApproveMerchantRegistration`
- `Actions\Fees\CalculateMerchantFee`
- `Actions\Topups\MarkSuccessChecklist`

Controller hanya validasi request dan panggil Action. Logic bisnis jangan ditaruh di Blade atau controller besar.

### DTO

Isi object data ter-normalisasi dari gateway atau report.

Contoh:

- `DTO\Gateway\GatewayTransactionData`
- `DTO\Reports\MerchantReportFilter`

Tujuannya supaya payload HG/Alpha/Artageto tidak bocor mentah ke semua layer.

### Enums

Isi status yang sekarang masih string berulang.

Target:

- `MerchantType`: `cm`, `script`
- `GatewayName`: `hilogate`, `alpha`, `artageto`, `kingspay`
- `TopupStatus`: `pending`, `success`, `expired`, `failed`, `rejected`
- `RegistrationStatus`: `pending_agent`, `pending_ma`, `approved`, `rejected`

### Jobs

Isi pekerjaan background yang tidak boleh memberatkan request user.

Target:

- `Jobs\Gateway\SyncAllGatewayTransactions`
- `Jobs\Gateway\SyncMerchantTransactions`
- `Jobs\Metrics\RebuildMerchantDailyMetric`
- `Jobs\Metrics\RebuildDateRangeMetrics`

Dashboard tidak boleh menunggu job ini selesai.

### Queries

Isi query read-model untuk dashboard/report.

Target:

- `Queries\MerchantMetricsQuery`
- `Queries\AgentDashboardQuery`
- `Queries\MaDashboardQuery`
- `Queries\TopupRequestQuery`

Tujuannya supaya query report tidak tersebar di controller/view.

### Services

Tetap dipakai untuk integrasi eksternal dan helper lintas modul.

Yang sudah ada dan dipertahankan:

- `Services\Gateway\HilogateClient`
- `Services\TransactionIngestionService`
- `Services\MetricRollupService`
- `Services\MetricsService`
- `Services\Navigation\MenuBuilder`

## Alur Data Gateway Target

### Callback

```text
Gateway callback
  -> GatewayCallbackController
  -> verify signature + whitelist
  -> IngestGatewayTransaction action
  -> upsert topup_requests
  -> dispatch RebuildMerchantDailyMetric
  -> write gateway_sync_logs
```

### Polling 8 Detik

```text
Laravel scheduler setiap 8 detik
  -> dispatch SyncAllGatewayTransactions
  -> job ambil merchant approved aktif
  -> per merchant pull max page_size terbaru
  -> ingest payload
  -> update topup_requests + merchant_daily_metrics
```

Request dashboard tidak ikut polling. Dashboard cukup baca DB.

## Rule Anti Bug Lama

- `merchant_daily_metrics` menjadi sumber angka dashboard MA/agent/finance.
- `topup_requests` menjadi sumber detail transaksi paginated.
- `gateway_payload` boleh disimpan mentah, tapi view/report memakai kolom normal.
- `is_processed`, `processed_by_user_id`, `checked_by_email`, `checked_by_role`, `processed_at` tidak boleh dioverwrite oleh sync gateway.
- Merchant script tidak boleh melihat Top Up Request dan Success Checklist.
- Merchant CM boleh melihat Top Up Request dan Success Checklist.
- Tidak boleh ada fallback dummy angka transaksi.
- Tidak boleh match transaksi ke toko berdasarkan agent/group name saja. Match utama harus `merchant_id`, `gateway_ref_id`, `payment_id`, atau merchant DB id.

## Konfigurasi Target

Buat `config/paygrid.php` sebagai pusat config:

```php
return [
    'gateway_sync' => [
        'enabled' => env('PAYGRID_GATEWAY_SYNC_ENABLED', true),
        'interval_seconds' => env('PAYGRID_GATEWAY_SYNC_INTERVAL_SECONDS', 8),
        'page_size' => env('PAYGRID_GATEWAY_SYNC_PAGE_SIZE', 50),
        'concurrency' => env('PAYGRID_GATEWAY_SYNC_CONCURRENCY', 6),
    ],
    'security' => [
        'server_ip' => env('PAYGRID_SERVER_IP'),
        'callback_trusted_ips' => array_filter(explode(',', env('PAYGRID_CALLBACK_TRUSTED_IPS', ''))),
    ],
];
```

## Database Target

Tabel utama:

- `agents`
- `merchants`
- `merchant_registrations`
- `topup_requests`
- `support_tickets`
- `merchant_daily_metrics`
- `gateway_sync_logs`
- `audit_logs`
- `dashboard_users`

Tambahan yang disarankan sebelum production:

- `merchant_gateway_accounts`: credential gateway per merchant, encrypted.
- `fee_schemes`: riwayat fee MA/agent/toko.
- `fee_snapshots`: fee yang melekat pada transaksi saat transaksi masuk.
- `sync_cursors`: posisi terakhir polling per gateway/merchant.

## Security Target

- `merchant_key`, secret gateway, dan credential external wajib encrypted cast.
- Callback wajib verify signature.
- Callback wajib support trusted IP list.
- Internal endpoint wajib token atau middleware auth khusus.
- Admin/MA/Agent/CS/Finance memakai role dan policy, bukan cek string tersebar.
- Semua create/edit/delete fee, merchant, checklist, settlement wajib masuk audit log.

## Roadmap Migrasi

### Fase 1 - Pondasi DB dan Read Model

- Rapikan migration agar cocok dengan data Node production.
- Import agents, merchants, users, dan transaksi dari Node.
- Rebuild `merchant_daily_metrics`.
- Pastikan dashboard Laravel membaca DB dan tidak punya dummy fallback.

### Fase 2 - Gateway Sync

- Implement real `HilogateClient`.
- Implement command/job polling semua merchant.
- Tambahkan `sync_cursors` agar polling tidak selalu tarik data lama.
- Callback HG masuk Laravel dan verified.

### Fase 3 - User dan Role

- Pisahkan login Superadmin, MA, Agent, Toko Finance, Toko CS.
- Buat policy per route.
- Pastikan CS/Finance hanya melihat merchant miliknya.

### Fase 4 - Onboarding dan Fee

- Superadmin create MA + fee dasar MA.
- MA create Agent + fee agent.
- Agent/MA create toko dengan fee turun otomatis.
- Simpan fee snapshot transaksi agar report lama tidak berubah jika fee diedit.

### Fase 5 - Cutover

- Jalankan Laravel read-only side-by-side dengan Node.
- Bandingkan angka dashboard per merchant.
- Aktifkan callback ke Laravel.
- Pindahkan domain bertahap per role/merchant.

## Checklist Test Wajib

- CM punya Top Up Request dan Success Checklist.
- Script tidak punya Top Up Request dan Success Checklist.
- Checklist sukses tersimpan ke DB dan tetap ada setelah logout/login.
- Polling gateway tidak overwrite checklist.
- Dashboard merchant kosong menampilkan `0`, bukan data merchant lain.
- Report agent tidak memasukkan transaksi milik toko lain.
- Fee MA/agent/toko dihitung dari snapshot yang benar.
- Callback signature invalid ditolak.
- Internal sync tanpa token ditolak.

