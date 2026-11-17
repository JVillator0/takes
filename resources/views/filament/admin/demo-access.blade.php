{{-- Evaluator access on /admin/login (spec 7 issue 2): shown only while takes.demo.enabled. --}}
<x-filament::section compact :heading="__('console.demo_access.heading')" data-testid="admin-demo-access">
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('console.demo_access.body') }}</p>
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
        <dt class="font-medium text-gray-950 dark:text-white">{{ __('console.demo_access.email') }}</dt>
        <dd class="font-mono text-gray-700 dark:text-gray-200">{{ $email }}</dd>
        <dt class="font-medium text-gray-950 dark:text-white">{{ __('console.demo_access.password') }}</dt>
        <dd class="font-mono text-gray-700 dark:text-gray-200">{{ $password }}</dd>
    </dl>
</x-filament::section>
