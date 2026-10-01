<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
