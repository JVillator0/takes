<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Clusters\Billing\Pages\Reports;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Vite;

// QA #156: the console's custom Blade tables (Reports, Pixelforge usage) need their Tailwind utilities, which
// Filament's prebuilt CSS does not ship. The panel loads its own theme, compiled with @source over these views.

const ADMIN_THEME = 'resources/css/filament/admin/theme.css';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('loads the compiled admin theme on the console pages', function (): void {
    expect(Filament::getPanel('admin')->getViteTheme())->toBe(ADMIN_THEME);

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())
        ->get(Reports::getUrl())
        ->assertOk()
        ->assertSee(Vite::asset(ADMIN_THEME), escape: false);
});

it('compiles the utilities the custom tables use into the admin theme', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $css = (string) file_get_contents(public_path('build/'.$manifest[ADMIN_THEME]['file']));

    foreach (['.px-2{', '.tabular-nums{', '.text-end{', '.whitespace-nowrap{', '.border-t-2{', '.font-mono{'] as $utility) {
        expect($css)->toContain($utility);
    }
});
