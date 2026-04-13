<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GenerateApiToken extends Command
{
    protected $signature = 'api:token {email : Email del usuario} {--name=api-access : Nombre del token}';

    protected $description = 'Genera un token de API (Sanctum) para un usuario existente';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No se encontró un usuario con email: {$this->argument('email')}");
            return self::FAILURE;
        }

        $token = $user->createToken($this->option('name'));

        $this->info('Token generado exitosamente:');
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->warn('Guarda este token, no se mostrará de nuevo.');

        return self::SUCCESS;
    }
}
