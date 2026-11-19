<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El prototipo (unidad Exemys) queda instalado en Szykula Aguas Escobar S.A.
 * Se renombra la planta del gateway Exemys; lecturas, alarmas, CIP y notas siguen colgando de su id.
 * Si existía una Szykula de demo (solo gateways EM300), se borra para que no quede duplicada.
 */
return new class extends Migration
{
    private const NEW = ['code' => 'szykula-aguas-escobar-sa', 'name' => 'Szykula Aguas Escobar S.A.', 'location' => 'Escobar'];

    private const OLD = ['code' => 'quamaq-prueba-exemys', 'name' => 'Quamaq · Prueba Exemys', 'location' => 'GRD210'];

    public function up(): void
    {
        $plantId = $this->exemysPlantId();
        if (! $plantId) {
            return;
        }

        $demo = DB::table('plants')->where('code', self::NEW['code'])->where('id', '!=', $plantId)->value('id');
        if ($demo) {
            $realGateway = DB::table('gateways')->where('plant_id', $demo)->where('model', '!=', 'EM300')->exists();
            if ($realGateway) {
                throw new RuntimeException('Ya existe una planta Szykula con un equipo real: revisar a mano antes de migrar.');
            }
            DB::table('plants')->where('id', $demo)->delete();
        }

        DB::table('plants')->where('id', $plantId)->update(self::NEW + ['updated_at' => now()]);
    }

    public function down(): void
    {
        if ($plantId = $this->exemysPlantId()) {
            DB::table('plants')->where('id', $plantId)->update(self::OLD + ['updated_at' => now()]);
        }
    }

    private function exemysPlantId(): ?int
    {
        return DB::table('gateways')->where('model', 'Exemys')->value('plant_id');
    }
};
