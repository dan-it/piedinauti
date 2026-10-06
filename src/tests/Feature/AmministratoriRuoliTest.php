<?php

namespace Tests\Feature;

use App\Enums\Ruolo;
use App\Models\Citta;
use App\Models\Fermata;
use App\Models\Linea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The global administrator's list of administrators: it also shows people who hold other roles,
 * can take the city administrator role away (with a warning when a city is left without one),
 * and filters by city. Managers and chaperones stay managed by city administrators only.
 */
class AmministratoriRuoliTest extends TestCase
{
    use RefreshDatabase;

    private User $globale;

    private Citta $milano;

    private Citta $torino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->globale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create(['cognome' => 'Aaa']);
        $this->milano = Citta::factory()->create(['nome' => 'Milano']);
        $this->torino = Citta::factory()->create(['nome' => 'Torino']);
    }

    /** @param  list<Ruolo>  $ruoli */
    private function persona(Citta $citta, array $ruoli, array $attributi = []): User
    {
        $persona = User::factory()->perCitta($citta)->create($attributi);

        foreach ($ruoli as $ruolo) {
            $persona->assegnaRuolo($ruolo);
        }

        return $persona;
    }

    // --------------------------------------------------------- the list

    public function test_l_elenco_mostra_anche_chi_ha_piu_ruoli(): void
    {
        $solo = $this->persona($this->milano, [Ruolo::AdminCitta], ['cognome' => 'Bbb']);
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile, Ruolo::Accompagnatore], ['cognome' => 'Ccc']);
        $this->persona($this->milano, [Ruolo::Responsabile], ['cognome' => 'Ddd']);       // not an administrator
        $this->persona($this->milano, [Ruolo::Accompagnatore], ['cognome' => 'Eee']);     // not an administrator

        $this->actingAs($this->globale)->get('/amministratori')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('amministratori/Index')
                ->has('amministratori', 3)      // the global administrator, the plain one and the mixed one
                ->where('amministratori.2.id', $misto->id)
                ->where('amministratori.2.ruoli', ['Amministratore di città', 'Responsabile', 'Accompagnatore'])
                ->where('amministratori.2.altri_ruoli', ['Responsabile', 'Accompagnatore'])
                ->where('amministratori.2.citta', 'Milano')
                ->where('amministratori.1.id', $solo->id)
                ->where('amministratori.1.altri_ruoli', []));
    }

    public function test_le_azioni_possibili_dipendono_dai_ruoli(): void
    {
        $solo = $this->persona($this->milano, [Ruolo::AdminCitta]);
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->actingAs($this->globale)->get('/amministratori')
            ->assertInertia(fn (Assert $p) => $p
                ->where('amministratori', fn ($elenco) => collect($elenco)->contains(fn ($r) => $r['id'] === $solo->id && $r['puo_eliminare'] === true && $r['puo_revocare'] === false))
                ->where('amministratori', fn ($elenco) => collect($elenco)->contains(fn ($r) => $r['id'] === $misto->id && $r['puo_eliminare'] === false && $r['puo_revocare'] === true)));
    }

    public function test_l_elenco_porta_le_citta_per_il_filtro_e_quelle_senza_amministratori(): void
    {
        $this->persona($this->milano, [Ruolo::AdminCitta]);
        $solaRuoli = $this->persona($this->torino, [Ruolo::Responsabile]);   // Torino has a manager, not an administrator

        $this->actingAs($this->globale)->get('/amministratori')
            ->assertInertia(fn (Assert $p) => $p
                ->has('citte', 2)
                ->where('citte.0.nome', 'Milano')
                ->where('amministratori.0.citta_id', null)               // the global administrator
                ->has('citte_senza_amministratori', 1)
                ->where('citte_senza_amministratori.0.nome', 'Torino'));

        $this->assertNotNull($solaRuoli->fresh());
    }

    // ------------------------------------------------------------- revoke

    public function test_si_revoca_il_ruolo_di_amministratore_e_gli_altri_restano(): void
    {
        $this->persona($this->milano, [Ruolo::AdminCitta], ['cognome' => 'Altro']);   // Milano keeps one
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile, Ruolo::Accompagnatore], ['nome' => 'Anna', 'cognome' => 'Neri']);

        $this->actingAs($this->globale)->post("/amministratori/{$misto->id}/revoca-ruolo-citta")
            ->assertRedirect()
            ->assertSessionHas('status', 'Anna Neri non è più amministratore di città. Resta: responsabile e accompagnatore.')
            ->assertSessionMissing('avviso');

        $misto = $misto->fresh();
        $this->assertFalse($misto->haRuolo(Ruolo::AdminCitta));
        $this->assertTrue($misto->haRuolo(Ruolo::Responsabile));
        $this->assertTrue($misto->haRuolo(Ruolo::Accompagnatore));
        $this->assertSame($this->milano->id, $misto->citta_id, 'still a person of the city');
    }

    public function test_un_avviso_se_la_citta_resta_senza_amministratori(): void
    {
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->actingAs($this->globale)->post("/amministratori/{$misto->id}/revoca-ruolo-citta")
            ->assertSessionHas('status')
            ->assertSessionHas('avviso', 'Attenzione: la città «Milano» non ha più amministratori. Invitane uno nuovo, altrimenti nessuno potrà gestirla.');

        $this->assertFalse($misto->fresh()->haRuolo(Ruolo::AdminCitta), 'the revocation goes through anyway');
    }

    public function test_l_avviso_conta_solo_gli_amministratori_della_stessa_citta(): void
    {
        $this->persona($this->torino, [Ruolo::AdminCitta]);                 // another city's administrator
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->actingAs($this->globale)->post("/amministratori/{$misto->id}/revoca-ruolo-citta")
            ->assertSessionHas('avviso');
    }

    public function test_dopo_la_revoca_la_citta_compare_tra_quelle_senza_amministratori(): void
    {
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->actingAs($this->globale)->post("/amministratori/{$misto->id}/revoca-ruolo-citta");

        $this->actingAs($this->globale)->get('/amministratori')
            ->assertInertia(fn (Assert $p) => $p
                ->where('citte_senza_amministratori.0.nome', 'Milano')
                ->where('amministratori', fn ($elenco) => ! collect($elenco)->contains(fn ($r) => $r['id'] === $misto->id))); // no longer listed: he is only a manager now
    }

    public function test_l_avviso_arriva_alla_pagina(): void
    {
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->actingAs($this->globale)->followingRedirects()->from('/amministratori')->post("/amministratori/{$misto->id}/revoca-ruolo-citta")
            ->assertInertia(fn (Assert $p) => $p->where('flash.avviso', fn ($testo) => str_contains($testo, 'Milano')));
    }

    public function test_chi_ha_solo_il_ruolo_di_amministratore_non_si_revoca_si_elimina(): void
    {
        $solo = $this->persona($this->milano, [Ruolo::AdminCitta]);

        $this->actingAs($this->globale)->post("/amministratori/{$solo->id}/revoca-ruolo-citta")
            ->assertSessionHas('errore', 'Questa persona ha solo il ruolo di amministratore di città: per toglierglielo eliminala.');

        $this->assertTrue($solo->fresh()->haRuolo(Ruolo::AdminCitta));
    }

    public function test_la_revoca_non_tocca_le_assegnazioni_del_responsabile(): void
    {
        $linea = Linea::factory()->create(['citta_id' => $this->milano->id]);
        $fermata = Fermata::factory()->create(['linea_id' => $linea->id, 'citta_id' => $this->milano->id, 'ordine' => 1]);
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile, Ruolo::Accompagnatore]);
        $linea->assegnaResponsabile($misto);
        $fermata->assegnaAccompagnatore($misto);

        $this->actingAs($this->globale)->post("/amministratori/{$misto->id}/revoca-ruolo-citta");

        $this->assertSame(1, $linea->responsabili()->count());
        $this->assertSame(1, $fermata->accompagnatori()->count());
    }

    public function test_solo_gli_amministratori_globali_revocano(): void
    {
        $adminCitta = $this->persona($this->milano, [Ruolo::AdminCitta]);
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->actingAs($adminCitta)->post("/amministratori/{$misto->id}/revoca-ruolo-citta")->assertForbidden();

        $this->assertTrue($misto->fresh()->haRuolo(Ruolo::AdminCitta));
    }

    public function test_non_si_revoca_a_chi_non_e_amministratore_di_citta(): void
    {
        $responsabile = $this->persona($this->milano, [Ruolo::Responsabile]);
        $altroGlobale = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        $this->actingAs($this->globale)->post("/amministratori/{$responsabile->id}/revoca-ruolo-citta")->assertForbidden();
        $this->actingAs($this->globale)->post("/amministratori/{$altroGlobale->id}/revoca-ruolo-citta")->assertForbidden();
    }

    // ------------------------------------------- what stays with city administrators

    public function test_chi_ha_piu_ruoli_non_si_elimina_da_qui(): void
    {
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->actingAs($this->globale)->delete("/amministratori/{$misto->id}")->assertForbidden();

        $this->assertNotNull($misto->fresh());
    }

    public function test_i_dati_di_chi_ha_piu_ruoli_si_correggono_e_l_invito_si_reinvia(): void
    {
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile], ['password' => null]);

        $this->actingAs($this->globale)->get("/amministratori/{$misto->id}/edit")->assertOk();
        $this->actingAs($this->globale)->put("/amministratori/{$misto->id}", ['nome' => 'Luisa', 'cognome' => 'Verdi', 'email' => $misto->email])
            ->assertRedirect('/amministratori');
        $this->actingAs($this->globale)->post("/amministratori/{$misto->id}/reinvia")->assertSessionHas('status');

        $this->assertSame('Luisa', $misto->fresh()->nome);
    }

    public function test_responsabili_e_accompagnatori_restano_fuori_dalla_portata(): void
    {
        $responsabile = $this->persona($this->milano, [Ruolo::Responsabile]);
        $accompagnatore = $this->persona($this->milano, [Ruolo::Accompagnatore]);

        foreach ([$responsabile, $accompagnatore] as $persona) {
            $this->actingAs($this->globale)->get("/amministratori/{$persona->id}/edit")->assertForbidden();
            $this->actingAs($this->globale)->put("/amministratori/{$persona->id}", ['nome' => 'X', 'cognome' => 'Y', 'email' => 'x@example.com'])->assertForbidden();
            $this->actingAs($this->globale)->delete("/amministratori/{$persona->id}")->assertForbidden();
            $this->actingAs($this->globale)->post("/amministratori/{$persona->id}/revoca-ruolo-citta")->assertForbidden();
        }

        $this->assertNotNull($responsabile->fresh());
        $this->assertNotNull($accompagnatore->fresh());
    }

    public function test_il_globale_non_cambia_i_ruoli_di_responsabile_e_accompagnatore(): void
    {
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        // Roles other than the administrator ones are given and taken by city administrators.
        $this->assertFalse($this->globale->can('assegnareRuoli', [$misto, [Ruolo::Accompagnatore]]));
        $this->assertFalse($this->globale->can('invitare', [User::class, $this->milano, [Ruolo::Responsabile]]));
    }

    // -------------------------------------------------- deleting, with a warning

    public function test_eliminando_l_ultimo_amministratore_di_una_citta_c_e_un_avviso(): void
    {
        $solo = $this->persona($this->milano, [Ruolo::AdminCitta]);

        $this->actingAs($this->globale)->delete("/amministratori/{$solo->id}")
            ->assertRedirect('/amministratori')
            ->assertSessionHas('avviso', fn ($testo) => str_contains($testo, 'Milano'));

        $this->assertNull($solo->fresh());
    }

    public function test_eliminando_un_amministratore_non_unico_non_ci_sono_avvisi(): void
    {
        $this->persona($this->milano, [Ruolo::AdminCitta]);
        $solo = $this->persona($this->milano, [Ruolo::AdminCitta]);

        $this->actingAs($this->globale)->delete("/amministratori/{$solo->id}")->assertSessionMissing('avviso');
    }

    public function test_eliminando_un_amministratore_globale_non_ci_sono_avvisi(): void
    {
        $altro = User::factory()->conRuolo(Ruolo::AdminGlobale)->create();

        $this->actingAs($this->globale)->delete("/amministratori/{$altro->id}")->assertSessionMissing('avviso');
    }

    // ---------------------------------------------------------- the policy

    public function test_la_policy_distingue_vedere_correggere_revocare_ed_eliminare(): void
    {
        $solo = $this->persona($this->milano, [Ruolo::AdminCitta]);
        $misto = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);
        $responsabile = $this->persona($this->milano, [Ruolo::Responsabile]);

        foreach ([$solo, $misto] as $persona) {
            $this->assertTrue($this->globale->can('view', $persona));
            $this->assertTrue($this->globale->can('update', $persona));
            $this->assertTrue($this->globale->can('revocareAmministratore', $persona));
        }

        $this->assertTrue($this->globale->can('delete', $solo));
        $this->assertFalse($this->globale->can('delete', $misto));

        foreach (['view', 'update', 'revocareAmministratore', 'delete'] as $abilita) {
            $this->assertFalse($this->globale->can($abilita, $responsabile), $abilita);
        }
    }

    public function test_un_amministratore_di_citta_non_ha_i_poteri_del_globale(): void
    {
        $adminCitta = $this->persona($this->milano, [Ruolo::AdminCitta]);
        $altroAdmin = $this->persona($this->milano, [Ruolo::AdminCitta, Ruolo::Responsabile]);

        $this->assertFalse($adminCitta->can('revocareAmministratore', $altroAdmin));
        $this->assertFalse($this->persona($this->milano, [Ruolo::Responsabile])->can('revocareAmministratore', $altroAdmin));
    }
}
