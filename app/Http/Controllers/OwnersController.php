<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwnersController extends Controller
{
    public function index(): View
    {
        return view('social.owners', [
            'owners' => Owner::query()->withCount('connectedAccounts')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:internal,vendor'],
            'external_id' => ['nullable', 'string', 'max:120'],
        ]);

        Owner::query()->create($data);

        return back()->with('status', 'Owner created.');
    }
}
