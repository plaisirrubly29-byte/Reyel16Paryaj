<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Verrou applicatif tenu jusqu'à la fin de la transaction en cours (pg_advisory_xact_lock).
 *
 * Pour sérialiser une opération sur une table en ajout seul (setting_versions, ledger_entries, audit_logs…) :
 * en Production, le compte de l'application n'y a que SELECT et INSERT (scripts/db/restrict-app-role.sql), or
 * PostgreSQL exige le droit UPDATE pour un SELECT … FOR UPDATE / FOR SHARE. Ce verrou ne touche aucune ligne et
 * ne demande aucun droit sur la table. Sur SQLite (tests, poste local), les écritures sont déjà sérialisées : rien à faire.
 */
final class TransactionLock
{
    /** À appeler dans DB::transaction() ; deux appels avec la même clé s'attendent l'un l'autre. */
    public static function acquire(string $key): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
