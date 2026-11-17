<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CreateDemoCustomerAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /demo`, "Try a demo account" (D-045): a fresh ephemeral customer
 * with the welcome credit, signed in on the customer guard and sent to its
 * new project. Throttled per IP (`throttle:demo`, `rate_limited`). Off
 * (404) unless DEMO_LOGIN_ENABLED=true.
 */
final class DemoController extends Controller
{
    public function store(Request $request, CreateDemoCustomerAction $createDemoCustomer): RedirectResponse
    {
        abort_unless(config()->boolean('takes.demo.enabled'), 404);

        $demo = $createDemoCustomer->execute();

        Auth::guard('customer')->login($demo->customer);
        $request->session()->regenerate();

        return to_route('projects.show', $demo->project);
    }
}
