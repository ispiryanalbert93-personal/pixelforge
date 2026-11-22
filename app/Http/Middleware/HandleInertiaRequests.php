<?php

namespace App\Http\Middleware;

use App\Http\Resources\WalletResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'is_development' => app()->environment(['local', 'development']),
            'auth' => [
                'user' => function () use ($request): ?array {
                    $user = $request->user();

                    if ($user === null) {
                        return null;
                    }

                    $user->load('wallet');

                    return [
                        ...$user->withoutRelations()->toArray(),
                        ...WalletResource::make($user->wallet)->resolve($request),
                    ];
                },
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'current_generation' => fn () => $request->session()->get('current_generation'),
            ],
        ];
    }
}
