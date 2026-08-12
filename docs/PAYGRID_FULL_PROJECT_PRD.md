# PayGrid Full Project PRD

Versi: 1.0  
Target stack: Laravel 13, PHP 8.3, MySQL/MariaDB, Queue Worker, Scheduler, Blade atau Inertia/Vue/React bertahap  
Project path target: `C:\paygrid`

## 1. Ringkasan Produk

PayGrid adalah dashboard monitoring transaksi topup merchant dengan struktur multi-level:

- Superadmin
- MA
- Agent atau Merchant Group
- Toko atau Merchant
- Toko Finance
- Toko CS

Sistem menerima transaksi dari gateway seperti Hilogate, Alpha, Artageto, dan KingsPay. Semua data transaksi harus disimpan ke database lokal. Dashboard tidak boleh bergantung pada request langsung ke gateway saat halaman dibuka.

Tujuan migrasi Laravel adalah mengganti aplikasi Node/embedded lama dengan sistem yang:

- Lebih aman.
- Lebih ringan.
- Lebih mudah di-maintain.
- Tidak memakai dummy/fallback data.
- Tidak mencampur logic CM dan Script dalam folder frontend berbeda.
- Siap untuk polling gateway, callback, audit log, role policy, dan report besar.

## 2. Prinsip Utama

1. Database lokal adalah source of truth untuk dashboard.
2. Gateway hanya diakses oleh backend melalui callback, job, command, atau sync worker.
3. Frontend tidak boleh menyimpan status penting di localStorage.
4. Semua data yang tampil di dashboard harus berasal dari database.
5. Jika data database kosong, UI harus menampilkan `0` atau state kosong, bukan data dummy.
6. Transaksi tidak boleh dicocokkan ke merchant berdasarkan agent/group name saja.
7. Checklist manual tidak boleh hilang setelah sync gateway.
8. CM dan Script berada dalam satu aplikasi Laravel, dibedakan lewat `merchants.merchant_type`.
9. Semua perubahan fee, merchant, checklist, dan settlement harus masuk audit log.
10. API internal harus dilindungi token atau middleware auth.

## 3. Role dan Hak Akses

### 3.1 Superadmin

Fungsi:

- Login dashboard Superadmin.
- Melihat dashboard fee dan ringkasan semua MA, agent, toko, dan transaksi.
- Membuat MA.
- Mengatur fee dasar MA:
  - MDR merchant MA.
  - Base MDR HG.
  - Pay-in atau connection fee.
  - Settlement/disbursement fee.
  - MA fee.
- Mengedit fee MA sewaktu-waktu.
- Melihat daftar agent.
- Melihat daftar toko.
- Melihat report per agent/toko.
- Melihat log aktivitas.
- Reset akun dashboard.
- Mengelola setting gateway default:
  - callback URL
  - whitelist IP server
  - payment gateway IDs
  - settlement type

Larangan:

- Tidak boleh melihat secret gateway full di UI tanpa masking.
- Tidak boleh menghapus transaksi raw.

### 3.2 MA

Fungsi:

- Login dashboard MA.
- Membuat Agent atau Merchant Group.
- Menentukan fee agent berdasarkan batas fee MA.
- Melihat agent dan toko di bawah MA.
- Mengapprove request merchant/toko.
- Melengkapi data gateway/HG saat approve merchant.
- Melihat report transaksi merchant di bawah MA.

Rule:

- MA tidak boleh membuat fee toko lebih rendah dari cost dasar.
- MA hanya melihat agent/toko miliknya.

### 3.3 Agent

Fungsi:

- Login dashboard Agent.
- Melihat toko di bawah agent.
- Membuat request toko baru.
- Mengatur fee toko jika diizinkan.
- Melihat report transaksi toko miliknya.
- Melihat status request toko.

Rule:

- Agent tidak boleh melihat toko agent lain.
- Data dashboard agent harus berasal dari merchant_daily_metrics dan topup_requests sesuai scope agent.

### 3.4 Toko Finance

Fungsi:

- Melihat overview saldo aktif/pending.
- Melihat settlement/report transaksi.
- Melihat daftar transaksi sukses.
- Melihat request settlement atau withdrawal jika fitur aktif.

Rule:

- Finance hanya melihat merchant miliknya.
- Finance tidak boleh melakukan checklist CS.

### 3.5 Toko CS

Fungsi CM:

- Ticket Status.
- Top Up Request.
- Success Checklist.

Fungsi Script:

- Ticket Status.
- History TRX.

Rule:

- Merchant `merchant_type = cm` boleh melihat Top Up Request dan Success Checklist.
- Merchant `merchant_type = script` tidak boleh melihat Top Up Request dan Success Checklist.
- Route CM-only untuk Script harus return 404.
- Checklist harus tersimpan ke database.

## 4. Merchant Type

### 4.1 CM

CM adalah merchant yang memakai link topup PayGrid.

Fitur:

- Link topup aktif jika `merchants.topup_enabled = true`.
- Top Up Request tampil.
- Success Checklist tampil.
- CS bisa checklist transaksi sukses.

### 4.2 Script

Script adalah merchant yang transaksinya datang dari engine/provider luar.

Fitur:

- Tidak ada Top Up Request.
- Tidak ada Success Checklist.
- CS melihat History TRX.
- Transaksi tetap masuk DB via gateway callback/polling.

## 5. Konsep Fee

### 5.1 Layer Fee

Fee harus dihitung bertingkat:

```text
Merchant MDR final
  = Base MDR HG
  + Pay-in/Connection Fee
  + Settlement/Disbursement Fee
  + MA Fee
  + Agent Fee
  + Toko Spread jika ada
```

Contoh:

```text
MA config:
Base HG: 0.80%
Settlement: 0.05%
Script/CM service: 0.05%
MA fee: 0.05%
MDR MA final: 0.95%

Agent config:
Base inherited from MA
Agent fee: 0.10%
MDR Agent final: 1.05%

Toko:
Toko fee/spread: 0.15%
MDR toko final: 1.20%
```

### 5.2 Fee Snapshot

Setiap transaksi harus menyimpan snapshot fee saat transaksi dibuat/masuk:

- merchant_mdr_percent
- base_mdr_percent
- payin_fee_percent
- settlement_fee_percent
- ma_fee_percent
- agent_fee_percent
- toko_fee_percent

Alasan:

- Jika fee diedit besok, report transaksi lama tidak berubah.
- Settlement dan komisi historis tetap akurat.

### 5.3 Tabel Fee Target

Disarankan:

- `fee_schemes`
- `fee_snapshots`

Minimal field `fee_schemes`:

- id
- owner_type: superadmin, ma, agent, merchant
- owner_id
- merchant_mdr_percent
- base_mdr_percent
- payin_fee_percent
- settlement_fee_percent
- ma_fee_percent
- agent_fee_percent
- toko_fee_percent
- effective_from
- effective_to
- created_by_user_id
- created_at
- updated_at

## 6. Gateway dan Sync Transaksi

### 6.1 Gateway yang Didukung

- Hilogate
- Alpha
- Artageto
- KingsPay

Semua gateway harus memakai interface:

```php
interface GatewayClientInterface
{
    public function pullTransactions(Merchant $merchant, array $filters = []): array;
    public function createMerchant(array $payload): array;
}
```

### 6.2 Callback Flow

```text
Gateway
  -> /api/callbacks/{gateway}/{type}
  -> verify signature
  -> resolve merchant
  -> normalize payload
  -> upsert topup_requests
  -> preserve checklist fields
  -> rebuild merchant_daily_metrics
  -> write gateway_sync_logs
  -> return accepted
```

### 6.3 Polling Flow

Target polling:

```text
Every 8 seconds
  -> Sync all approved active merchants
  -> Pull max page_size latest transactions per merchant
  -> Upsert DB
  -> Rebuild metrics
  -> Log result
```

Rule:

- Jika cycle sebelumnya belum selesai, cycle berikutnya skip.
- Polling tidak boleh dipanggil dari render dashboard.
- Polling harus berjalan di scheduler/worker.
- Per merchant harus punya log sukses/gagal.
- Timeout satu merchant tidak boleh menghentikan merchant lain.

### 6.4 Sync Cursor

Tambahkan table `sync_cursors`:

- id
- gateway
- merchant_id
- cursor_type: transaction, settlement, balance
- last_synced_at
- last_gateway_ref_id
- last_payload_at
- meta
- created_at
- updated_at

Tujuan:

- Tidak menarik semua transaksi lama terus-menerus.
- Bisa resume jika worker restart.

## 7. Data Model Target

### 7.1 agents

Field utama:

- id
- code
- name
- email
- contact
- hg_group_id
- default_agent_fee_percent
- ma_id
- is_active
- created_at
- updated_at

### 7.2 merchants

Field utama:

- id
- agent_id
- ma_id
- slug
- name
- merchant_id
- merchant_key encrypted
- merchant_group_name
- merchant_group_id
- merchant_type: cm/script
- gateway
- approval_status
- topup_enabled
- topup_url
- transaction_callback_url
- withdrawal_callback_url
- api_ip_whitelist
- dashboard_ip_whitelist
- is_whitelist_enabled
- pic_email
- finance_email
- cs_email
- merchant_mdr_percent
- base_mdr_percent
- ma_fee_percent
- agent_fee_percent
- payin_fee_percent
- settlement_fee_percent
- onboarding_payload
- approved_at
- created_at
- updated_at

### 7.3 topup_requests

Field utama:

- id
- merchant_id
- gateway
- data_source
- payment_id
- gateway_ref_id unique
- rrn
- transaction_id
- member_username
- status: pending/success/expired/failed/rejected
- amount
- net_amount
- fee_amount
- fee snapshot fields
- is_processed
- processed_by_user_id
- checked_by_email
- checked_by_role
- processed_at
- submitted_at
- callback_received_at
- expires_at
- gateway_payload
- created_at
- updated_at

Index wajib:

- merchant_id, submitted_at
- merchant_id, status, submitted_at
- merchant_id, is_processed, submitted_at
- gateway, data_source, submitted_at
- gateway_ref_id unique
- rrn
- payment_id

### 7.4 merchant_daily_metrics

Sumber utama dashboard.

Field:

- id
- merchant_id
- agent_id
- ma_id
- metric_date
- gateway
- data_source
- trx_total
- trx_success
- trx_pending
- trx_expired
- amount_success
- net_success
- fee_total
- settled_total
- ticket_total
- created_at
- updated_at

Unique:

- merchant_id, metric_date, data_source

### 7.5 support_tickets

Field:

- id
- merchant_id
- topup_request_id
- ticket_code
- status
- note
- attachment_url
- created_by_user_id
- support_note
- support_updated_by_user_id
- support_updated_at
- closed_at
- created_at
- updated_at

### 7.6 gateway_sync_logs

Field:

- id
- merchant_id
- gateway
- direction: callback/pull/create_merchant/balance
- endpoint
- http_status
- status
- message
- request_meta
- response_meta
- started_at
- finished_at
- created_at
- updated_at

### 7.7 audit_logs

Field:

- id
- actor_user_id
- actor_role
- action
- target_type
- target_id
- before_payload
- after_payload
- ip_address
- user_agent
- created_at

## 8. Dashboard Data Rules

### 8.1 Dashboard Summary

Dashboard summary harus baca dari `merchant_daily_metrics`.

Contoh:

- total transaksi
- sukses
- pending
- expired
- volume sukses
- settlement
- ranking toko
- report agent

### 8.2 Detail Transaksi

Detail transaksi baca dari `topup_requests` dengan pagination.

Rule:

- Wajib ada filter tanggal default.
- Page size default 50.
- Max page size 200.
- Query tidak boleh unbounded untuk jutaan row.

### 8.3 Merchant Kosong

Jika merchant belum punya transaksi:

- total = 0
- volume = 0
- jangan tampilkan transaksi milik merchant lain
- jangan fallback ke dummy/static data

## 9. Route Target

### Auth

```text
GET  /login
POST /login
POST /logout
```

### Superadmin

```text
GET /superadmin
GET /superadmin/ma
GET /superadmin/agents
GET /superadmin/merchants
GET /superadmin/fees
GET /superadmin/reports
GET /superadmin/logs
```

### MA

```text
GET /ma
GET /ma/agents
GET /ma/merchants
GET /ma/approvals
GET /ma/fees
GET /ma/reports
```

### Agent

```text
GET /agent
GET /agent/merchants
GET /agent/create-store
GET /agent/requests
GET /agent/reports
```

### Merchant CS

```text
GET /portal/{merchant}/cs/tickets
GET /portal/{merchant}/cs/topup
GET /portal/{merchant}/cs/checklist
GET /portal/{merchant}/cs/history
```

### Merchant Finance

```text
GET /portal/{merchant}/finance/overview
GET /portal/{merchant}/finance/settlement
GET /portal/{merchant}/finance/report
GET /portal/{merchant}/finance/request-settlement
```

### Public Topup

```text
GET  /topup/{merchant}
POST /topup/{merchant}/submit
GET  /topup/{merchant}/status/{reference}
```

### Internal

```text
POST /internal/gateway-sync/run
GET  /internal/gateway-sync/status
```

Internal route wajib token.

## 10. API dan Action Target

### Checklist

Endpoint:

```text
PATCH /api/topup-requests/{topupRequest}/checklist
```

Rules:

- Hanya transaksi success yang boleh dichecklist.
- Hanya CS/admin merchant terkait yang boleh checklist.
- Update field:
  - is_processed
  - processed_by_user_id
  - checked_by_email
  - checked_by_role
  - processed_at
- Write audit log.

### Merchant Onboarding

Flow:

```text
User/Agent create merchant request
  -> pending_agent
Agent submit to MA
  -> pending_ma
MA fill fee/gateway
  -> approved
System create merchant on gateway
  -> save merchant_id/key
```

### Create Merchant Gateway

Saat merchant dibuat ke HG:

- api_ip_whitelist wajib berisi IP server production.
- is_whitelist_enabled default true.
- callback URL wajib otomatis.
- payment gateway IDs ikut default config.
- settlement type ikut default config.

## 11. Security Requirements

- Password harus hashed.
- Merchant secret/gateway key harus encrypted.
- Callback signature wajib diverifikasi.
- Callback trusted IP disediakan via config.
- Internal endpoint wajib token.
- Role authorization memakai policy/middleware.
- Semua request mutation memakai validation class.
- Semua sensitive data di UI masked.

## 12. Performance Requirements

- Dashboard initial load target < 2 detik untuk cached summary.
- Query report harus bounded by date.
- Gateway polling tidak boleh block request user.
- Summary dashboard memakai `merchant_daily_metrics`.
- Raw transaction table disiapkan untuk partition/archive bulanan.
- Queue worker harus punya retry dan timeout.
- Gateway timeout tidak boleh menghentikan whole sync cycle.

## 13. Migration Plan Dari Node

### Step 1 - Export Node Data

Export:

- admins/users
- portal_mas
- portal_agents
- merchants
- topup_requests
- topup_tickets
- system_settings

### Step 2 - Transform

Mapping:

```text
portal_mas -> users + ma profile
portal_agents -> agents
merchants -> merchants
topup_requests -> topup_requests
topup_tickets -> support_tickets
system_settings -> config/admin settings
```

### Step 3 - Import

Import ke Laravel DB:

- agents
- merchants
- users
- topup_requests
- support_tickets

### Step 4 - Rebuild Metrics

Run:

```bash
php artisan metrics:rebuild-daily YYYY-MM-DD
```

Untuk range besar, buat command date range.

### Step 5 - Compare

Bandingkan Laravel vs Node:

- total merchant
- total transaksi
- total sukses
- volume sukses
- per merchant
- per agent
- checklist count

### Step 6 - Parallel Run

- Node tetap production.
- Laravel baca DB clone atau hasil import.
- Callback Laravel bisa aktif sebagai secondary jika aman.

### Step 7 - Cutover Bertahap

Urutan rekomendasi:

1. CS/Finance read-only.
2. Agent dashboard.
3. MA dashboard.
4. Superadmin.
5. Public topup.
6. Gateway create merchant.

## 14. Acceptance Criteria

### Data

- Semua dashboard tampil dari DB.
- Merchant tanpa transaksi tampil 0.
- Tidak ada dummy transaction.
- Transaksi tidak masuk merchant salah.

### CM/Script

- CM melihat Top Up Request.
- CM melihat Success Checklist.
- Script tidak melihat Top Up Request.
- Script tidak melihat Success Checklist.
- Script route CM-only return 404.

### Checklist

- Checklist sukses tersimpan di DB.
- Refresh browser tetap checked.
- Logout/login tetap checked.
- Gateway sync tidak overwrite checklist.

### Gateway

- Callback valid accepted.
- Callback invalid signature rejected.
- Polling semua merchant berjalan.
- Error satu merchant tidak stop merchant lain.
- Sync log tersimpan.

### Fee

- Superadmin bisa create MA dengan fee dasar.
- MA bisa create agent dengan fee turunan.
- Toko otomatis pakai fee agent/MA.
- MDR final otomatis dihitung.
- Fee snapshot tersimpan per transaksi.

### Security

- Role tidak bisa akses merchant lain.
- Secret gateway masked/encrypted.
- Internal endpoint tanpa token rejected.
- Mutation punya audit log.

## 15. Prioritas Implementasi Laravel

### P0

- Real auth dan role.
- Import data Node.
- Query dashboard dari DB.
- CM/Script route guard.
- Checklist DB.
- Metrics rebuild.

### P1

- Real Hilogate client.
- Gateway polling 8 detik.
- Callback signature verification.
- Gateway sync logs.
- Internal sync status page.

### P2

- Fee scheme dan fee snapshot.
- MA create Agent.
- Agent create merchant.
- Gateway create merchant.
- IP whitelist default.

### P3

- Audit log lengkap.
- Report export Excel.
- Archive/partition transaksi.
- Monitoring worker.
- Alert sync failure.

## 16. Non-Goals Awal

Yang tidak wajib di fase awal:

- Menghapus Node production langsung.
- Membuat UI baru total dari nol.
- Realtime websocket.
- Multi-database sharding.
- Full settlement automation jika transaksi dashboard belum stabil.

## 17. Definisi Selesai Untuk Cutover

Laravel boleh menggantikan Node jika:

- Data dashboard Laravel sama dengan Node untuk minimal 7 hari sampling.
- Callback dan polling berjalan stabil.
- Tidak ada transaksi masuk merchant salah.
- Checklist aman.
- CM/Script route sesuai rule.
- Role scope aman.
- Gateway create merchant sudah menyimpan whitelist/callback default benar.
- Error gateway tercatat dan mudah dicek.

