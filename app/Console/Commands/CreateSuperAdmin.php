<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\OneTimePassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * First-installation bootstrap. Options only, never interactive prompts (the
 * hosting platform cannot run them). Prints a one-time password once.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'user:create-super-admin {--name= : Full name} {--email= : Sign-in email}';

    protected $description = 'Create a super admin and print a one-time password';

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $email = mb_strtolower(trim((string) $this->option('email')));

        $validator = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = OneTimePassword::generate();
        $user = new User(['name' => $name, 'email' => $email, 'role' => Role::SuperAdmin, 'is_active' => true]);
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();

        Audit::record('user_created', $user, meta: ['role' => Role::SuperAdmin->value, 'via' => 'cli']);

        $this->line("Super admin created: {$email}");
        $this->line("One-time password (shown once, change it at first sign-in): {$password}");

        return self::SUCCESS;
    }
}
