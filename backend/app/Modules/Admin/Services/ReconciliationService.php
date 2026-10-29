<?php

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rapprochement du ledger (lecture seule) : totaux par devise et par type, puis écarts détectés.
 *
 * Contrôles :
 * - balance_chain : le solde mémorisé par chaque écriture vaut le solde précédent du portefeuille + son montant ;
 * - ticket_amount : le net des écritures d'un ticket vaut (gain versé ?? 0) - mise
 *   (en attente ou perdu : -mise ; gagné : gain - mise ; annulé : 0, la mise étant remboursée) ;
 * - orphan_entry : écriture rattachée à un ticket qui n'existe pas.
 *
 * Les écarts sont listés jusqu'à MAX_DISCREPANCIES par contrôle ; les compteurs restent exacts.
 */
class ReconciliationService
{
    public const MAX_DISCREPANCIES = 100;

    /** @return array<string, mixed> */
    public function run(): array
    {
        $checks = [
            'balance_chain' => $this->balanceChainBreaks(),
            'ticket_amount' => $this->ticketMismatches(),
            'orphan_entry' => $this->orphanEntries(),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'checked' => [
                'wallets' => DB::table('wallets')->count(),
                'ledger_entries' => DB::table('ledger_entries')->count(),
                'tickets' => DB::table('bet_tickets')->count(),
            ],
            'totals' => $this->totals(),
            'discrepancies_count' => array_sum(array_column($checks, 'count')),
            'discrepancies' => array_merge(...array_column($checks, 'items')),
            'truncated' => collect($checks)->contains(fn (array $check) => $check['count'] > count($check['items'])),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function totals(): array
    {
        return DB::table('ledger_entries')
            ->select(['currency', 'type'])
            ->selectRaw('COUNT(*) AS entries, SUM(amount_minor) AS sum_minor')
            ->groupBy('currency', 'type')
            ->orderBy('currency')
            ->orderBy('type')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'type' => $row->type,
                'entries' => (int) $row->entries,
                'sum' => ['minor' => (int) $row->sum_minor, 'currency' => $row->currency],
            ])
            ->all();
    }

    /** @return array{count: int, items: list<array<string, mixed>>} */
    private function balanceChainBreaks(): array
    {
        $chain = DB::table('ledger_entries')
            ->select(['id', 'wallet_id', 'amount_minor', 'balance_after_minor', 'currency'])
            ->selectRaw('LAG(balance_after_minor) OVER (PARTITION BY wallet_id ORDER BY id) AS previous_minor');

        $breaks = DB::query()->fromSub($chain, 'chain')
            ->whereRaw('COALESCE(previous_minor, 0) + amount_minor <> balance_after_minor');

        return [
            'count' => (clone $breaks)->count(),
            'items' => $breaks->orderBy('id')->limit(self::MAX_DISCREPANCIES)->get()
                ->map(fn ($row) => [
                    'kind' => 'balance_chain',
                    'wallet_id' => (string) $row->wallet_id,
                    'ledger_entry_id' => (string) $row->id,
                    'expected' => ['minor' => (int) $row->previous_minor + (int) $row->amount_minor, 'currency' => $row->currency],
                    'actual' => ['minor' => (int) $row->balance_after_minor, 'currency' => $row->currency],
                ])
                ->all(),
        ];
    }

    /** @return array{count: int, items: list<array<string, mixed>>} */
    private function ticketMismatches(): array
    {
        $net = DB::table('ledger_entries')
            ->where('related_type', 'bet')
            ->groupBy('related_id')
            ->select('related_id')
            ->selectRaw('SUM(amount_minor) AS net_minor');

        $mismatches = DB::table('bet_tickets')
            ->leftJoinSub($net, 'net', 'net.related_id', '=', DB::raw('CAST(bet_tickets.id AS VARCHAR(64))'))
            ->whereRaw('COALESCE(net.net_minor, 0) <> COALESCE(bet_tickets.payout_minor, 0) - bet_tickets.stake_minor');

        return [
            'count' => (clone $mismatches)->count(),
            'items' => $mismatches
                ->select(['bet_tickets.id', 'bet_tickets.reference', 'bet_tickets.status', 'bet_tickets.stake_minor', 'bet_tickets.payout_minor', 'bet_tickets.currency'])
                ->selectRaw('COALESCE(net.net_minor, 0) AS net_minor')
                ->orderBy('bet_tickets.id')
                ->limit(self::MAX_DISCREPANCIES)
                ->get()
                ->map(fn ($row) => [
                    'kind' => 'ticket_amount',
                    'ticket_id' => (string) $row->id,
                    'reference' => $row->reference,
                    'status' => $row->status,
                    'expected' => ['minor' => (int) $row->payout_minor - (int) $row->stake_minor, 'currency' => $row->currency],
                    'actual' => ['minor' => (int) $row->net_minor, 'currency' => $row->currency],
                ])
                ->all(),
        ];
    }

    /** @return array{count: int, items: list<array<string, mixed>>} */
    private function orphanEntries(): array
    {
        $orphans = DB::table('ledger_entries')
            ->where('related_type', 'bet')
            ->whereNotExists(fn ($q) => $q->from('bet_tickets')->whereRaw('CAST(bet_tickets.id AS VARCHAR(64)) = ledger_entries.related_id'));

        return [
            'count' => (clone $orphans)->count(),
            'items' => $orphans->select(['id', 'wallet_id', 'related_id'])->orderBy('id')->limit(self::MAX_DISCREPANCIES)->get()
                ->map(fn ($row) => [
                    'kind' => 'orphan_entry',
                    'wallet_id' => (string) $row->wallet_id,
                    'ledger_entry_id' => (string) $row->id,
                    'related' => ['type' => 'bet', 'id' => $row->related_id],
                ])
                ->all(),
        ];
    }
}
