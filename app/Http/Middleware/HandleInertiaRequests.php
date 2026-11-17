<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Lang groups shared with the client as UI copy (D-057). They hold no
     * secrets and no provider names (D-023).
     */
    private const COPY_GROUPS = ['takes', 'billing', 'landing'];

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
            'auth' => [
                'user' => $request->user(),
            ],
            // Only the boolean, never the key (spec 3-04 issue 4). Spec 4 shows the
            // "service not configured" banner and disables submit when false.
            'pixelforge' => [
                'configured' => app(PixelforgeClient::class)->isConfigured(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'copy' => Inertia::once(fn (): array => self::copy())->as('copy:'.self::copyVersion()),
        ];
    }

    /**
     * Customer-facing UI copy read on the client by `useCopy().t()` (D-057).
     *
     * An Inertia once prop: sent on the first page load and kept by the client
     * across visits. Its once key carries a hash of the lang files (see
     * copyVersion()), so a deploy that changes only copy re-sends it to tabs
     * that are already open; the asset version does not cover lang files.
     *
     * @return array<string, mixed>
     */
    private static function copy(): array
    {
        $copy = [];

        foreach (self::COPY_GROUPS as $group) {
            $copy[$group] = trans($group);
        }

        return $copy;
    }

    /**
     * Content hash of the shared lang files; it changes whenever a string does.
     */
    public static function copyVersion(): string
    {
        $contents = array_map(
            fn (string $group): string => File::get(lang_path("en/{$group}.php")),
            self::COPY_GROUPS,
        );

        return hash('xxh128', implode("\0", $contents));
    }
}
