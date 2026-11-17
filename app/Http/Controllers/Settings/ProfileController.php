<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\UpdateCustomerProfileAction;
use App\DTOs\Auth\UpdateCustomerProfileDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer profile (starter kit page on the customer guard). No email
 * verification (D-030) and no self-deletion: customers are never
 * hard-deleted because money rows reference them (spec 3-01).
 */
final class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request, UpdateCustomerProfileAction $updateProfile): RedirectResponse
    {
        $updateProfile->execute($request->customer(), UpdateCustomerProfileDTO::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
