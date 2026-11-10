<?php

namespace Tests\Feature;

use App\Enums\PlantStatus;
use App\Enums\Role;
use App\Enums\VariableState;
use App\Models\PlantSetpoint;
use App\Models\PlantVariableState;
use App\Models\SetpointChange;
use App\Models\Variable;
use App\Services\Telemetry\TelemetryIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login_and_bad_credentials_fail(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Login'));

        $user = $this->user(Role::Admin);
        $this->post('/login', ['username' => $user->username, 'password' => 'mal'])
            ->assertSessionHasErrors(['username' => 'Usuario o contraseña incorrectos.']);
        $this->post('/login', ['username' => $user->username, 'password' => 'secreto123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_network_lists_plants_with_summary(): void
    {
        [$plant] = $this->makePlant('Planta Norte');
        $this->actingAs($this->user(Role::Auditor))
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Network')
                ->has('plants', 1)
                ->where('plants.0.code', 'planta-norte')
                ->where('summary.total', 1)
                ->where('summary.sin_conexion', 1));
    }

    public function test_plant_page_has_everything_the_screen_needs(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $now = CarbonImmutable::now()->startOfSecond();
        app(TelemetryIngestor::class)->telemetry($gateway, $this->payload($now->getTimestampMs(), ['ozone' => 0.45]), $now);

        $this->actingAs($this->user(Role::Admin))
            ->get("/plantas/{$plant->code}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Plant')
                ->where('plant.status', 'alarma')
                ->has('variables', 6)
                ->where('banner.kind', 'alarm')
                ->where('banner.variable', 'ozone')
                ->has('chart.ranges.1d.series.ozone')
                ->where('settings.disinfectant', 'ozone')
                ->where('settings.disinfectants.0.key', 'ozone')
                ->where('settings.disinfectants.1.key', 'chlorine_dioxide')
                ->has('cip', 2)
                ->where('can.configure', true)
                ->where('can.writeNotes', true));

        $this->actingAs($this->user(Role::Admin))->get("/plantas/{$plant->code}/cip")
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Cip')->where('settings.cipPeriodDays', 7));
    }

    public function test_asociado_only_sees_own_plant(): void
    {
        [$own] = $this->makePlant('Propia', 'GW-1');
        $other = \App\Models\Plant::create(['code' => 'ajena', 'name' => 'Ajena']);
        $user = $this->user(Role::Asociado, [$own]);

        $this->actingAs($user)->get('/')->assertRedirect("/plantas/{$own->code}");
        $this->actingAs($user)->get("/plantas/{$own->code}")
            ->assertInertia(fn (Assert $p) => $p->where('can.configure', false)->where('can.writeNotes', false)->where('showNetworkLink', false));
        $this->actingAs($user)->get('/plantas/ajena')->assertForbidden();
        $this->actingAs($user)->put("/plantas/{$own->code}/configuracion", [])->assertForbidden();
        $this->actingAs($user)->post("/plantas/{$own->code}/notas", ['body' => 'x'])->assertForbidden();
    }

    public function test_pantalla_sees_network_but_cannot_open_plants(): void
    {
        [$plant] = $this->makePlant('Única', 'GW-1');
        $user = $this->user(Role::Pantalla);

        $this->actingAs($user)->get('/')
            ->assertInertia(fn (Assert $p) => $p->component('Network')->where('canOpen', false)->where('summary.total', 1));
        $this->actingAs($user)->get("/plantas/{$plant->code}")->assertForbidden();
        $this->actingAs($user)->get("/plantas/{$plant->code}/cip")->assertForbidden();
        $this->actingAs($user)->post("/plantas/{$plant->code}/notas", ['body' => 'x'])->assertForbidden();
    }

    public function test_admin_updates_setpoints_and_current_value_is_rejudged(): void
    {
        [$plant, $gateway] = $this->makePlant();
        $now = CarbonImmutable::now()->startOfSecond();
        app(TelemetryIngestor::class)->telemetry($gateway, $this->payload($now->getTimestampMs(), ['ozone' => 0.52]), $now);
        $this->assertSame(PlantStatus::Conforme, $plant->fresh()->status);

        $admin = $this->user(Role::Admin);
        $valid = [
            'setpoints' => ['ozone' => 0.55, 'temperature' => 60, 'alkaline' => 0.3, 'chlorine' => 0.2, 'peracetic' => 0.1],
            'setpoints_high' => ['ozone' => 1],
            'disinfectant' => 'ozone',
            'cip_period_days' => 5,
        ];

        $this->actingAs($admin)->put("/plantas/{$plant->code}/configuracion", $valid)->assertRedirect();

        $this->assertSame(5, $plant->settings()->first()->cip_period_days);
        $state = PlantVariableState::where('plant_id', $plant->id)->where('variable_id', Variable::where('key', 'ozone')->value('id'))->first();
        $this->assertSame(VariableState::Alarma, $state->state, '0,52 ya no cumple con setpoint 0,55');
        $this->assertSame(PlantStatus::Alarma, $plant->fresh()->status);
        $this->assertSame(3, SetpointChange::count()); // ozone, cloro, período
        $this->assertEqualsWithDelta(0.2, PlantSetpoint::where('plant_id', $plant->id)->where('variable_id', Variable::where('key', 'chlorine')->value('id'))->value('setpoint'), 0.0001);
        $this->assertSame($admin->id, SetpointChange::first()->user_id);

        $this->actingAs($admin)->put("/plantas/{$plant->code}/configuracion", array_replace_recursive($valid, ['setpoints' => ['ozone' => 2]]))
            ->assertSessionHasErrors('setpoints_high.ozone');

        $this->actingAs($this->user(Role::Auditor))->put("/plantas/{$plant->code}/configuracion", $valid)->assertForbidden();
    }

    public function test_chlorine_dioxide_range_is_configurable(): void
    {
        [$plant] = $this->makePlant();
        $admin = $this->user(Role::Admin);
        $base = ['setpoints' => ['temperature' => 60, 'alkaline' => 0.3, 'chlorine' => 0.1, 'peracetic' => 0.1], 'disinfectant' => 'chlorine_dioxide', 'cip_period_days' => 7];
        $put = fn (float $min, float $max) => $this->actingAs($admin)->put("/plantas/{$plant->code}/configuracion",
            array_replace_recursive($base, ['setpoints' => ['chlorine_dioxide' => $min], 'setpoints_high' => ['chlorine_dioxide' => $max]]));

        $put(0.30, 0.60)->assertSessionHasNoErrors();
        $sp = \App\Models\PlantSetpoint::where('plant_id', $plant->id)->where('variable_id', Variable::where('key', 'chlorine_dioxide')->value('id'))->sole();
        $this->assertEqualsWithDelta(0.30, $sp->setpoint, 0.0001);
        $this->assertEqualsWithDelta(0.60, $sp->setpoint_high, 0.0001);
        $this->assertEqualsCanonicalizing(['disinfectant:chlorine_dioxide', 'chlorine_dioxide', 'chlorine_dioxide_high'], SetpointChange::pluck('field')->all());
        $this->assertSame('chlorine_dioxide', $plant->settings()->first()->disinfectant);

        $put(0.60, 0.40)->assertSessionHasErrors('setpoints_high.chlorine_dioxide');
    }

    public function test_auditor_saves_notes(): void
    {
        [$plant] = $this->makePlant();
        $auditor = $this->user(Role::Auditor);

        $this->actingAs($auditor)->post("/plantas/{$plant->code}/notas", ['body' => 'Revisar dosificador'])->assertRedirect();
        $this->actingAs($auditor)->get("/plantas/{$plant->code}")
            ->assertInertia(fn (Assert $p) => $p->where('note.body', 'Revisar dosificador')->where('note.author', 'Auditor'));
    }

    public function test_demo_seed_reproduces_the_mockup_counts(): void
    {
        $this->seed();
        $this->assertSame(
            ['alarma' => 3, 'atencion' => 6, 'conforme' => 32, 'sin_conexion' => 2],
            \App\Models\Plant::query()->get()->countBy(fn ($p) => $p->status->value)->sortKeys()->all(),
        );
    }

    public function test_web_installer_requires_token(): void
    {
        $this->get('/instalar')->assertNotFound();

        config(['telemetry.install_token' => 'abc123']);
        $this->get('/instalar?token=mal')->assertNotFound();
        $this->get('/instalar?token=abc123&demo=1')->assertOk()->assertSee('Listo.');
        $this->assertSame(43, \App\Models\Plant::count());
        $this->get('/instalar?token=abc123&demo=1')->assertOk()->assertSee('Ya hay usuarios cargados');
    }
}
