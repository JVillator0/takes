<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\Billing\BillingReportFilterDTO;
use App\Enums\Auth\AdminPermission;
use App\Enums\Billing\Provider;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The Reports CSV download (spec 7, D-035): an admin holding `Export:Reports`, and the same filters as the page,
 * rebuilt from the query string and validated (real dates, ordered, at most 366 days).
 */
final class ExportBillingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user();

        return $admin instanceof User && $admin->can(AdminPermission::ExportReports->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d'],
            'group_by' => ['nullable', Rule::in([BillingReportFilterDTO::BY_DAY, BillingReportFilterDTO::BY_CUSTOMER])],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'provider' => ['nullable', Rule::enum(Provider::class)],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty()) {
                    try {
                        $this->filter();
                    } catch (InvalidArgumentException) {
                        $validator->errors()->add('until', __('console.reports.invalid_range', ['days' => BillingReportFilterDTO::MAX_DAYS]));
                    }
                }
            },
        ];
    }

    public function filter(): BillingReportFilterDTO
    {
        $customerId = $this->query('customer_id');
        $provider = $this->query('provider');
        $groupBy = $this->query('group_by');

        return BillingReportFilterDTO::fromArray([
            'from' => (string) $this->query('from'),
            'until' => (string) $this->query('until'),
            'group_by' => is_string($groupBy) && $groupBy !== '' ? $groupBy : BillingReportFilterDTO::BY_DAY,
            'customer_id' => is_string($customerId) && ctype_digit($customerId) ? (int) $customerId : null,
            'provider' => is_string($provider) && $provider !== '' ? $provider : null,
        ]);
    }

    public function admin(): User
    {
        $admin = $this->user();

        if (! $admin instanceof User) {
            abort(403);
        }

        return $admin;
    }
}
