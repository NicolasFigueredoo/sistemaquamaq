<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['name' => $user->name, 'role' => $user->role->value] : null,
            ],
            'panel' => [
                'timezone' => config('telemetry.display_timezone'),
                'pollSeconds' => config('telemetry.panel.poll_seconds'),
            ],
        ];
    }
}
