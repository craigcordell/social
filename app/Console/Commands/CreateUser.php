<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('user:create {--name=} {--email=} {--password=} {--verified : Mark the user email as verified immediately}')]
#[Description('Create a user without exposing public registration routes')]
class CreateUser extends Command
{
    public function __construct(private readonly CreateNewUser $creator)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?: text(
            label: 'Name',
            validate: ['name' => ['required', 'string', 'max:255']],
        );

        $email = $this->option('email') ?: text(
            label: 'Email',
            validate: ['email' => ['required', 'string', 'email', 'max:255']],
        );

        $plainTextPassword = $this->option('password') ?: password(
            label: 'Password',
            required: 'The password is required.',
        );

        if (! $this->option('password')) {
            $passwordConfirmation = password(
                label: 'Confirm password',
                required: 'The password confirmation is required.',
            );

            if ($plainTextPassword !== $passwordConfirmation) {
                $this->components->error('The password confirmation does not match.');

                return self::FAILURE;
            }
        }

        $user = $this->creator->create([
            'name' => $name,
            'email' => $email,
            'password' => $plainTextPassword,
            'password_confirmation' => $plainTextPassword,
        ]);

        if ($this->option('verified')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->components->info("Created user {$user->email}");

        if ($this->option('verified')) {
            $this->components->info('Email marked as verified.');
        }

        return self::SUCCESS;
    }
}
