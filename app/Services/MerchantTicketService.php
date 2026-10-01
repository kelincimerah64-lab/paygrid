<?php

namespace App\Services;

use App\Jobs\NotifyIpWhitelistApproval;
use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\MerchantTicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class MerchantTicketService
{
    public const DEPARTMENTS = ['cs', 'tech', 'finance'];

    public const CS_CATEGORIES = [
        'settlement' => 'Settlement',
        'transaction' => 'Cek Transaksi',
        'topup' => 'Topup',
        'others' => 'Others',
    ];

    public const TECH_CATEGORIES = [
        'merchant_details' => 'Merchant Details/User Role',
        'ip_whitelist' => 'IP Whitelist/VPS',
        'technical_issue' => 'Technical Issue',
        'others' => 'Others',
    ];

    public const FINANCE_CATEGORIES = [
        'settlement' => 'Settlement',
        'missing_transaction' => 'Missing Transaction',
        'discrepancies_amount' => 'Discrepancies Amount',
        'others' => 'Others',
    ];

    public const DEPARTMENT_LABELS = [
        'cs' => 'CS',
        'tech' => 'Tech Support',
        'finance' => 'Finance',
    ];

    /**
     * The 5 merchant-facing category cards on the "Buat Tiket Support" wizard.
     * Each card picks a department + a default category to preselect - the
     * "Jenis Permasalahan" dropdown still lists that department's FULL real
     * category set (categoriesFor()), so every category that was reachable
     * via the old department/category selects (including topup and
     * ip_whitelist, which has its own approval workflow) stays reachable.
     */
    public const CARDS = [
        'transaksi' => ['label' => 'Transaksi', 'description' => 'Cek status transaksi, RRN, refund, dll.', 'icon' => '💳', 'department' => 'cs', 'default_category' => 'transaction', 'response' => '15 - 30 menit'],
        'settlement' => ['label' => 'Settlement', 'description' => 'Pertanyaan seputar pencairan dana', 'icon' => '🏦', 'department' => 'finance', 'default_category' => 'settlement', 'response' => '1 - 4 jam'],
        'teknis' => ['label' => 'Teknis', 'description' => 'Integrasi API, error, dll.', 'icon' => '⚙️', 'department' => 'tech', 'default_category' => 'technical_issue', 'response' => '1 - 4 jam'],
        'akun_user' => ['label' => 'Akun / User', 'description' => 'Akses akun, user baru, reset password', 'icon' => '👤', 'department' => 'tech', 'default_category' => 'merchant_details', 'response' => '1 - 4 jam'],
        'lainnya' => ['label' => 'Lainnya', 'description' => 'Pertanyaan lain', 'icon' => '💬', 'department' => 'cs', 'default_category' => 'others', 'response' => '1 - 4 jam'],
    ];

    public const PAYMENT_METHODS = ['QRIS', 'Virtual Account', 'E-Wallet', 'Transfer Bank', 'Lainnya'];

    /**
     * Extra structured fields + info banner keyed by the ACTUAL category
     * (the "Jenis Permasalahan" dropdown value), not by the outer card - so
     * switching Settlement -> Missing Transaction -> Discrepancies Amount
     * within the same card also changes the fields shown, not just the 5
     * top-level cards. All fields are optional - only `category` and
     * `description` are hard requirements for a ticket. `others` intentionally
     * stays generic (title + priority) since it's the catch-all everywhere.
     */
    public const CATEGORY_META = [
        'transaction' => [
            'banner' => 'Untuk mempercepat proses, mohon lengkapi RRN atau Reference ID transaksi.',
            'fields' => [
                ['key' => 'rrn', 'label' => 'RRN', 'type' => 'text', 'tooltip' => 'Retrieval Reference Number dari gateway pembayaran.', 'placeholder' => 'Contoh: 123456789012'],
                ['key' => 'reference_id', 'label' => 'Reference ID', 'type' => 'text', 'tooltip' => 'Reference ID dari sistem PayGrid, jika ada.', 'placeholder' => 'Contoh: PG-20260911-000123'],
                ['key' => 'transaction_date', 'label' => 'Tanggal Transaksi', 'type' => 'date'],
                ['key' => 'amount', 'label' => 'Jumlah Transaksi', 'type' => 'number', 'placeholder' => 'Contoh: 100000'],
                ['key' => 'payment_method', 'label' => 'Metode Pembayaran', 'type' => 'select', 'options' => self::PAYMENT_METHODS],
            ],
        ],
        'topup' => [
            'banner' => 'Untuk mempercepat proses, mohon lengkapi RRN dan tanggal topup yang bermasalah.',
            'fields' => [
                ['key' => 'rrn', 'label' => 'RRN', 'type' => 'text', 'tooltip' => 'Retrieval Reference Number dari gateway pembayaran.', 'placeholder' => 'Contoh: 123456789012'],
                ['key' => 'reference_id', 'label' => 'Reference ID', 'type' => 'text', 'placeholder' => 'Contoh: PG-20260911-000123'],
                ['key' => 'transaction_date', 'label' => 'Tanggal Topup', 'type' => 'date'],
                ['key' => 'amount', 'label' => 'Jumlah Topup', 'type' => 'number', 'placeholder' => 'Contoh: 100000'],
                ['key' => 'payment_method', 'label' => 'Metode Pembayaran', 'type' => 'select', 'options' => self::PAYMENT_METHODS],
            ],
        ],
        'settlement' => [
            'banner' => 'Untuk mempercepat proses, mohon lengkapi tanggal dan jumlah settlement yang Anda harapkan.',
            'fields' => [
                ['key' => 'settlement_date', 'label' => 'Tanggal Settlement', 'type' => 'date'],
                ['key' => 'expected_amount', 'label' => 'Jumlah yang Diharapkan', 'type' => 'number', 'placeholder' => 'Contoh: 5000000'],
                ['key' => 'batch_reference', 'label' => 'Reference/Batch Settlement', 'type' => 'text', 'placeholder' => 'Contoh: STL-20260926-001'],
            ],
        ],
        'missing_transaction' => [
            'banner' => 'Sertakan RRN/Reference ID dan tanggal transaksi yang hilang agar tim finance lebih cepat menelusuri.',
            'fields' => [
                ['key' => 'rrn', 'label' => 'RRN Transaksi yang Hilang', 'type' => 'text', 'placeholder' => 'Contoh: 123456789012'],
                ['key' => 'transaction_date', 'label' => 'Tanggal Transaksi', 'type' => 'date'],
                ['key' => 'amount', 'label' => 'Jumlah Transaksi', 'type' => 'number', 'placeholder' => 'Contoh: 100000'],
            ],
        ],
        'discrepancies_amount' => [
            'banner' => 'Sertakan jumlah yang seharusnya dan yang diterima agar selisihnya bisa ditelusuri.',
            'fields' => [
                ['key' => 'expected_amount', 'label' => 'Jumlah Seharusnya', 'type' => 'number', 'placeholder' => 'Contoh: 5000000'],
                ['key' => 'received_amount', 'label' => 'Jumlah Diterima', 'type' => 'number', 'placeholder' => 'Contoh: 4900000'],
                ['key' => 'transaction_date', 'label' => 'Tanggal Transaksi', 'type' => 'date'],
            ],
        ],
        'merchant_details' => [
            'banner' => 'Sertakan nama/email user yang terkait agar permintaan lebih cepat diproses.',
            'fields' => [
                ['key' => 'target_user', 'label' => 'Nama/Email User Terkait', 'type' => 'text', 'placeholder' => 'Contoh: budi@toko.com'],
                ['key' => 'requested_role', 'label' => 'Role yang Diminta', 'type' => 'select', 'options' => ['CS INA', 'CS Merchant', 'IT INA', 'IT Merchant', 'Finance INA', 'Finance Merchant', 'PIC Merchant', 'Settlement INA']],
            ],
        ],
        'ip_whitelist' => [
            'banner' => 'Sertakan IP Address dan nama VPS/server yang ingin di-whitelist.',
            'fields' => [
                ['key' => 'ip_address', 'label' => 'IP Address', 'type' => 'text', 'placeholder' => 'Contoh: 103.10.20.30'],
                ['key' => 'server_name', 'label' => 'Nama VPS/Server', 'type' => 'text', 'placeholder' => 'Contoh: vps-jakarta-01'],
            ],
        ],
        'technical_issue' => [
            'banner' => 'Sertakan pesan error atau URL/endpoint terkait agar tim teknis lebih cepat menindaklanjuti.',
            'fields' => [
                ['key' => 'platform', 'label' => 'Platform/Modul Terkait', 'type' => 'select', 'options' => ['Web Dashboard', 'API Integrasi', 'VPS/Server', 'Lainnya']],
                ['key' => 'error_message', 'label' => 'Pesan Error', 'type' => 'text', 'placeholder' => 'Contoh: 500 Internal Server Error'],
                ['key' => 'url_endpoint', 'label' => 'URL/Endpoint Terkait', 'type' => 'text', 'placeholder' => 'Contoh: https://...'],
            ],
        ],
        'others' => [
            'banner' => null,
            'fields' => [
                ['key' => 'title', 'label' => 'Judul Singkat', 'type' => 'text', 'placeholder' => 'Contoh: Pertanyaan soal biaya admin'],
                ['key' => 'priority', 'label' => 'Tingkat Urgensi', 'type' => 'select', 'options' => ['Rendah', 'Sedang', 'Tinggi']],
            ],
        ],
    ];

    public function fieldsFor(string $category): array
    {
        return self::CATEGORY_META[$category]['fields'] ?? [];
    }

    public function bannerFor(string $category): ?string
    {
        return self::CATEGORY_META[$category]['banner'] ?? null;
    }

    public function fieldLabel(string $category, string $key): string
    {
        foreach ($this->fieldsFor($category) as $field) {
            if ($field['key'] === $key) {
                return $field['label'];
            }
        }

        return $key;
    }

    public function fieldType(string $category, string $key): ?string
    {
        foreach ($this->fieldsFor($category) as $field) {
            if ($field['key'] === $key) {
                return $field['type'];
            }
        }

        return null;
    }

    /**
     * Validation rules for the given category's extra structured fields -
     * all optional, typed per CATEGORY_META's `type`. Merge into the
     * controller's own validate() call.
     */
    public function fieldRules(string $category): array
    {
        $rules = [];
        foreach ($this->fieldsFor($category) as $field) {
            $rules[$field['key']] = match (true) {
                $field['key'] === 'title' => ['nullable', 'string', 'max:100'],
                $field['type'] === 'date' => ['nullable', 'date'],
                $field['type'] === 'number' => ['nullable', 'numeric', 'min:0'],
                $field['type'] === 'select' => ['nullable', \Illuminate\Validation\Rule::in($field['options'])],
                default => ['nullable', 'string', 'max:120'],
            };
        }

        return $rules;
    }

    /**
     * Picks the given category's extra fields out of an already-validated
     * array, dropping empties. `title` is skipped - it's a real
     * merchant_tickets column, not part of the JSON metadata blob. Returns
     * null (not an empty array) when nothing was filled in, so it stores as
     * SQL NULL rather than "{}".
     */
    public function metadataFrom(string $category, array $validated): ?array
    {
        $metadata = [];
        foreach ($this->fieldsFor($category) as $field) {
            if ($field['key'] === 'title') {
                continue;
            }
            $value = $validated[$field['key']] ?? null;
            if ($value !== null && $value !== '') {
                $metadata[$field['key']] = $value;
            }
        }

        return $metadata ?: null;
    }

    public function categoriesFor(string $department): array
    {
        return match ($department) {
            'tech' => self::TECH_CATEGORIES,
            'finance' => self::FINANCE_CATEGORIES,
            default => self::CS_CATEGORIES,
        };
    }

    public function departmentLabel(string $department): string
    {
        return self::DEPARTMENT_LABELS[$department] ?? ucfirst($department);
    }

    public function categoryLabel(string $department, string $category): string
    {
        return $this->categoriesFor($department)[$category] ?? $category;
    }

    public function create(Merchant $merchant, User $user, array $data, array $attachments = []): MerchantTicket
    {
        $needsApproval = $data['department'] === 'tech' && $data['category'] === 'ip_whitelist';

        $ticket = MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $user->id,
            'ticket_no' => 'PENDING',
            'department' => $data['department'],
            'category' => $data['category'],
            'title' => $data['title'] ?? null,
            'description' => $data['description'],
            'metadata' => $data['metadata'] ?? null,
            'attachments' => $this->storeAttachments($merchant, $attachments),
            'approval_status' => $needsApproval ? 'waiting' : null,
            'last_message_at' => now(),
        ]);

        $ticket->forceFill(['ticket_no' => 'TK-'.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT)])->save();

        if ($needsApproval) {
            NotifyIpWhitelistApproval::dispatch($ticket->id);
        }

        return $ticket;
    }

    private function storeAttachments(Merchant $merchant, array $attachments): array
    {
        return collect($attachments)
            ->filter()
            ->map(fn (UploadedFile $file) => [
                'disk' => 'local',
                'path' => $file->store('ticket-attachments/'.$merchant->id, 'local'),
                'name' => $file->getClientOriginalName(),
            ])
            ->values()
            ->all();
    }

    public function addMessage(MerchantTicket $ticket, User $user, string $body, bool $isStaff): MerchantTicketMessage
    {
        $message = $ticket->messages()->create([
            'user_id' => $user->id,
            'is_staff' => $isStaff,
            'body' => $body,
        ]);

        $ticket->forceFill(['last_message_at' => now()]);
        if ($isStaff && $ticket->status === 'open') {
            $ticket->status = 'in_progress';
        }
        $ticket->save();

        return $message;
    }
}
