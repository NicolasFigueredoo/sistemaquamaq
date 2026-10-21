<?php

namespace App\Http\Controllers;

use App\Models\Plant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuditorNoteController extends Controller
{
    public function store(Request $request, Plant $plant): RedirectResponse
    {
        abort_unless($request->user()->canWriteNotes() && $request->user()->canSeePlant($plant), 403);

        $data = $request->validate(['body' => ['nullable', 'string', 'max:20000']]);

        $plant->auditorNotes()->create([
            'user_id' => $request->user()->id,
            'body' => trim((string) ($data['body'] ?? '')),
        ]);

        return back();
    }
}
