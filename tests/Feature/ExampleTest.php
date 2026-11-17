<?php

declare(strict_types=1);

test('returns a successful response', function (): void {
    $this->get(route('home'))->assertOk();
});
