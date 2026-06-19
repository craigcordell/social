<?php

use App\Models\User;

test('user create command creates a verified user', function () {
    $this->artisan('user:create', [
        '--name' => 'Internal Admin',
        '--email' => 'admin@example.com',
        '--password' => 'password',
        '--verified' => true,
    ])->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect($user->name)->toBe('Internal Admin')
        ->and($user->email_verified_at)->not->toBeNull();
});
