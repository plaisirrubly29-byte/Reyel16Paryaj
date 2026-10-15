<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\IdentityAccess\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function requestLink(string $email): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(202);

        $token = null;
        Notification::assertSentTo(
            User::where('email', $email)->firstOrFail(),
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$token) {
                $token = $notification->token;

                return true;
            },
        );

        return $token;
    }

    private function reset(string $email, string $token, string $password = 'nouveaumdp456')
    {
        return $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_forgot_password_gives_the_same_answer_for_unknown_accounts(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@example.com']);

        $known->assertStatus(202);
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_repeated_requests_do_not_reveal_throttling(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $first = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com']);
        $second = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com']);

        $this->assertSame($first->json(), $second->json());
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_reset_link_points_to_the_frontend_and_is_queued(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);
        $token = $this->requestLink('a@example.com');

        $mail = (new ResetPasswordNotification($token))->toMail($user);

        $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?token=', $mail->actionUrl);
        $this->assertInstanceOf(ShouldQueue::class, new ResetPasswordNotification($token));
    }

    public function test_password_can_be_reset_once_with_a_valid_token(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        $token = $this->requestLink('a@example.com');

        $this->reset('a@example.com', $token)->assertOk();
        $this->assertTrue(Hash::check('nouveaumdp456', User::where('email', 'a@example.com')->value('password')));

        // Usage unique.
        $this->reset('a@example.com', $token, 'autremdp789')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');
    }

    public function test_expired_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        $token = $this->requestLink('a@example.com');

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->reset('a@example.com', $token)->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');
    }

    public function test_invalid_token_and_unknown_account_return_the_same_error(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $badToken = $this->reset('a@example.com', 'jeton-invalide');
        $unknown = $this->reset('inconnu@example.com', 'jeton-invalide');

        $badToken->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_token');
        $this->assertSame($badToken->json(), $unknown->json());
    }

    public function test_token_is_bound_to_its_account(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        User::factory()->create(['email' => 'b@example.com']);
        $token = $this->requestLink('a@example.com');

        $this->reset('b@example.com', $token)->assertStatus(422);
    }

    public function test_new_password_must_follow_the_policy(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        $token = $this->requestLink('a@example.com');

        $this->reset('a@example.com', $token, 'court')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_reset_closes_all_existing_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'a@example.com']);
        DB::table('sessions')->insert([
            ['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 's2', 'user_id' => null, 'payload' => '', 'last_activity' => time()],
        ]);

        $token = Password::broker()->createToken($user);
        $this->reset('a@example.com', $token)->assertOk();

        $this->assertSame(['s2'], DB::table('sessions')->pluck('id')->all());
    }
}
