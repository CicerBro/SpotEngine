<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Spot;
use App\Models\UsenetState;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('admin.index', [
            'stats' => [
                'total_spots' => Spot::query()->count(),
                'total_users' => User::query()->count(),
                'category_stats' => Spot::query()
                    ->selectRaw('category_code, COUNT(*) as count, MAX(spot_posted_at) as latest')
                    ->groupBy('category_code')
                    ->orderBy('category_code')
                    ->get(),
            ],
            'latestSpots' => Spot::query()->orderBy('spot_posted_at', 'desc')->limit(10)->get(),
            'usenetState' => UsenetState::all(),
        ]);
    }

    public function users(): View
    {
        return view('admin.users', [
            'users' => User::query()->latest()->get(),
        ]);
    }

    public function createUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'unique:users'],
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'is_admin' => ['boolean'],
        ]);

        User::create($validated);

        return redirect()->route('admin.users')->with('success', 'User created.');
    }

    public function deleteUser(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, 'Cannot delete your own account.');

        $user->delete();

        return redirect()->route('admin.users')->with('success', 'User deleted.');
    }

    public function cleanOldSpots(Request $request): RedirectResponse
    {
        $days = max(1, (int) $request->input('days', 30));
        $deleted = Spot::query()->where('spot_posted_at', '<', now()->subDays($days))->delete();

        return redirect()->route('admin.index')->with('success', "Deleted {$deleted} spots older than {$days} days.");
    }
}
