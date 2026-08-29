<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserReadablePlainPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_decrypted_value_when_readable(): void
    {
        $user = User::factory()->create(['plain_password' => 'demo-password']);

        $this->assertSame('demo-password', $user->readablePlainPassword());
    }

    public function test_it_returns_null_instead_of_throwing_when_undecryptable(): void
    {
        $user = User::factory()->create(['plain_password' => 'demo-password']);
        DB::table('users')->where('id', $user->id)->update(['plain_password' => 'not-a-valid-encrypted-payload']);

        $this->assertNull($user->fresh()->readablePlainPassword());
    }
}
