<?php

namespace App\Console\Commands;

use App\Models\Citta;
use Illuminate\Console\Command;

class CreaCitta extends Command
{
    protected $signature = 'piedinauti:crea-citta {nome : Name of the city}';

    protected $description = 'Crea una nuova città';

    public function handle(): int
    {
        $nome = trim($this->argument('nome'));

        if ($nome === '') {
            $this->error('Il nome della città non può essere vuoto.');

            return self::FAILURE;
        }

        if (Citta::query()->where('nome', $nome)->exists()) {
            $this->error("Esiste già una città chiamata {$nome}.");

            return self::FAILURE;
        }

        Citta::query()->create(['nome' => $nome]);

        $this->info("Città creata: {$nome}.");

        return self::SUCCESS;
    }
}
