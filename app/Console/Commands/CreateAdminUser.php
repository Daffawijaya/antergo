<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create {email? : Existing or new admin email}';

    protected $description = 'Safely create an admin account or grant admin to an existing user';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email');
        $existing = User::where('email', $email)->first();

        if ($existing) {
            $existing->addRole(UserRole::ADMIN);
            $existing->update(['is_active' => true]);
            $this->info('Admin role granted to the existing account.');

            return self::SUCCESS;
        }

        $data = [
            'email' => $email,
            'name' => $this->ask('Name'),
            'phone' => $this->ask('Phone'),
            'password' => $this->secret('Password'),
        ];
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:12'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'email' => $data['email'], 'name' => $data['name'], 'phone' => $data['phone'],
            'password' => Hash::make($data['password']), 'is_active' => true,
        ]);
        $user->addRole(UserRole::ADMIN);
        $this->info('Admin account created.');

        return self::SUCCESS;
    }
}
