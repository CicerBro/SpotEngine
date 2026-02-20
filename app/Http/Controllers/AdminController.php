<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RootCategory;
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
        /** @var \Illuminate\Support\Collection<int, object{category_code: string, count: int|string, latest: string}> $categoryRows */
        $categoryRows = Spot::query()
            ->toBase()
            ->selectRaw('category_code, COUNT(*) as count, MAX(spot_posted_at) as latest')
            ->groupBy('category_code')
            ->orderBy('category_code')
            ->get();

        $categoryStats = $categoryRows->map(
            /**
             * @param  object{category_code: string, count: int|string, latest: string}  $row
             */
            static function (object $row): object {
                $rootCode = strlen((string) $row->category_code) >= 2
                    ? substr((string) $row->category_code, 0, 2)
                    : $row->category_code;
                $root = RootCategory::tryFrom($rootCode);
                $categoryName = $root instanceof RootCategory ? $root->name : $row->category_code;

                return (object) [
                    'category_code' => $row->category_code,
                    'category_name' => $categoryName,
                    'count' => (int) $row->count,
                    'latest' => $row->latest,
                ];
            }
        );

        return view('admin.index', [
            'stats' => [
                'total_spots' => Spot::query()->count(),
                'total_users' => User::query()->count(),
                'category_stats' => $categoryStats,
            ],
            'usenetState' => UsenetState::all(),
        ]);
    }

    public function users(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->search.'%';
                $q->where(fn ($q) => $q
                    ->where('username', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('api_token', 'ilike', $term)
                );
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.users', [
            'users' => $users,
            'search' => $request->search,
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
