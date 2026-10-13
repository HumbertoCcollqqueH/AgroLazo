<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reset_link_uses_the_configured_mail_channel_for_a_normalized_email(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.test']);
        Notification::fake();

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'BUYER@EXAMPLE.TEST'])
            ->assertRedirectToRoute('password.request')->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->assertSame(['mail'], $notification->via($user));
            $this->assertSame('Recupera tu acceso a AgroLazo', $notification->toMail($user)->subject);
            $this->assertStringContainsString('/reset-password/', $notification->toMail($user)->actionUrl);

            return Password::tokenExists($user, $notification->token);
        });
    }

    public function test_unknown_and_disabled_addresses_receive_the_same_status_without_a_notification(): void
    {
        $user = User::factory()->inactive()->create();
        Notification::fake();

        $unknown = $this->post(route('password.email'), ['email' => 'unknown@example.test']);
        $unknownStatus = session('status');
        $disabled = $this->post(route('password.email'), ['email' => $user->email]);

        $unknown->assertSessionHas('status');
        $disabled->assertSessionHas('status', $unknownStatus);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_valid_token_resets_password_rotates_remember_token_and_cannot_be_replayed(): void
    {
        $user = User::factory()->create(['password' => 'PreviousSecure123']);
        $previousRememberToken = $user->remember_token;
        $token = Password::createToken($user);
        $data = ['token' => $token, 'email' => $user->email, 'password' => 'NewSecurePassword123', 'password_confirmation' => 'NewSecurePassword123'];

        $this->post(route('password.store'), $data)->assertRedirectToRoute('login');

        $this->assertTrue(Hash::check('NewSecurePassword123', $user->fresh()->password));
        $this->assertNotSame($previousRememberToken, $user->fresh()->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'actor_id' => $user->id]);
        $this->assertGuest();

        $this->post(route('password.store'), $data)->assertSessionHasErrors('email');
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_expired_token_does_not_change_password_or_write_an_audit_event(): void
    {
        $user = User::factory()->create(['password' => 'PreviousSecure123']);
        $this->freezeTime();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePassword123',
            'password_confirmation' => 'NewSecurePassword123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('PreviousSecure123', $user->fresh()->password));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_previous_authenticated_session_is_rejected_after_password_changes(): void
    {
        $user = User::factory()->create();
        $oldHash = Auth::guard('web')->hashPasswordForCookie($user->password);
        $user->forceFill(['password' => 'NewSecurePassword123'])->save();

        $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])
            ->get(route('dashboard'))->assertRedirectToRoute('login');

        $this->assertGuest();
    }
}
