<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function lastCode(string $to): string
    {
        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $m) use ($to, &$code) {
            if ($m->hasTo($to)) {
                $code = $m->code;
            }

            return true;
        });

        return $code;
    }

    public function test_login_accepts_username_or_email(): void
    {
        $user = $this->user(Role::Asociado);
        $user->forceFill(['email' => 'socio@example.com'])->save();

        $this->post('/login', ['username' => 'Socio@Example.com', 'password' => 'secreto123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        $this->post('/login', ['username' => $user->username, 'password' => 'secreto123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->user(Role::Asociado);
        $user->forceFill(['active' => false])->save();

        $this->post('/login', ['username' => $user->username, 'password' => 'secreto123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_user_sets_email_only_after_confirming_code(): void
    {
        $user = $this->user(Role::Asociado);
        $this->actingAs($user)->get('/perfil')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Profile')->where('profile.email', null));

        $this->post('/perfil/mail/codigo', ['email' => 'Nuevo@Example.com'])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->email);
        $code = $this->lastCode('nuevo@example.com');

        $this->put('/perfil/mail', ['email_code' => '000000' === $code ? '111111' : '000000'])->assertSessionHasErrors('email_code');
        $this->put('/perfil/mail', ['email_code' => $code])->assertSessionHasNoErrors();

        $this->assertSame('nuevo@example.com', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_already_used_by_another_user_is_rejected(): void
    {
        $this->user(Role::Auditor)->forceFill(['email' => 'tomado@example.com'])->save();

        $this->actingAs($this->user(Role::Asociado))
            ->post('/perfil/mail/codigo', ['email' => 'tomado@example.com'])
            ->assertSessionHasErrors('email');
        Mail::assertNothingSent();
    }

    public function test_password_change_requires_email_code(): void
    {
        $user = $this->user(Role::Asociado);
        $this->actingAs($user)->post('/perfil/contrasena/codigo')->assertSessionHasErrors('password_code');

        $user->forceFill(['email' => 'socio@example.com'])->save();
        $this->post('/perfil/contrasena/codigo')->assertSessionHasNoErrors();
        $code = $this->lastCode('socio@example.com');

        $this->put('/perfil/contrasena', ['password_code' => $code, 'password' => 'nueva-clave-1', 'password_confirmation' => 'otra'])
            ->assertSessionHasErrors('password');
        $this->put('/perfil/contrasena', ['password_code' => $code, 'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nueva-clave-1', $user->fresh()->password));

        // El código es de un solo uso.
        $this->put('/perfil/contrasena', ['password_code' => $code, 'password' => 'otra-clave-2', 'password_confirmation' => 'otra-clave-2'])
            ->assertSessionHasErrors('password_code');
    }

    public function test_code_is_invalidated_after_too_many_wrong_attempts(): void
    {
        $user = $this->user(Role::Asociado);
        $user->forceFill(['email' => 'socio@example.com'])->save();
        $this->actingAs($user)->post('/perfil/contrasena/codigo');
        $code = $this->lastCode('socio@example.com');
        $wrong = $code === '999999' ? '888888' : '999999';

        foreach (range(1, 5) as $_) {
            $this->put('/perfil/contrasena', ['password_code' => $wrong, 'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1']);
        }
        $this->put('/perfil/contrasena', ['password_code' => $code, 'password' => 'nueva-clave-1', 'password_confirmation' => 'nueva-clave-1'])
            ->assertSessionHasErrors('password_code');
        $this->assertTrue(Hash::check('secreto123', $user->fresh()->password));
    }

    public function test_forgot_password_with_code_sent_to_registered_email(): void
    {
        $user = $this->user(Role::Asociado);
        $user->forceFill(['email' => 'socio@example.com'])->save();

        $this->get('/olvide-contrasena')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('ForgotPassword')->where('step', 'request')->has('support.name'));

        $this->post('/olvide-contrasena/codigo', ['identifier' => $user->username])->assertRedirect('/olvide-contrasena');
        $this->get('/olvide-contrasena')->assertInertia(fn (Assert $p) => $p->where('step', 'code'));
        $code = $this->lastCode('socio@example.com');

        $this->post('/olvide-contrasena', ['code' => $code, 'password' => 'recuperada-1', 'password_confirmation' => 'recuperada-1'])
            ->assertRedirect('/login');

        $this->post('/login', ['username' => 'socio@example.com', 'password' => 'recuperada-1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_forgot_password_does_not_reveal_unknown_users_or_users_without_email(): void
    {
        $noEmail = $this->user(Role::Asociado);

        foreach (['no-existe', $noEmail->username] as $identifier) {
            RateLimiter::clear('forgot:127.0.0.1');
            $this->post('/olvide-contrasena/codigo', ['identifier' => $identifier])
                ->assertRedirect('/olvide-contrasena')
                ->assertSessionHasNoErrors();
            $this->post('/olvide-contrasena', ['code' => '123456', 'password' => 'recuperada-1', 'password_confirmation' => 'recuperada-1'])
                ->assertSessionHasErrors('code');
        }

        Mail::assertNothingSent();
        $this->assertTrue(Hash::check('secreto123', $noEmail->fresh()->password));
    }
}
