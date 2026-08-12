# PayGrid Laravel Target Structure

Ini susunan kerja praktis untuk development harian di `C:\paygrid`.

## Yang Dipakai Sekarang

- `routes/web.php`: route UI dan callback sementara.
- `app/Http/Controllers`: controller layar dan callback.
- `app/Models`: model utama.
- `app/Services/Gateway`: client gateway.
- `app/Services/TransactionIngestionService.php`: normalisasi dan upsert transaksi.
- `app/Services/MetricRollupService.php`: rebuild summary harian.
- `merchant_daily_metrics`: sumber angka dashboard.

## Target Saat Fitur Baru Dibuat

Gunakan pola ini:

```text
Request
  -> FormRequest
  -> Controller tipis
  -> Action
  -> Model/Service/Job
  -> ViewModel/Resource
```

Contoh untuk checklist:

```text
PATCH /api/topup-requests/{topupRequest}/checklist
  -> UpdateChecklistRequest
  -> ChecklistController
  -> Actions\Topups\MarkSuccessChecklist
  -> TopupRequest
```

Contoh untuk polling HG:

```text
php artisan paygrid:sync-gateway-transactions
  -> Jobs\Gateway\SyncAllGatewayTransactions
  -> Services\Gateway\HilogateClient
  -> Actions\Gateway\IngestGatewayTransaction
  -> TopupRequest + MerchantDailyMetric
```

## Mapping Dari Project Lama

```text
Node /api/portal-state
  -> Laravel Queries + MetricsService + merchant_daily_metrics

Node /api/callbacks/hilogate/transaction
  -> GatewayCallbackController + TransactionIngestionService

Node embedded/transactpro
  -> merchant_type = cm

Node embedded/merchant_script
  -> merchant_type = script

Node merchant fee config
  -> fee_schemes + fee_snapshots

Node success checklist
  -> topup_requests.is_processed + processed fields
```

## Aturan Edit

- Jangan pindahkan logic production Node ke Laravel mentah-mentah.
- Porting fitur satu per satu berdasarkan modul.
- Semua angka dashboard harus bisa dijelaskan dari query DB.
- Gateway client tidak boleh dipanggil dari Blade/controller dashboard.
- Kalau butuh data gateway terbaru, trigger job/command, bukan hit gateway di render halaman.

