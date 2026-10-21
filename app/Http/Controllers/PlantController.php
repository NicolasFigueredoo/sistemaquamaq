<?php

namespace App\Http\Controllers;

use App\Console\Commands\SyncExemys;
use App\Models\Plant;
use App\Services\Panel\PlantPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlantController extends Controller
{
    public function __construct(private PlantPresenter $presenter) {}

    public function show(Request $request, Plant $plant): Response
    {
        abort_unless($request->user()->canSeePlant($plant), 403);
        $now = CarbonImmutable::now();

        $variables = $this->presenter->variables($plant);
        $cip = $this->presenter->cipStatus($plant, $now);

        return Inertia::render('Plant', [
            'plant' => $this->presenter->summary($plant),
            'variables' => $variables,
            'settings' => $this->presenter->settings($plant),
            'banner' => $this->presenter->banner($plant, $variables, $cip),
            'cip' => $cip,
            'chart' => $this->presenter->series($plant, $now),
            'events' => $this->presenter->events($plant, $now),
            'note' => $this->presenter->note($plant),
            'can' => $this->presenter->permissions($request->user()),
            'serverNow' => $now->getTimestampMs(),
            'exemysTest' => $request->user()->isAdmin() && $plant->gateways()->where('model', SyncExemys::GATEWAY_MODEL)->exists(),
            'showNetworkLink' => $request->user()->seesAllPlants() || $request->user()->plants()->count() > 1,
        ]);
    }

    public function cip(Request $request, Plant $plant): Response
    {
        abort_unless($request->user()->canSeePlant($plant), 403);
        $now = CarbonImmutable::now();

        return Inertia::render('Cip', [
            'plant' => $this->presenter->summary($plant),
            'settings' => $this->presenter->settings($plant),
            'cip' => $this->presenter->cipStatus($plant, $now),
            'history' => $this->presenter->cipHistory($plant, $now),
            'showNetworkLink' => $request->user()->seesAllPlants() || $request->user()->plants()->count() > 1,
        ]);
    }
}
