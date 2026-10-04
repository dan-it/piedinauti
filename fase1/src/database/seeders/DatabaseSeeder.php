<?php

namespace Database\Seeders;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data for local development only. All demo accounts use the password "password".
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        User::factory()->conRuolo(Ruolo::AdminGlobale)->create([
            'nome' => 'Anna',
            'cognome' => 'Globale',
            'email' => 'admin@example.com',
        ]);

        $citta = Citta::factory()->create(['nome' => 'Città di prova']);

        User::factory()->perCitta($citta)->conRuolo(Ruolo::AdminCitta)->create([
            'nome' => 'Carlo',
            'cognome' => 'Città',
            'email' => 'admin-citta@example.com',
        ]);

        $responsabile = User::factory()->perCitta($citta)->conRuolo(Ruolo::Responsabile)->create([
            'nome' => 'Rita',
            'cognome' => 'Responsabile',
            'email' => 'responsabile@example.com',
        ]);

        $accompagnatore = User::factory()->perCitta($citta)->conRuolo(Ruolo::Accompagnatore)->create([
            'nome' => 'Aldo',
            'cognome' => 'Accompagnatore',
            'email' => 'accompagnatore@example.com',
        ]);

        $linea = Linea::factory()->create(['citta_id' => $citta->id, 'nome' => 'Linea Verde - Andata']);
        $linea->assegnaResponsabile($responsabile);

        $fermate = collect([
            ['Parco giochi', '07:40:00'],
            ['Via Roma', '07:50:00'],
            ['Scuola', '08:05:00'],
        ])->map(fn (array $dati, int $indice) => Fermata::factory()->create([
            'linea_id' => $linea->id,
            'citta_id' => $citta->id,
            'nome' => $dati[0],
            'orario' => $dati[1],
            'ordine' => $indice + 1,
        ]));

        $fermate[0]->assegnaAccompagnatore($accompagnatore);

        Bambino::factory()->count(4)->create(['citta_id' => $citta->id])
            ->each(fn (Bambino $bambino) => $fermate[0]->assegnaBambino($bambino));
    }
}
