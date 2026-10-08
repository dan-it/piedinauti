<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Bambino;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\Presenza;
use App\Models\User;
use App\Support\CittaCorrente;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PostgreSQL's row-level security, as a safety net under the application's own filters.
 *
 * Every query here goes straight to the tables (DB::table(), withoutGlobalScopes()), skipping the
 * model scopes on purpose: what is being tested is what the database itself refuses to show or
 * change while a request is limited to one city.
 */
class RlsTest extends TestCase
{
    use RefreshDatabase;

    private Citta $a;

    private Citta $b;

    private User $adminA;

    private User $adminB;

    private User $globale;

    private CittaCorrente $corrente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corrente = app(CittaCorrente::class);

        $this->a = Citta::factory()->create(['nome' => 'Alfa']);
        $this->b = Citta::factory()->create(['nome' => 'Beta']);
        $this->adminA = User::factory()->perCitta($this->a)->conRuolo(Ruolo::AdminCitta)->create(['email' => 'a@example.com']);
        $this->adminB = User::factory()->perCitta($this->b)->conRuolo(Ruolo::AdminCitta)->create(['email' => 'b@example.com']);
        $this->globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create(['email' => 'g@example.com']);

        Bambino::factory()->count(2)->create(['citta_id' => $this->a->id]);
        Bambino::factory()->count(3)->create(['citta_id' => $this->b->id]);

        foreach ([$this->a, $this->b] as $citta) {
            $linea = Linea::factory()->create(['citta_id' => $citta->id]);
            $fermata = Fermata::factory()->create(['linea_id' => $linea->id, 'citta_id' => $citta->id, 'ordine' => 1]);
            $bambino = Bambino::query()->where('citta_id', $citta->id)->firstOrFail();
            $fermata->assegnaBambino($bambino);
            Presenza::registra($fermata, $bambino, today(), true);
        }
    }

    /** Number of rows of a table seen straight from the database, as the current limit allows. */
    private function righe(string $tabella, ?int $cittaId = null): int
    {
        $query = DB::table($tabella);

        return $cittaId === null ? $query->count() : $query->where('citta_id', $cittaId)->count();
    }

    // ----------------------------------------------------- the safeguards

    public function test_l_applicazione_non_si_collega_con_un_ruolo_che_salta_la_rls(): void
    {
        $ruolo = DB::selectOne('select rolname, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertFalse(
            (bool) $ruolo->rolsuper || (bool) $ruolo->rolbypassrls,
            "Il database è collegato con il ruolo «{$ruolo->rolname}», che è un superutente o ha BYPASSRLS: la RLS non si applicherebbe. "
            .'Esegui scripts/passa-a-rls.sh (installazioni esistenti) o ricrea il database con docker/db/init (nuove installazioni).'
        );
    }

    public function test_ogni_tabella_con_una_citta_e_protetta_e_ha_una_regola(): void
    {
        $tabelle = collect(DB::select(<<<'SQL'
            SELECT c.relname AS nome, c.relrowsecurity AS attiva, c.relforcerowsecurity AS forzata,
                   (SELECT count(*) FROM pg_policies p WHERE p.schemaname = 'public' AND p.tablename = c.relname) AS regole
            FROM pg_class c
            WHERE c.relkind = 'r' AND c.relnamespace = 'public'::regnamespace
              AND (c.relname IN ('citta', 'utente_ruolo')
                   OR EXISTS (SELECT 1 FROM information_schema.columns k
                              WHERE k.table_schema = 'public' AND k.table_name = c.relname AND k.column_name = 'citta_id'))
            ORDER BY c.relname
        SQL));

        // Guards against a future table that carries a city and is forgotten here.
        $this->assertGreaterThanOrEqual(11, $tabelle->count());

        foreach ($tabelle as $tabella) {
            $this->assertTrue((bool) $tabella->attiva, "{$tabella->nome}: la RLS non è attiva");
            $this->assertTrue((bool) $tabella->forzata, "{$tabella->nome}: la RLS non è forzata anche sul proprietario della tabella");
            $this->assertGreaterThanOrEqual(1, (int) $tabella->regole, "{$tabella->nome}: manca la regola");
        }
    }

    // ------------------------------------------------------------ reading

    public function test_senza_limite_si_vede_tutto(): void
    {
        $this->assertSame(5, $this->righe('bambini'));
        $this->assertSame(2, $this->righe('citta'));
        $this->assertSame(3, $this->righe('users'));
    }

    public function test_limitati_a_una_citta_si_vede_solo_quella_anche_senza_filtri(): void
    {
        $this->corrente->limitaA($this->a->id);

        // Straight table queries and the models with their scopes switched off: no filter anywhere.
        $this->assertSame(2, $this->righe('bambini'));
        $this->assertSame(0, $this->righe('bambini', $this->b->id));
        $this->assertSame(2, Bambino::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, $this->righe('citta'));
        $this->assertSame(1, $this->righe('linee'));
        $this->assertSame(1, $this->righe('fermate'));
        $this->assertSame(1, $this->righe('fermata_bambino'));
        $this->assertSame(1, $this->righe('presenze'));
        $this->assertSame(1, User::query()->withoutGlobalScopes()->count());
    }

    public function test_una_join_senza_filtri_resta_nella_citta(): void
    {
        $this->corrente->limitaA($this->b->id);

        $righe = DB::table('presenze')->join('bambini', 'bambini.id', '=', 'presenze.bambino_id')->get();

        $this->assertCount(1, $righe);
        $this->assertSame($this->b->id, (int) $righe[0]->citta_id);
    }

    public function test_chi_non_ha_una_citta_non_vede_nulla(): void
    {
        $this->corrente->limitaA(null);

        foreach (['citta', 'users', 'utente_ruolo', 'bambini', 'linee', 'fermate', 'fermata_bambino', 'presenze'] as $tabella) {
            $this->assertSame(0, $this->righe($tabella), $tabella);
        }
    }

    public function test_l_amministratore_globale_non_e_visibile_a_una_richiesta_limitata(): void
    {
        $this->corrente->limitaA($this->a->id);

        $this->assertNull(DB::table('users')->where('email', 'g@example.com')->first());
        $this->assertNull(DB::table('users')->where('email', 'b@example.com')->first(), 'nor the other city\'s people');
        $this->assertNotNull(DB::table('users')->where('email', 'a@example.com')->first());
    }

    public function test_i_ruoli_seguono_le_persone(): void
    {
        $this->corrente->limitaA($this->a->id);

        $ruoli = DB::table('utente_ruolo')->get();

        $this->assertCount(1, $ruoli);
        $this->assertSame($this->adminA->id, (int) $ruoli[0]->user_id);
    }

    // ------------------------------------------------------------ writing

    public function test_non_si_scrive_in_un_altra_citta(): void
    {
        $this->corrente->limitaA($this->a->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        DB::table('bambini')->insert(['citta_id' => $this->b->id, 'nome' => 'Intruso', 'cognome' => '', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_si_scrive_nella_propria_citta(): void
    {
        $this->corrente->limitaA($this->a->id);

        DB::table('bambini')->insert(['citta_id' => $this->a->id, 'nome' => 'Giulia', 'cognome' => '', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(3, $this->righe('bambini'));
    }

    public function test_non_si_sposta_un_record_in_un_altra_citta(): void
    {
        $this->corrente->limitaA($this->a->id);
        $bambino = DB::table('bambini')->first();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        DB::table('bambini')->where('id', $bambino->id)->update(['citta_id' => $this->b->id]);
    }

    public function test_modificare_o_cancellare_record_di_altre_citta_non_ha_effetto(): void
    {
        $this->corrente->limitaA($this->a->id);

        $modificati = DB::table('bambini')->where('citta_id', $this->b->id)->update(['nome' => 'Hackerato']);
        $cancellati = DB::table('bambini')->where('citta_id', $this->b->id)->delete();
        $linee = DB::table('linee')->where('citta_id', $this->b->id)->delete();

        $this->assertSame(0, $modificati);
        $this->assertSame(0, $cancellati);
        $this->assertSame(0, $linee);

        $this->corrente->nessunLimite();
        $this->assertSame(3, $this->righe('bambini', $this->b->id));
        $this->assertSame(0, DB::table('bambini')->where('nome', 'Hackerato')->count());
    }

    public function test_un_ruolo_non_si_da_a_una_persona_di_un_altra_citta(): void
    {
        $this->corrente->limitaA($this->a->id);

        $this->expectException(QueryException::class);

        DB::table('utente_ruolo')->insert(['user_id' => $this->adminB->id, 'ruolo' => Ruolo::Accompagnatore->value]);
    }

    public function test_le_cancellazioni_a_cascata_funzionano_dentro_la_propria_citta(): void
    {
        $this->corrente->limitaA($this->a->id);

        DB::table('linee')->where('citta_id', $this->a->id)->delete();

        $this->assertSame(0, $this->righe('fermate'));
        $this->assertSame(0, $this->righe('fermata_bambino'));
        $this->assertSame(0, $this->righe('presenze'));

        $this->corrente->nessunLimite();
        $this->assertSame(1, $this->righe('presenze', $this->b->id), 'the other city is untouched');
    }

    // ------------------------------------------------ switching the limit

    public function test_senza_limiti_toglie_il_limite_e_poi_lo_rimette(): void
    {
        $this->corrente->limitaA($this->a->id);

        $totale = $this->corrente->senzaLimiti(fn () => $this->righe('bambini'));

        $this->assertSame(5, $totale);
        $this->assertSame(2, $this->righe('bambini'), 'the limit is back in the database');
        $this->assertTrue($this->corrente->limitato());
    }

    public function test_il_limite_torna_anche_se_la_funzione_fallisce_dentro_una_transazione(): void
    {
        $this->corrente->limitaA($this->a->id);

        try {
            DB::transaction(function () {
                $this->corrente->senzaLimiti(function () {
                    DB::select('select 1/0');
                });
            });
            $this->fail('the failing query should have raised an exception');
        } catch (QueryException $errore) {
            // The error reported is the real one, not "transaction aborted".
            $this->assertStringContainsString('division by zero', $errore->getMessage());
        }

        $this->assertSame(2, $this->righe('bambini'), 'still limited to the city');
        $this->assertTrue($this->corrente->limitato());
    }

    public function test_nessun_limite_dopo_un_limite(): void
    {
        $this->corrente->limitaA($this->a->id);
        $this->corrente->nessunLimite();

        $this->assertSame(5, $this->righe('bambini'));
    }

    // --------------------------------------------------------- web requests

    public function test_una_richiesta_web_e_limitata_dal_database_non_solo_dai_filtri(): void
    {
        // A route that deliberately asks the tables with no filter at all.
        Route::middleware('web')->get('/_prova/rls', fn () => response()->json([
            'bambini' => DB::table('bambini')->count(),
            'senza_scope' => Bambino::query()->withoutGlobalScopes()->count(),
            'utenti' => DB::table('users')->count(),
        ]));

        $this->actingAs($this->adminA)->get('/_prova/rls')->assertOk()->assertExactJson(['bambini' => 2, 'senza_scope' => 2, 'utenti' => 1]);
        $this->actingAs($this->adminB)->get('/_prova/rls')->assertOk()->assertExactJson(['bambini' => 3, 'senza_scope' => 3, 'utenti' => 1]);
        $this->actingAs($this->globale)->get('/_prova/rls')->assertOk()->assertExactJson(['bambini' => 5, 'senza_scope' => 5, 'utenti' => 3]);
    }

    public function test_finita_la_richiesta_il_database_torna_senza_limite(): void
    {
        Route::middleware('web')->get('/_prova/rls', fn () => response('ok'));

        $this->actingAs($this->adminA)->get('/_prova/rls')->assertOk();

        $this->assertNotSame('on', DB::selectOne("select current_setting('app.limitato', true) as v")->v);
        $this->assertSame(5, $this->righe('bambini'));
    }

    // ------------------------------------------------- email across cities

    public function test_un_indirizzo_gia_usato_in_un_altra_citta_si_rifiuta_con_un_errore_chiaro(): void
    {
        // Alfa's administrator cannot see Beta's people, yet the address is taken: a validation
        // message, not a database error.
        $this->actingAs($this->adminA)
            ->post('/persone', ['nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'b@example.com', 'ruoli' => ['accompagnatore']])
            ->assertSessionHasErrors('email');

        $this->actingAs($this->adminA)
            ->post('/persone', ['nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'B@Example.COM', 'ruoli' => ['accompagnatore']])
            ->assertSessionHasErrors('email');

        // Nobody was created: Beta's administrator is still the only person with that address.
        $this->assertSame(1, User::query()->withoutGlobalScopes()->whereRaw('LOWER(email) = ?', ['b@example.com'])->count());
    }

    public function test_un_indirizzo_libero_si_accetta(): void
    {
        $this->actingAs($this->adminA)
            ->post('/persone', ['nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'nuova@example.com', 'ruoli' => ['accompagnatore']])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()->withoutGlobalScopes()->where('email', 'nuova@example.com')->count());
    }

    public function test_modificando_una_persona_non_si_puo_prendere_l_indirizzo_di_un_altra_citta(): void
    {
        $persona = User::factory()->perCitta($this->a)->conRuolo(Ruolo::Accompagnatore)->create();

        $this->actingAs($this->adminA)
            ->put("/persone/{$persona->id}", ['nome' => 'X', 'cognome' => 'Y', 'email' => 'b@example.com', 'ruoli' => ['accompagnatore']])
            ->assertSessionHasErrors('email');

        // Keeping its own address is not a clash.
        $this->actingAs($this->adminA)
            ->put("/persone/{$persona->id}", ['nome' => 'X', 'cognome' => 'Y', 'email' => $persona->email, 'ruoli' => ['accompagnatore']])
            ->assertSessionHasNoErrors();
    }

    public function test_il_profilo_non_prende_l_indirizzo_di_un_altra_citta(): void
    {
        $this->actingAs($this->adminA)
            ->patch('/settings/profile', ['nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'b@example.com'])
            ->assertSessionHasErrors('email');

        $this->actingAs($this->adminA)
            ->patch('/settings/profile', ['nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'a@example.com'])
            ->assertSessionHasNoErrors();
    }

    public function test_l_amministratore_globale_vede_tutti_gli_indirizzi_gia_usati(): void
    {
        $this->actingAs($this->globale)
            ->post('/amministratori', ['nome' => 'Carlo', 'cognome' => 'Bianchi', 'email' => 'a@example.com', 'tipo' => 'citta', 'citta_id' => $this->b->id])
            ->assertSessionHasErrors('email');
    }
}
