<?php

namespace Tests\Feature;

use App\Models\BotAttendance;
use App\Models\BotSession;
use App\Models\BotTicket;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class BotTeleImportSheetsTest extends TestCase
{
    use UsesBotTeleDatabase;

    private const CONFIRM = 'This will write Bot_Tele history into the bottele database. Continue?';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'bottele').'.xlsx';
        $this->writeFixture($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** A few rows per sheet, including the dirty data seen in the real export. */
    private function writeFixture(string $path): void
    {
        $book = new Spreadsheet;

        $tickets = $book->getActiveSheet()->setTitle('Tickets');
        $tickets->fromArray([
            ['ticket_id', 'created_at', 'source_chat_id', 'category', 'status', 'assigned_user_id', 'has_attachment', 'handling_minutes', 'total_resolution_minutes', 'escalated', 'row_number'],
            ['HGT-20260821-1', '2026-08-21 10:00:00', -1003933837716, 'Technical Issue', 'RESOLVED', 701, 'TRUE', -25141, 3600, 'FALSE', 2],
            // The old n8n FAILED bug wrote a datetime into total_resolution_minutes.
            ['HGT-20260821-8796', '2026-08-21 11:00:00', -1003933837716, 'Others', 'FAILED', 701, 'FALSE', 120, '2026-08-21 12:00:00', '', 3],
            [null, null, null, null, null, null, null, null, null, null, null],
            ['HGT-20260822-3', '2026-08-22 09:00:00', -1001, 'Create User', 'OPEN', '', '', '', '', '', 5],
        ]);

        $sessions = $book->createSheet()->setTitle('session');
        $sessions->fromArray([
            ['user_id', 'chat_id', 'state', 'category', 'temp_ticket_id', 'updated_at'],
            [501, -1003933837716, 'SUPPORT_MENU', '', '', '2026-08-21 09:00:00'],
            // n8n appended a second row for the same user/chat; the newest one must win.
            [501, -1003933837716, 'WAITING_API_DESCRIPTION', 'Technical Issue', '', '2026-08-21 09:05:00'],
            [701, -1004445566939, 'WAITING_CS_UPDATE', 'CS_UPDATE', 'HGT-20260821-1', '2026-08-21 10:30:00'],
        ]);

        $attendance = $book->createSheet()->setTitle('attendance');
        $attendance->fromArray([
            ['user_id', 'chat_id', 'username', 'name', 'status', 'check_in_at', 'expires_at', 'active_ticket_id', 'expiry_notified', 'last_auto_assigned_at', 'updated_at'],
            [701, 701, 'tech1', 'Tech One', 'HADIR', '2026-08-21 08:00:00', '2026-08-21 16:00:00', 'HGT-20260821-1', 'FALSE', '', '2026-08-21 08:00:00'],
            [null, null, null, null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null, null, null, null],
            [702, 702, 'tech2', 'Tech Two', 'TIDAK_HADIR', '2026-08-20 08:00:00', '2026-08-20 16:00:00', '', 'TRUE', '', '2026-08-20 16:00:00'],
        ]);

        (new Xlsx($book))->save($path);
    }

    public function test_dry_run_previews_without_writing(): void
    {
        $this->artisan('bottele:import-sheets', ['--file' => $this->file])
            ->expectsOutputToContain('Dry-run Bot_Tele import')
            ->expectsOutputToContain('No data was written')
            ->assertSuccessful();

        $this->assertSame(0, BotTicket::query()->count());
        $this->assertSame(0, BotSession::query()->count());
        $this->assertSame(0, BotAttendance::query()->count());
    }

    public function test_commit_imports_dirty_data_and_is_idempotent(): void
    {
        $this->artisan('bottele:import-sheets', ['--file' => $this->file, '--commit' => true])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->expectsTable(['Sheet', 'Rows', 'Created', 'Updated', 'Skipped', 'Errors'], [
                ['Tickets', 4, 3, 0, 1, 0],
                ['session', 3, 2, 0, 1, 0],
                ['attendance', 4, 2, 0, 2, 0],
            ])
            ->expectsOutputToContain('Tickets row 3 (total_resolution_minutes): "2026-08-21 12:00:00" is not a number, stored NULL.')
            ->assertSuccessful();

        $resolved = BotTicket::findByTicketId('HGT-20260821-1');
        $this->assertSame(-1003933837716, (int) $resolved->source_chat_id);
        $this->assertSame(-25141, $resolved->handling_minutes);
        $this->assertSame(3600, $resolved->total_resolution_minutes);
        $this->assertTrue($resolved->has_attachment);
        $this->assertFalse($resolved->escalated);
        $this->assertSame('2026-08-21 10:00:00', $resolved->created_at);

        $failed = BotTicket::findByTicketId('HGT-20260821-8796');
        $this->assertNull($failed->total_resolution_minutes);
        $this->assertNull($failed->escalated);

        $open = BotTicket::findByTicketId('HGT-20260822-3');
        $this->assertNull($open->assigned_user_id);
        $this->assertFalse($open->has_attachment);

        $this->assertSame('WAITING_API_DESCRIPTION', BotSession::for(501, -1003933837716)->state);
        $this->assertSame(2, BotAttendance::query()->count());
        $this->assertTrue(BotAttendance::query()->where('user_id', 702)->value('expiry_notified'));

        // Running it again updates in place instead of duplicating.
        $this->artisan('bottele:import-sheets', ['--file' => $this->file, '--commit' => true])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->expectsTable(['Sheet', 'Rows', 'Created', 'Updated', 'Skipped', 'Errors'], [
                ['Tickets', 4, 0, 3, 1, 0],
                ['session', 3, 0, 2, 1, 0],
                ['attendance', 4, 0, 2, 2, 0],
            ])
            ->assertSuccessful();

        $this->assertSame(3, BotTicket::query()->count());
        $this->assertSame(2, BotSession::query()->count());
    }

    public function test_missing_file_fails(): void
    {
        $this->artisan('bottele:import-sheets', ['--file' => 'nope.xlsx'])
            ->expectsOutputToContain('Spreadsheet not found')
            ->assertFailed();
    }
}
