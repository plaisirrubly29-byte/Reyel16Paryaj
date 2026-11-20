<?php

namespace Tests\Feature\Modules\Settlement;

use App\Modules\Settlement\Jobs\SettleEventJob;
use App\Modules\Settlement\Services\SettlementService;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit du 18/11/2026, constat n° 6 : un règlement et une correction de résultat ne doivent pas s'intercaler.
 *
 * Le test du verrou exige PostgreSQL (SQLite ignore FOR UPDATE) et des données validées, visibles d'une seconde
 * connexion : il est ignoré sur SQLite (suite par défaut) ; à lancer sur une base PostgreSQL jetable, jamais sur une
 * base de travail :
 *   DB_CONNECTION=pgsql DB_DATABASE=<base_jetable_test> php artisan test --filter=SettlementLockTest
 * Garde-fou : ignoré si le nom de la base ne contient pas « test » (le test vide la base).
 */
class SettlementLockTest extends TestCase
{
    public function test_the_settlement_job_is_unique_only_while_it_waits_in_the_queue(): void
    {
        // Le job envoyé par une correction pendant qu'un règlement tourne n'est plus ignoré.
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, new SettleEventJob(1));
    }

    public function test_settlement_waits_for_the_match_lock_held_by_a_result_correction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Verrou de ligne PostgreSQL : base PostgreSQL requise.');
        }
        // migrate:fresh vide la base : jamais sur une base de travail.
        if (! str_contains(DB::getDatabaseName(), 'test')) {
            $this->markTestSkipped('Base PostgreSQL jetable requise (nom contenant « test ») : ce test vide la base.');
        }

        // Données validées (pas de transaction de test) : visibles de la seconde connexion. Base jetable seulement.
        $this->artisan('migrate:fresh')->assertSuccessful();
        try {
            $this->assertSettlementWaitsForTheMatchLock();
        } finally {
            // Base jetable remise à vide, réussite ou échec : aucune donnée pour les tests suivants de la même passe.
            $this->artisan('migrate:fresh')->assertSuccessful();
        }
    }

    private function assertSettlementWaitsForTheMatchLock(): void
    {
        $this->app->instance(SportsDataProvider::class, new FakeSportsDataProvider);
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $event = Event::query()->firstOrFail();

        // Seconde connexion : comme ResultRecorder pendant une correction, elle tient le verrou du match.
        config(['database.connections.pgsql_correction' => config('database.connections.pgsql')]);
        $correction = DB::connection('pgsql_correction');
        $correction->beginTransaction();
        $correction->table('events')->where('id', $event->id)->lockForUpdate()->first();

        DB::statement("SET lock_timeout = '300ms'");
        try {
            app(SettlementService::class)->settleEvent($event);
            $this->fail('Le règlement aurait dû attendre le verrou du match.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'lock_not_available attendu : le règlement prend le verrou du match.');
        } finally {
            $correction->rollBack();
            DB::statement('SET lock_timeout = 0');
            DB::disconnect('pgsql_correction');
        }

        // Verrou libéré : le règlement passe.
        app(SettlementService::class)->settleEvent($event);
    }
}
