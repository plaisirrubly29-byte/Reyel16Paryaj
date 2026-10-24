<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Exceptions\SportsDataException;
use Illuminate\Console\Command;

/**
 * Diagnostic du fournisseur : connexion, sports configurés disponibles, quota restant.
 * N'utilise que la liste des sports (gratuite chez The Odds API) : ne consomme aucun crédit.
 */
class CheckProviderCommand extends Command
{
    protected $signature = 'sports-data:check';

    protected $description = 'Vérifie la connexion au fournisseur de données sportives et le quota restant (sans coût)';

    public function handle(SportsDataProvider $provider): int
    {
        $this->info("Fournisseur : {$provider->name()}");

        try {
            $sports = collect($provider->sports())->keyBy('key');
        } catch (SportsDataException $e) {
            $this->error("Échec ({$e->reason}) : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("Sports proposés : {$sports->count()} ({$sports->where('active', true)->count()} actifs)");

        $rows = collect(config('sports_data.sports'))->map(fn (string $key) => [
            $key,
            $sports->has($key) ? $sports[$key]->title : '—',
            ! $sports->has($key) ? 'inconnu' : ($sports[$key]->active ? 'actif' : 'hors saison'),
        ]);
        $this->table(['Sport configuré (SPORTS_DATA_SPORTS)', 'Titre', 'État'], $rows);

        $quota = $provider->quota();
        if ($quota !== null) {
            $this->line("Quota : {$quota->remaining} requêtes restantes, {$quota->used} utilisées (seuil de sécurité : ".config('sports_data.min_remaining_requests').').');
        }

        return self::SUCCESS;
    }
}
