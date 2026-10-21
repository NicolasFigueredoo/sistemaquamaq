<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Plantas y acceso ───────────────────────────────────────────────
        Schema::create('plants', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();            // slug, se usa en la URL
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('province')->nullable();
            $table->string('status', 20)->default('sin_conexion'); // conforme | atencion | sin_conexion | alarma
            $table->dateTime('status_changed_at')->nullable();
            $table->dateTime('last_reading_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'status']);
        });

        Schema::create('plant_user', function (Blueprint $table) {
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['plant_id', 'user_id']);
        });

        Schema::create('plant_settings', function (Blueprint $table) {
            $table->foreignId('plant_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cip_period_days')->default(7);
            $table->unsignedSmallInteger('cip_warning_hours')->default(24);
            $table->decimal('tank_volume_l', 10, 2)->default(1000);
            $table->decimal('product_liters', 10, 2)->default(18.2);
            $table->unsignedSmallInteger('offline_after_minutes')->default(10);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->string('serial', 80)->unique();          // va en el topic MQTT: ivess/{serial}/...
            $table->string('model', 40)->default('EM300');
            $table->string('firmware', 40)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('mqtt_username', 80)->nullable()->unique();
            $table->string('api_token_hash', 64)->nullable()->unique(); // sha256 del token para POST /api/ingest
            $table->dateTime('last_seen_at')->nullable();
            $table->boolean('online')->default(false);
            $table->timestamps();
        });

        // ── Variables y setpoints ─────────────────────────────────────────
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();             // ozone, temperature, alkaline, effective_time, chlorine
            $table->string('name');                          // "Ozono en llenadora"
            $table->string('short_name');                    // "Temperatura lavado" (tarjeta)
            $table->string('alert_name');                    // "Conductividad del producto alcalino" (banner)
            $table->string('unit', 20)->default('');
            $table->unsignedTinyInteger('decimals')->default(1);
            $table->string('kind', 10)->default('analog');   // analog | boolean
            $table->string('rule', 10)->default('min');      // min | max | presence
            $table->decimal('default_setpoint', 12, 4)->default(0);
            $table->decimal('setpoint_min', 12, 4)->nullable(); // rango permitido en el modal
            $table->decimal('setpoint_max', 12, 4)->nullable();
            $table->decimal('chart_min', 12, 4)->nullable();
            $table->decimal('chart_max', 12, 4)->nullable();
            $table->string('chart_color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);        // orden de tarjetas
            $table->unsignedSmallInteger('chart_sort')->nullable(); // null = sin gráfico
            $table->timestamps();
        });

        Schema::create('plant_setpoints', function (Blueprint $table) {
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->decimal('setpoint', 12, 4);
            $table->unsignedInteger('alarm_delay_s')->default(300); // tiempo fuera de parámetro antes de ser alarma
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['plant_id', 'variable_id']);
        });

        Schema::create('setpoint_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);                     // key de la variable o cip_period_days, tank_volume_l, product_liters
            $table->decimal('old_value', 12, 4)->nullable();
            $table->decimal('new_value', 12, 4)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('changed_at');
            $table->index(['plant_id', 'changed_at']);
        });

        // ── Mediciones ────────────────────────────────────────────────────
        Schema::create('readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('value', 12, 4);
            $table->dateTime('measured_at');                 // hora del gateway
            $table->dateTime('received_at');                 // hora de llegada al servidor
            // Si el EM300 reenvía su cola tras un corte, el duplicado se descarta solo.
            $table->unique(['plant_id', 'variable_id', 'measured_at']);
            $table->index('measured_at');
        });

        Schema::create('readings_hourly', function (Blueprint $table) {
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->dateTime('hour');
            $table->decimal('avg_value', 12, 4);
            $table->decimal('min_value', 12, 4);
            $table->decimal('max_value', 12, 4);
            $table->unsignedInteger('samples')->default(0);
            $table->primary(['plant_id', 'variable_id', 'hour']);
        });

        Schema::create('plant_variable_states', function (Blueprint $table) {
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->string('state', 12)->default('ok');      // ok | atencion | alarma
            $table->decimal('last_value', 12, 4)->nullable();
            $table->dateTime('last_measured_at')->nullable();
            $table->dateTime('out_since')->nullable();       // desde cuándo está fuera de parámetro
            $table->decimal('worst_value', 12, 4)->nullable();
            $table->unsignedBigInteger('open_alarm_event_id')->nullable();
            $table->primary(['plant_id', 'variable_id']);
        });

        // ── Alarmas y conexión ────────────────────────────────────────────
        Schema::create('alarm_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();        // null = activa
            $table->unsignedInteger('duration_s')->nullable();
            $table->decimal('setpoint_at_start', 12, 4);
            $table->decimal('worst_value', 12, 4);
            $table->index(['plant_id', 'started_at']);
        });

        Schema::create('connection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained()->cascadeOnDelete();
            $table->dateTime('offline_at');
            $table->dateTime('online_at')->nullable();
            $table->unsignedInteger('duration_s')->nullable();
            $table->index(['gateway_id', 'offline_at']);
        });

        // ── Trazabilidad CIP ──────────────────────────────────────────────
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                      // lavadora | llenadora
            $table->string('name');
            $table->timestamps();
            $table->unique(['plant_id', 'type']);
        });

        Schema::create('cip_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('plc_cycle_id', 80);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at');
            $table->unsignedInteger('total_min')->default(0);
            $table->string('result', 12);                    // finalizado | incompleto
            $table->timestamps();
            $table->unique(['equipment_id', 'plc_cycle_id']);
            $table->index(['equipment_id', 'result', 'finished_at']);
        });

        Schema::create('cip_cycle_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cip_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20);                     // saneado | enjuague | desinfeccion | enjuague_final
            $table->unsignedTinyInteger('position');
            $table->unsignedInteger('duration_min')->nullable();
            $table->boolean('completed')->default(true);
        });

        // ── Operación ─────────────────────────────────────────────────────
        Schema::create('auditor_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['plant_id', 'created_at']);
        });

        Schema::create('mqtt_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic');
            $table->longText('payload');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->index('processed_at');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        foreach ([
            'mqtt_inbox', 'auditor_notes', 'cip_cycle_stages', 'cip_cycles', 'equipment',
            'connection_events', 'alarm_events', 'plant_variable_states', 'readings_hourly',
            'readings', 'setpoint_changes', 'plant_setpoints', 'variables', 'gateways',
            'plant_settings', 'plant_user', 'plants',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
