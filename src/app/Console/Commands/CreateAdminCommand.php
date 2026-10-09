<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    protected $signature = 'crm:create-admin
                            {--email= : Email администратора}
                            {--name= : Имя администратора}
                            {--password= : Пароль (если не указан — будет сгенерирован и показан один раз)}';

    protected $description = 'Создать администратора CRM. Пароль не хранится в исходниках и сидерах.';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Email администратора'));
        $name = (string) ($this->option('name') ?: $this->ask('Имя администратора'));

        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = $this->option('password');

        if (! is_string($password) || $password === '') {
            // Generate password meeting Policy: min 12, mixed case, numbers, symbols
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            $password = '';
            for ($i = 0; $i < 16; $i++) {
                $password .= $chars[random_int(0, strlen($chars) - 1)];
            }
            // Ensure at least one of each required type
            $password[0] = 'a'; // lowercase
            $password[1] = 'A'; // uppercase
            $password[2] = '1'; // number
            $password[3] = '!'; // symbol
            $generated = true;
        }

        $passwordValidator = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($passwordValidator->fails()) {
            if (isset($generated)) {
                foreach ($passwordValidator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $this->error('Пароль должен содержать минимум 12 символов, заглавные и строчные буквы, цифры и символы.');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->info("Администратор создан: {$user->email} (ID {$user->id})");

        if (isset($generated)) {
            $this->warn('Сгенерированный пароль (показывается один раз):');
            $this->line($password);
            $this->warn('Сохраните его — восстановить будет невозможно.');
        }

        return self::SUCCESS;
    }
}
