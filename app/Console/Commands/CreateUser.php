<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    protected $signature = 'user:create
                            {username? : The username for the new user}
                            {password? : The password for the new user}
                            {--admin : Create as administrator}
                            {--email= : Email address}';

    protected $description = 'Create a new user account';

    public function handle(): int
    {
        $username = $this->argument('username') ?? $this->ask('Username');
        $password = $this->argument('password') ?? $this->secret('Password');
        $email = $this->option('email') ?? $this->ask('Email (optional)', '');
        $isAdmin = $this->option('admin');

        // Validate
        $validator = Validator::make([
            'username' => $username,
            'password' => $password,
            'email' => $email ?: null,
        ], [
            'username' => 'required|string|min:3|max:100|unique:users',
            'password' => 'required|string|min:6',
            'email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return Command::FAILURE;
        }

        // Confirm
        $this->info('');
        $this->info('Creating user with the following details:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Username', $username],
                ['Email', $email ?: '(none)'],
                ['Admin', $isAdmin ? 'Yes' : 'No'],
            ]
        );

        if (!$this->confirm('Do you want to create this user?', true)) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        // Create user
        try {
            $user = User::create([
                'username' => $username,
                'password' => $password,
                'email' => $email ?: null,
                'is_admin' => $isAdmin,
                'is_active' => true,
            ]);

            $this->info('');
            $this->info("User '{$username}' created successfully! (ID: {$user->id})");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create user: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
