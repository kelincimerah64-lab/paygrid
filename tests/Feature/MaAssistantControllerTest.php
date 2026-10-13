<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaAssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_string_history_from_the_frontend_is_accepted_not_rejected_as_invalid(): void
    {
        $ma = User::query()->create(['name' => 'MA', 'email' => 'ma-assistant-ctrl@paygrid.local', 'role' => 'ma', 'password' => 'secret']);

        $response = $this->actingAs($ma)->post(route('ma.assistant.ask'), [
            'message' => 'Halo, ada apa hari ini?',
            'history' => json_encode([
                ['role' => 'user', 'content' => 'Pertanyaan sebelumnya'],
                ['role' => 'assistant', 'content' => 'Jawaban sebelumnya'],
            ]),
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['reply']);
    }

    public function test_non_ma_role_is_forbidden(): void
    {
        $cs = User::query()->create(['name' => 'CS', 'email' => 'cs-assistant-ctrl@paygrid.local', 'role' => 'cs', 'password' => 'secret']);

        $response = $this->actingAs($cs)->post(route('ma.assistant.ask'), [
            'message' => 'Halo',
        ]);

        $response->assertForbidden();
    }

    public function test_multiple_attachments_of_different_file_types_are_accepted(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke, saya lihat lampirannya.']]]]],
            ]),
        ]);
        $ma = User::query()->create(['name' => 'MA', 'email' => 'ma-attach-ctrl@paygrid.local', 'role' => 'ma', 'password' => 'secret']);

        $response = $this->actingAs($ma)->post(route('ma.assistant.ask'), [
            'message' => 'Tolong cek lampiran ini',
            'attachments' => [
                UploadedFile::fake()->image('screenshot.png', 10, 10),
                UploadedFile::fake()->create('laporan.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('data.csv', 10, 'text/csv'),
            ],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['reply']);
        Http::assertSent(function ($request) {
            $inlineParts = array_filter($request->data()['contents'][0]['parts'] ?? [], fn ($p) => isset($p['inlineData']));

            return count($inlineParts) === 3;
        });
    }

    public function test_more_than_three_attachments_is_rejected(): void
    {
        $ma = User::query()->create(['name' => 'MA', 'email' => 'ma-attach-limit@paygrid.local', 'role' => 'ma', 'password' => 'secret']);

        $response = $this->actingAs($ma)->post(route('ma.assistant.ask'), [
            'message' => 'Terlalu banyak lampiran',
            'attachments' => [
                UploadedFile::fake()->image('a.png'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.png'),
                UploadedFile::fake()->image('d.png'),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    public function test_oversized_attachment_is_rejected(): void
    {
        $ma = User::query()->create(['name' => 'MA', 'email' => 'ma-attach-size@paygrid.local', 'role' => 'ma', 'password' => 'secret']);

        $response = $this->actingAs($ma)->post(route('ma.assistant.ask'), [
            'message' => 'File kegedean',
            'attachments' => [UploadedFile::fake()->create('big.pdf', 3000, 'application/pdf')],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }
}
