<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class EnsureProviderAdminCommand extends Command
{
    protected $signature = 'provider:ensure-admin
                            {--email=admin@1boxoffice.com : Admin email address}
                            {--password=password : Plain-text password (stored as bcrypt)}
                            {--first-name=Provider : First name}
                            {--last-name=Admin : Last name}';

    protected $description = 'Ensure a local provider-console admin exists in the shared users table.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');
        $storeId = (int) config('provider-auth.store_id');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email is required.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('A non-empty --password is required.');

            return self::FAILURE;
        }

        $user = User::query()
            ->where('email', $email)
            ->where('store_id', $storeId)
            ->first();

        if ($user === null) {
            $user = User::query()->create([
                'first_name' => (string) $this->option('first-name'),
                'last_name' => (string) $this->option('last-name'),
                'email' => $email,
                'password' => Hash::make($password),
                'status' => User::ACTIVE_STATUS,
                'user_type' => User::ADMIN_USER_TYPE,
                'store_id' => $storeId,
                'two_factor_enabled' => false,
            ]);

            $this->info("Created provider admin {$email} (id {$user->id}).");
        } else {
            $user->fill([
                'first_name' => (string) $this->option('first-name'),
                'last_name' => (string) $this->option('last-name'),
                'password' => Hash::make($password),
                'status' => User::ACTIVE_STATUS,
                'user_type' => User::ADMIN_USER_TYPE,
                'two_factor_enabled' => false,
            ]);
            $user->save();

            $this->info("Updated provider admin {$email} (id {$user->id}).");
        }

        $this->line('You can sign in at the provider console with the email and password above.');

        return self::SUCCESS;
    }
}
