<?php

namespace App\Http\Controllers;

use App\Enums\PlantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NetworkController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $plants = $user->visiblePlants()->orderBy('name')->get(['id', 'code', 'name', 'location', 'status']);

        // Un asociado con una sola planta va directo a su pantalla.
        if (! $user->seesAllPlants() && $plants->count() === 1) {
            return redirect()->route('plants.show', $plants->first()->code);
        }

        $counts = collect(PlantStatus::cases())->mapWithKeys(
            fn (PlantStatus $s) => [$s->value => $plants->where('status', $s)->count()]
        );

        return Inertia::render('Network', [
            'plants' => $plants->map(fn ($p) => [
                'code' => $p->code,
                'name' => $p->name,
                'location' => $p->location,
                'status' => $p->status->value,
            ])->values(),
            'summary' => ['total' => $plants->count()] + $counts->all(),
            'updatedAt' => CarbonImmutable::now()->getTimestampMs(),
        ]);
    }
}
