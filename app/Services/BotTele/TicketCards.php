<?php

namespace App\Services\BotTele;

use App\Models\BotAttendance;
use App\Models\BotTicket;
use Illuminate\Support\Collection;

/**
 * Every Telegram text the bot sends, copied from the n8n nodes. Ticket cards
 * share one layout and differ only in which lines appear, so they are built
 * by card(); the node each variant came from is noted on the method.
 */
class TicketCards
{
    public const OPEN = '🔴';

    public const WAITING = '🟡';

    public const ASSIGNED = '🔵';

    public const PROGRESS = '🟣';

    public const RESOLVED = '🟢';

    public const FAILED = '⚫';

    public const ESCALATED = '🟠';

    public const SUPPORT_MENU = "Bot Support\nApa kendala yang sedang Anda alami?\nSilakan pilih salah satu kategori:";

    public const CATEGORY_PROMPTS = [
        'Merchant Details | User Role' => "Merchant Details | User Role\nMohon jelaskan kebutuhan perubahan atau pengecekan terkait merchant/user role secara detail.\nSertakan jika tersedia:\nMerchant Name / Merchant ID\nNama User / Email / Username\nRole saat ini\nRole yang diminta\nDetail perubahan atau kendala\nJika ada screenshot pendukung, kirim gambar beserta caption penjelasan.",
        'Technical Issue' => "Technical Issue\nMohon jelaskan kendala teknis yang sedang dialami secara detail.\nSertakan jika tersedia:\nMerchant Name / Merchant ID\nDetail kendala\nError message / response code\nWaktu kejadian\nRequest ID / Transaction ID\nJika ada screenshot pendukung, kirim gambar beserta caption penjelasan.\nSemakin lengkap informasi, semakin cepat tim kami melakukan pengecekan.",
        'IP Whitelist / VPN' => "IP Whitelist / VPN\nMohon kirim detail permintaan IP Whitelist atau VPN secara lengkap.\nSertakan informasi berikut jika relevan:\nMerchant Name / Merchant ID\nIP Address\nEnvironment : Production / Development\nRequest : IP Whitelist / VPN\nDetail kebutuhan atau tujuan akses\nJika ada screenshot atau informasi pendukung, kirim beserta caption penjelasan.\nUntuk IP Whitelist, pastikan IP yang dikirim adalah Public Static IP.",
        'Others' => "Others\nMohon jelaskan kebutuhan atau kendala Anda secara detail.\nSertakan informasi berikut jika relevan:\nMerchant Name / Merchant ID\nDetail kebutuhan atau kendala\nWaktu kejadian\nInformasi pendukung lainnya\nJika ada screenshot, kirim gambar beserta caption penjelasan.\nSemakin lengkap informasi, semakin cepat tim kami melakukan pengecekan.",
        'Create User' => "CREATE USER\n\nKirim 4 baris saja, tanpa menulis judul:\n1. Nama Merchant\n2. Nama Grup Merchant\n3. User Email (pisahkan dengan koma jika lebih dari satu)\n4. Role (pisahkan dengan koma jika lebih dari satu)\n\nContoh:\nKingspay H+1\nKingspay\nadmin@kingspay.com, finance@kingspay.com\nMaster, Finance\n\nSemua role berlaku untuk semua email dalam tiket ini.",
    ];

    public const NO_OPEN_TICKETS = 'Tidak ada ticket yang menunggu untuk di-TAKE.';

    public static function menuKeyboard(): array
    {
        return [
            [['text' => 'Merchant Details / User Role', 'callback_data' => 'ticket_dashboard'], ['text' => 'IP Whitelist / VPN', 'callback_data' => 'ticket_whitelist']],
            [['text' => 'Technical Issue', 'callback_data' => 'ticket_api'], ['text' => 'Others', 'callback_data' => 'ticket_other']],
            [['text' => 'Create User', 'callback_data' => 'ticket_create_user']],
        ];
    }

    public static function takeKeyboard(string $ticketId): array
    {
        return [[['text' => 'Take Ticket', 'callback_data' => 'take_'.$ticketId], ['text' => 'Reject', 'callback_data' => 'reject_'.$ticketId]]];
    }

    public static function startKeyboard(string $ticketId): array
    {
        return [[['text' => 'Start Investigation', 'callback_data' => 'progress_'.$ticketId]]];
    }

    public static function actionKeyboard(string $ticketId): array
    {
        return [
            [['text' => 'Send Update', 'callback_data' => 'update_'.$ticketId]],
            [['text' => 'Resolve', 'callback_data' => 'resolve_'.$ticketId], ['text' => 'Failed', 'callback_data' => 'failed_'.$ticketId]],
        ];
    }

    public static function approvalKeyboard(string $ticketId): array
    {
        return [[['text' => 'Approve', 'callback_data' => 'ipapprove_'.$ticketId], ['text' => 'Reject', 'callback_data' => 'ipreject_'.$ticketId]]];
    }

    public static function createUserError(string $missing): string
    {
        return 'Format Create User tidak valid: '.$missing."\n\nKirim 4 baris tanpa judul. Pisahkan email dan role dengan koma.\n\nContoh:\nKingspay H+1\nKingspay\nadmin@kingspay.com, finance@kingspay.com\nMaster, Finance";
    }

    /** "TG - Confirm Merchant" */
    public function merchantCreated(BotTicket $t): string
    {
        $tail = $t->status === 'WAITING_APPROVAL' ? ['Approval : Waiting for Approval'] : [];

        return $this->card($t, $t->status === 'WAITING_APPROVAL' ? self::WAITING : self::OPEN, group: false, reporter: 'link', tail: $tail);
    }

    /** "TG - Push Tech Ticket" */
    public function techCreated(BotTicket $t): string
    {
        return $this->card($t, self::OPEN, group: true, reporter: 'link');
    }

    /** "TG - Send Screenshot Tech" caption */
    public function screenshotCaption(BotTicket $t): string
    {
        return $t->ticket_id."\nCategory : ".self::e($t->category);
    }

    /** "TG - Push IP Approval" */
    public function ipApprovalRequest(BotTicket $t): string
    {
        return $this->card($t, self::WAITING, group: true, reporter: 'link', tail: ['Approval : Waiting for Approval']);
    }

    /** "Ticket Was Take" */
    public function alreadyTaken(BotTicket $t): string
    {
        return 'Ticket '.$t->ticket_id.' sudah diambil oleh '.$t->assigned_name.'.';
    }

    /** "TG - Edit Tech Ticket ASSIGNED" */
    public function techAssigned(BotTicket $t): string
    {
        return $this->card($t, self::ASSIGNED, group: true, reporter: 'link', approval: true, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'PIC')]);
    }

    /** "TG - Edit Merchant Ticket ASSIGNED" */
    public function merchantAssigned(BotTicket $t): string
    {
        return $this->card($t, self::ASSIGNED, group: false, reporter: 'link', tail: ['PIC : '.self::e($t->assigned_name), 'Last Update : Ticket telah diambil oleh Technical Support.']);
    }

    /** "TG - Edit Tech Ticket ON_PROGRESS" (the clicked card, first edit) */
    public function techProgressClicked(BotTicket $t): string
    {
        return $this->card($t, self::PROGRESS, group: true, reporter: 'link', approval: true, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'PIC'), 'Last Update : Investigation started.']);
    }

    /** "TG - Edit Tech Ticket ON_PROGRESS1" (the tech group card) */
    public function techProgressGroup(BotTicket $t): string
    {
        return $this->card($t, self::PROGRESS, group: true, reporter: null, approval: true, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'Tech'), 'Last Update : Investigation started.']);
    }

    /** "TG - Edit Merchant Ticket ON_PROGRESS" and "Edit Clicked Ticket ON_PROGRESS" */
    public function merchantProgress(BotTicket $t): string
    {
        return $this->card($t, self::PROGRESS, group: false, reporter: 'link', tail: ['PIC : '.self::e($t->assigned_name), 'Last Update : Investigation started.']);
    }

    /** "TG - Notify Merchant ON_PROGRESS1" */
    public function updatePrompt(BotTicket $t): string
    {
        return '<b>'.$t->ticket_id."</b>\nSilakan ketik update yang ingin dikirim ke merchant.\nContoh : Kami menemukan latency pada upstream dan sedang melakukan pengecekan network route.";
    }

    /** "Edit Tech Ticket UPDATE" */
    public function techUpdate(BotTicket $t, string $update): string
    {
        return $this->card($t, self::PROGRESS, group: true, reporter: 'link', approval: true, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'PIC'), 'Last Update : '.self::e($update)]);
    }

    /** "TG - Edit Merchant Ticket UPDATE" */
    public function merchantUpdate(BotTicket $t, string $update): string
    {
        return $this->card($t, self::PROGRESS, group: false, reporter: 'link', tail: ['PIC : '.self::e($t->assigned_name), 'Last Update : '.self::e($update)]);
    }

    /** "TG - Edit Clicked Ticket UPDATE" */
    public function clickedUpdate(BotTicket $t, string $update): string
    {
        return $this->card($t, self::PROGRESS, group: false, reporter: 'link', approval: true, tail: ['PIC : '.self::e($t->assigned_name), 'Last Update : '.self::e($update)]);
    }

    /** "TG - Edit Tech Ticket RESOLVED" */
    public function techResolved(BotTicket $t): string
    {
        return $this->card($t, self::RESOLVED, group: true, reporter: 'link', approval: true, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'PIC')]);
    }

    /** "TG - Edit Merchant Ticket RESOLVED" */
    public function merchantResolved(BotTicket $t): string
    {
        return $this->card($t, self::RESOLVED, group: false, reporter: 'link', tail: ['PIC : '.self::e($t->assigned_name)]);
    }

    /** "TG - Notify Merchant SOLVE1" */
    public function failedPrompt(BotTicket $t): string
    {
        return '<b>'.$t->ticket_id."</b>\nSilakan ketik alasan ticket dinyatakan FAILED.\nContoh : Issue membutuhkan tindakan dari pihak upstream provider dan belum dapat diselesaikan oleh Technical Support.";
    }

    /** "TG - Edit Tech Ticket FAILED" */
    public function techFailed(BotTicket $t, string $reason): string
    {
        return $this->card($t, self::FAILED, group: true, reporter: 'link', approval: true, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'PIC'), 'Reason : '.self::e($reason)]);
    }

    /** "TG - Edit Merchant Ticket FAILED" */
    public function merchantFailed(BotTicket $t, string $reason): string
    {
        return $this->card($t, self::FAILED, group: false, reporter: 'link', tail: ['PIC : '.self::e($t->assigned_name), 'Reason : '.self::e($reason)]);
    }

    /** "TG - Edit Clicked Ticket FAILED" */
    public function clickedFailed(BotTicket $t, string $reason): string
    {
        return $this->card($t, self::FAILED, group: false, reporter: 'link', approval: true, tail: ['PIC : '.self::e($t->assigned_name), 'Reason : '.self::e($reason)]);
    }

    /** "TG - Edit PIC Ticket ON_PROGRESS" (the clicked card) */
    public function picClicked(BotTicket $t): string
    {
        return $this->card($t, self::PROGRESS, group: true, reporter: null, tail: ['PIC : '.self::e($t->assigned_name), 'Last Update : Investigation started by PIC.']);
    }

    /** "TG - Edit Merchant Ticket PIC ON_PROGRESS1" (despite the name, the tech group card) */
    public function picGroup(BotTicket $t): string
    {
        return $this->card($t, self::PROGRESS, group: true, reporter: null, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'PIC'), 'Last Update : Investigation started by PIC.']);
    }

    /** "TG - Edit Merchant Ticket PIC ON_PROGRESS" */
    public function picMerchant(BotTicket $t): string
    {
        return $this->card($t, self::PROGRESS, group: false, reporter: null, tail: ['PIC : '.self::e($t->assigned_name), 'Last Update : Investigation sedang dilakukan oleh Technical Support.']);
    }

    /** "TG - Edit IP Approval Approved" */
    public function ipApprovedApprovalCard(BotTicket $t): string
    {
        return $this->card($t, self::OPEN, group: true, reporter: 'plain', tail: ['Approval : Approved', 'Approved By : '.self::e($t->approval_by_name)]);
    }

    /** "TG - Edit Merchant IP Approved" */
    public function ipApprovedMerchant(BotTicket $t): string
    {
        return $this->card($t, self::OPEN, group: false, reporter: 'plain', tail: ['Approval : Approved', 'Approved By : '.self::e($t->approval_by_name)]);
    }

    /** "TG - Push Approved IP Ticket" */
    public function ipApprovedTech(BotTicket $t): string
    {
        return $this->card($t, self::OPEN, group: true, reporter: 'link', tail: ['Approval : Approved', 'Approved By : '.self::e($t->approval_by_name)]);
    }

    /** "TG - Ask IP Rejection Note" */
    public function rejectionPrompt(BotTicket $t): string
    {
        return '<b>'.$t->ticket_id."</b>\nPlease type the rejection reason.";
    }

    /** "TG - Edit IP Approval Rejected" */
    public function ipRejectedApprovalCard(BotTicket $t): string
    {
        return $this->card($t, self::FAILED, group: true, reporter: 'plain', tail: ['Approval : Rejected', 'Rejected By : '.self::e($t->approval_by_name), 'Rejection Note : '.self::e($t->approval_note)]);
    }

    /** "TG - Edit Merchant IP Rejected" */
    public function ipRejectedMerchant(BotTicket $t): string
    {
        return $this->card($t, self::FAILED, group: false, reporter: 'plain', tail: ['Approval : Rejected', 'Rejection Note : '.self::e($t->approval_note)]);
    }

    /** "TG - Send Check Ticket Card" */
    public function checkTicket(BotTicket $t, int $waitingMinutes): string
    {
        return $this->card($t, self::OPEN, group: true, reporter: 'link', tail: ['Waiting Time : '.$waitingMinutes.' menit', 'Created At : '.($t->created_at ?: '-')]);
    }

    /** remind_tiket_mnt_v2 "HTTP Request": the OPEN reminder ladder card. */
    public function openReminder(BotTicket $t, int $reminderNumber, int $waitingMinutes, Collection $onShift): string
    {
        $lines = [
            $reminderNumber === 3 ? '<b>FINAL REMINDER 3/3</b>' : '<b>REMINDER '.$reminderNumber.'/3</b>',
            '<b>'.$t->ticket_id.'</b>',
            'Merchant Group : '.self::e($t->source_chat_title),
            'Category : '.self::e($t->category),
            'Reported By : '.self::link($t->requester_user_id, $t->requester_name, 'Requester'),
        ];
        if ($t->category === SupportFlow::IP_WHITELIST) {
            $lines[] = 'Approved By : '.self::link($t->approval_by_user_id, $t->approval_by_name, 'Approver');
        }
        $lines[] = 'Issue : '.self::e($t->description);
        $lines[] = 'Status : '.self::OPEN;
        $lines[] = 'Waiting Time : '.$waitingMinutes.' Minutes';
        // Not in n8n: tag everyone on shift (absen within 8 hours) and the leader, so the reminder notifies them.
        $lines[] = 'Tech On Shift : '.($onShift->isEmpty()
            ? 'belum ada yang absen'
            : $onShift->map(fn (BotAttendance $tech): string => self::link($tech->user_id, $tech->name, 'Tech'))->implode(', '));
        $leaderId = config('bottele.leader_user_id');
        if ($leaderId !== null && $leaderId !== '') {
            $lines[] = 'Leader : '.self::link($leaderId, config('bottele.leader_name'), 'Leader');
        }

        return implode("\n", $lines);
    }

    /** remind_tiket_mnt_v2 "TG - Send Auto Assigned Ticket to Tech" (private chat of the picked tech) */
    public function autoAssignedPrivate(BotTicket $t): string
    {
        return $this->card($t, self::WAITING, group: true, reporter: null, tail: ['PIC : '.self::e($t->assigned_name), 'Assignment : Auto']);
    }

    /** remind_tiket_mnt_v2 "Edit Master Tech Ticket ASSIGNED" */
    public function autoAssignedMaster(BotTicket $t): string
    {
        return $this->card($t, self::WAITING, group: true, reporter: null, tail: ['PIC : '.self::link($t->assigned_user_id, $t->assigned_name, 'Tech'), 'Assignment : Auto', 'Last Update : Ticket telah di-auto assign ke Technical Support.']);
    }

    /** Auto_Assign_Escalation "TG - Escalate Ticket to PIC" */
    public function escalationToPic(BotTicket $t, int $minutesSinceAutoAssign): string
    {
        return $this->card($t, self::ESCALATED, group: true, reporter: null, tail: ['PIC : '.self::e($t->assigned_name), 'Waiting Time : '.$minutesSinceAutoAssign.' Minutes', 'Note : Investigation has not been started.']);
    }

    /** Auto_Assign_Escalation "TG - Edit Master Tech Ticket ESCALATED" */
    public function escalatedMaster(BotTicket $t): string
    {
        return $this->card($t, self::ESCALATED, group: true, reporter: null, tail: ['Previous PIC : '.self::e($t->assigned_name), 'Last Update : Ticket dialihkan ke PIC karena investigasi belum dimulai.']);
    }

    public static function picStartKeyboard(string $ticketId): array
    {
        return [[['text' => 'Start Investigation', 'callback_data' => 'picprogress_'.$ticketId]]];
    }

    /** absen_expired "TG - Notify Attendance Expired" */
    public const ATTENDANCE_EXPIRED = "⏰ <b>MASA KEHADIRAN BERAKHIR</b>\n\nMasa kehadiran Anda selama <b>8 jam</b> telah berakhir.\n\n🔴 Status Anda sekarang: <b>TIDAK HADIR</b>\n\nAnda tidak akan menerima assignment ticket baru.\n\nSilakan lakukan <b>HADIR</b> kembali apabila Anda akan melanjutkan aktivitas.";

    /** PayGrid_-_IP_Whitelist_WA_Approval "Build Info + Poll" (WhatsApp markdown, not HTML) */
    public static function waApprovalInfo(string $ticketNo, string $merchantGroup, string $reportedBy, string $merchantName, string $description): string
    {
        return "*Approval IP Whitelist*\n{$ticketNo}\nMerchant Group : {$merchantGroup}\nCategory : IP Whitelist / VPN\nReported By : {$reportedBy}\nMerchant Name : {$merchantName}\nKeterangan : {$description}\n\nVote di poll di bawah ini untuk Approve/Reject.";
    }

    /** "TG - Send Check Ticket Card2" (attendance confirmation) */
    public function attendance(string $name, string $checkInAt, string $expiresAt): string
    {
        return 'Tech : <b>'.self::e($name)."</b>\nStatus : 🟢\nMulai : ".$checkInAt."\nAktif Sampai : ".$expiresAt."\nMasa Aktif : 8 Jam\nAnda aktif untuk menerima assignment Technical Support.";
    }

    /**
     * Shared ticket card layout. $reporter: 'link' (tg://user link), 'plain' (name only) or null (omitted).
     * $approval adds the "Approved By" lines when the ticket was an approved IP Whitelist request.
     */
    private function card(BotTicket $t, string $status, bool $group, ?string $reporter, bool $approval = false, array $tail = []): string
    {
        $lines = ['<b>'.$t->ticket_id.'</b>'];
        if ($group) {
            $lines[] = 'Merchant Group : '.self::e($t->source_chat_title);
        }
        $lines[] = 'Category : '.self::e($t->category);
        if ($reporter === 'link') {
            $lines[] = 'Reported By : '.self::link($t->requester_user_id, $t->requester_name, 'Requester');
        } elseif ($reporter === 'plain') {
            $lines[] = 'Reported By : '.self::e($t->requester_name, 'Requester');
        }
        $lines[] = 'Issue : '.self::e($t->description);
        $lines[] = 'Status : '.$status;
        if ($approval && $t->approval_status === 'APPROVED') {
            $lines[] = 'Approval : Approved';
            $lines[] = 'Approved By : '.self::e($t->approval_by_name);
        }

        return implode("\n", array_merge($lines, $tail));
    }

    /** JS `(value || default)` then the manual &/</> escape every n8n template used. */
    public static function e(mixed $value, string $default = '-'): string
    {
        $value = ($value === null || $value === '') ? $default : (string) $value;

        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    /**
     * Tickets that came from the web (WAHA approval) have no Telegram user id;
     * an empty tg://user link would make Telegram reject the whole message, so print the name only.
     */
    private static function link(mixed $userId, mixed $name, string $default): string
    {
        if ($userId === null || $userId === '') {
            return self::e($name, $default);
        }

        return '<a href="tg://user?id='.$userId.'">'.self::e($name, $default).'</a>';
    }
}
