<?php

namespace Tests\Feature;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthEnhancementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_cannot_login(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Jane Admin',
            'email' => 'jane@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'Acme Corp',
            'company_address' => '456 Industrial Way',
        ])->assertCreated();

        $this->postJson('/api/login', [
            'email' => 'jane@acme.test',
            'password' => 'password123',
        ])->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_user_can_verify_email_with_code(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Jane Admin',
            'email' => 'jane@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'Acme Corp',
            'company_address' => '456 Industrial Way',
        ])->assertCreated();

        $user = User::where('email', 'jane@acme.test')->first();
        $code = '123456';

        EmailVerificationToken::where('user_id', $user->id)->delete();
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'token'),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/verify-email', [
            'email' => 'jane@acme.test',
            'code' => $code,
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/login', [
            'email' => 'jane@acme.test',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_password_reset_flow(): void
    {
        Mail::fake();
        $this->actingAsApiUser('admin');

        $plainToken = 'reset-token-123';

        DB::table('password_reset_tokens')->insert([
            'email' => User::where('company_id', $this->apiCompany->id)->first()->email,
            'token' => hash('sha256', $plainToken),
            'created_at' => now(),
        ]);

        $email = User::where('company_id', $this->apiCompany->id)->first()->email;

        $this->postJson('/api/reset-password', [
            'email' => $email,
            'token' => $plainToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'newpassword123',
        ])->assertOk();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }
}
