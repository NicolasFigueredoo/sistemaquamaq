<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Permite guardar modelos cuya clave primaria es compuesta (p. ej. plant_id + variable_id).
 * El modelo debe declarar: protected array $compositeKey = ['plant_id', 'variable_id'];
 */
trait HasCompositeKey
{
    public function getIncrementing(): bool
    {
        return false;
    }

    protected function setKeysForSaveQuery($query): Builder
    {
        foreach ($this->compositeKey as $key) {
            $query->where($key, '=', $this->getOriginal($key) ?? $this->getAttribute($key));
        }

        return $query;
    }

    protected function setKeysForSelectQuery($query): Builder
    {
        return $this->setKeysForSaveQuery($query);
    }
}
