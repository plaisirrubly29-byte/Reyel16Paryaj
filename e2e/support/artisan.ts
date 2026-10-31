import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = fileURLToPath(new URL('../..', import.meta.url));

/**
 * Commande artisan de l'environnement testé : seule façon de faire passer le temps (fin d'un match) et de lancer
 * l'import des résultats et le règlement, comme le ferait le planificateur.
 *
 *   local (docker compose) : défaut
 *   CI                     : E2E_ARTISAN="php backend/artisan"
 *   Staging                : E2E_ARTISAN="ssh staging 'cd /opt/reyel16-staging && … docker compose exec -T backend php artisan'"
 */
export function artisan(args: string): string {
  const command = process.env.E2E_ARTISAN ?? 'docker compose exec -T backend php artisan';
  return execSync(`${command} ${args}`, { cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

export type ScenarioMatch = { id: string; home: string; away: string; starts_at: string };

/** Nouveau match du faux fournisseur, synchronisé : pariable tout de suite (sports-data:fake-match create). */
export function createScenarioMatch(): ScenarioMatch {
  const output = artisan('sports-data:fake-match create');
  const match = JSON.parse(output.trim().split('\n').pop() ?? '') as ScenarioMatch;
  artisan('sports-data:sync --competition=soccer_epl');
  return match;
}

/** Fin du match avec ce score, puis import du résultat et règlement (chemin normal du planificateur). */
export function finishAndSettle(match: ScenarioMatch, score: string): void {
  artisan(`sports-data:fake-match finish ${match.id} ${score}`);
  artisan('settlement:import-results --competition=soccer_epl');
  artisan('settlement:run');
}
