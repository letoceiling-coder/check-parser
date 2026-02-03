<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create 
                            {username? : Username or email}
                            {password? : User password}
                            {name? : User full name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user (default: dsc-23@yandex.ru / 123123123 / Джон Уик)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Set default values if arguments are not provided
        $username = $this->argument('username') ?: 'dsc-23@yandex.ru';
        $password = $this->argument('password') ?: '123123123';
        $name = $this->argument('name') ?: 'Джон Уик';

        // Determine email and username
        // If username contains @, use it as email, otherwise generate email
        $email = filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : $username . '@example.com';
        $usernameField = filter_var($username, FILTER_VALIDATE_EMAIL) ? explode('@', $username)[0] : $username;

        // Check if user already exists by username or email
        if (User::where('username', $usernameField)->orWhere('email', $email)->exists()) {
            $this->error("Пользователь с username '{$usernameField}' или email '{$email}' уже существует!");
            return Command::FAILURE;
        }

        // Create user
        $user = User::create([
            'username' => $usernameField,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // Ensure UTF-8 encoding for output
        if (function_exists('mb_convert_encoding')) {
            $name = mb_convert_encoding($user->name, 'UTF-8', 'UTF-8');
        } else {
            $name = $user->name;
        }
        
        $this->info("Пользователь успешно создан!");
        $this->line("Username: {$user->username}");
        $this->line("Имя: {$name}");
        $this->line("Email: {$user->email}");

        return Command::SUCCESS;
    }
}
