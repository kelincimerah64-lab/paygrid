# PayGrid Laravel Architecture

Dokumen ini menjelaskan target struktur PayGrid versi Laravel. Tujuannya mengganti pola dua folder lama (`transactpro` dan `merchant_script`) menjadi satu aplikasi yang stabil, dengan database sebagai sumber kebenaran.

## Prinsip Utama

- Satu aplikasi Laravel untuk semua tipe toko.
- Tidak ada routing berdasarkan folder fisik CM/script.
- Tipe toko ditentukan dari database: `merchants.merchant_type`.
- Checklist tidak boleh memakai `localStorage`; status checklist wajib tersimpan di DB.
- Dashboard membaca data ringkasan dari DB, bukan hit API gateway setiap render halaman.
- Gateway pull/callback hanya bertugas insert/update data transaksi mentah dan summary.

## Lokasi Project

Project Laravel canonical ada di:

```text
C:\paygrid
```

Folder lama di repo Node (`C:\inmarc\TopUp\paygrid`) hanya copy lama dan tidak boleh dipakai untuk development lanjutan. Jika masih ada proses PHP yang mengunci folder lama, biarkan sampai dimatikan manual; seluruh perubahan Laravel baru dilakukan di `C:\paygrid`.

## Tipe Merchant

### CM

CM adalah toko yang memakai link topup PayGrid.

Menu CS:
- Tiket status
- Top Up Request
- Success Checklist

Rule:
- Topup request dan checklist hanya muncul untuk merchant `merchant_type = cm`.
- Link topup aktif jika `merchants.topup_enabled = true`.

### Script

Script adalah toko yang topup-nya diurus engine/provider luar.

Menu CS:
- Tiket status
- History TRX

Rule:
- Tidak ada Top Up Request.
- Tidak ada Success Checklist.
- Jika user coba buka URL checklist/topup untuk script, route harus return 404.

## Route Portal

Semua portal merchant memakai pola:

```text
/portal/{merchant-slug}/cs/tickets
/portal/{merchant-slug}/cs/topup
/portal/{merchant-slug}/cs/checklist
/portal/{merchant-slug}/cs/history
/portal/{merchant-slug}/finance/overview
```

`merchant-slug` diambil dari `merchants.slug`, bukan dari folder.

## Checklist

Kolom checklist ada di `topup_requests`:

- `is_processed`
- `processed_by_user_id`
- `checked_by_email`
- `checked_by_role`
- `processed_at`

Saat user centang:

1. Backend validasi transaksi harus `success`.
2. Backend update row `topup_requests` dalam DB transaction.
3. UI refresh dari response DB.
4. Gateway sync berikutnya tidak boleh overwrite field checklist.

Ini mencegah bug checklist hilang setelah logout/login, refresh, atau sync gateway.

Invariant yang tidak boleh dilanggar:

- Ingestion gateway hanya boleh mengubah field transaksi gateway, seperti status, RRN, amount, net amount, dan raw payload.
- Ingestion gateway wajib preserve `is_processed`, `processed_by_user_id`, `checked_by_email`, `checked_by_role`, dan `processed_at`.
- UI checklist harus membaca response API/DB, bukan state browser.
- Logout/login tidak boleh mengubah URL menjadi preview/admin bypass.
- Route CM-only harus tetap ditolak untuk merchant script.

## Data Dashboard

Tabel raw transaksi:

```text
topup_requests
```

Tabel summary:

```text
merchant_daily_metrics
```

Overview MA, agent, finance, dan CS membaca summary dari `merchant_daily_metrics`. Detail transaksi tetap membaca `topup_requests` dengan pagination dan filter tanggal/status.

Untuk trafik besar, worker gateway harus:

1. Pull/callback data gateway.
2. Upsert `topup_requests` berdasarkan reference/payment id.
3. Update `merchant_daily_metrics`.
4. Preserve field manual seperti checklist, ticket, dan note.

Command pendukung:

```bash
php artisan gateway:sync-transactions --merchant={merchant_id} --from=YYYY-MM-DD --to=YYYY-MM-DD
php artisan metrics:rebuild-daily YYYY-MM-DD
```

`gateway:sync-transactions` dipakai untuk polling gateway dan menyimpan data ke DB. `metrics:rebuild-daily` dipakai untuk rebuild summary jika ada koreksi data atau backfill.

## Skala 9 Juta Transaksi per Hari

Target skala tidak boleh bergantung pada query agregasi langsung ke raw table setiap render halaman. Struktur yang dipakai:

- `topup_requests` sebagai raw/audit log dan sumber detail paginated.
- `merchant_daily_metrics` sebagai sumber overview, report per agen, report per toko, dan ranking.
- Unique/upsert transaksi memakai reference gateway (`gateway_ref_id`) agar idempotent.
- Index wajib untuk query harian, merchant, gateway, status, dan checklist.
- Worker ingestion berjalan batch/chunk, bukan render-time fetch.
- Archive/partition bulanan disiapkan untuk raw transaksi lama.

Untuk produksi MySQL/PostgreSQL, raw table disarankan dipartisi berdasarkan bulan dari `submitted_at`. UI histori wajib pakai filter tanggal agar query selalu bounded.

## Onboarding

Alur target:

1. User isi form onboarding dari link agent.
2. Data masuk ke `merchant_registrations` dengan status `pending_agent`.
3. Agent review dan submit ke MA.
4. MA lengkapi fee / gateway / data HG bila perlu.
5. MA approve.
6. Sistem call gateway create merchant.
7. Response gateway disimpan ke `merchants`, termasuk `merchant_id` dan `merchant_key`.

Field form user tidak wajib lengkap. MA boleh melengkapi sebelum approve.

## Gateway

Client gateway disiapkan sebagai service:

- `HilogateClient`
- `AlphaClient`
- `ArtagetoClient`
- `KingsPayClient`

Saat ini client masih stub lokal. Sebelum production cutover, isi credential dan endpoint asli lalu buat test per gateway.

## Cutover dari Node

Langkah aman:

1. Import agents dari Node DB ke `agents`.
2. Import merchants/toko ke `merchants`.
3. Import transaksi aktif ke `topup_requests`.
4. Rebuild `merchant_daily_metrics` dari transaksi.
5. Implement gateway pull/callback real.
6. QA route CM dan script:
   - CM punya topup/checklist.
   - Script hanya tiket/history.
   - Checklist tetap ada setelah logout/login.
7. Baru arahkan domain/subdomain ke route Laravel.

## Test Wajib

Feature test wajib menjaga bug lama tidak balik:

- CM merchant melihat menu Top Up Request dan Success Checklist.
- Script merchant tidak melihat menu Top Up Request dan Success Checklist.
- Script merchant tidak bisa membuka route CM-only.
- Checklist tersimpan ke DB.
- MA membaca list merchant dari DB.
