<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('auth.profile', ['user' => $request->user()]);
    }

    public function regenerateApiKey(Request $request): RedirectResponse
    {
        $request->user()->regenerateApiKey();

        return redirect()->route('profile')->with('success', 'API key regenerated.');
    }
}
