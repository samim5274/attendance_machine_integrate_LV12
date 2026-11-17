<?php

namespace App\Console\Commands\Zkteco;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ZktecoAdminUser extends Command
{
    protected $signature = 'zkteco:admin-user {email : Login e-mail} {--name=Administrator : Display name}';

    protected $description = 'Create (or reset the password of) a user who can open the attendance admin pages';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->secret('Password (min 8 characters)');
        $confirm = (string) $this->secret('Repeat password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8', 'confirmed']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::INVALID;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $user->name = $user->name ?: (string) $this->option('name');
        $user->password = $password; // hashed by the model cast
        $user->save();

        $this->components->info(($existed ? 'Password reset for ' : 'Created ').$email.'. Sign in at /admin/hr/attendance/devices (browser login prompt).');

        $allowed = (array) config('zkteco.admin_emails', []);
        if ($allowed !== [] && !in_array($email, array_map('strtolower', $allowed), true)) {
            $this->components->warn('This e-mail is not in ZKTECO_ADMIN_EMAILS, so it will get "403 Forbidden".');
        }

        return self::SUCCESS;
    }
}
