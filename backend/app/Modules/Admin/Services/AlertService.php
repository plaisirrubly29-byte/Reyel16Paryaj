<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Services\CreditBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Points d'attention calculés à partir des données réelles (aucune alerte stockée ni inventée).
 * Chaque contrôle n'est exécuté que pour les rôles qui peuvent agir sur l'objet concerné,
 * et chaque alerte renvoie vers l'écran où la traiter.
 */
class AlertService
{
    /** Un match « en direct » depuis plus longtemps n'a plus été mis à jour par le fournisseur. */
    public const LIVE_STALE_HOURS = 4;

    /** Délai après le début d'un match au-delà duquel un ticket encore en cours est anormal. */
    public const SETTLEMENT_DELAY_HOURS = 6;

    /** Seuil d'échecs MFA sur 24 h signalé aux auditeurs. */
    public const MFA_FAILURES_THRESHOLD = 5;

    /** Le rapprochement parcourt tout le ledger : son résultat est mis en cache quelques minutes. */
    private const RECONCILIATION_TTL_SECONDS = 600;

    public function __construct(
        private readonly CreditBudget $credits,
        private readonly ReconciliationService $reconciliation,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @return list<array{key: string, severity: 'critical'|'warning'|'info', category: string, title: string, detail: string, link: string|null}>
     */
    public function current(array $permissions): array
    {
        $can = fn (string $permission) => in_array($permission, $permissions, true);
        $alerts = [];

        if ($can('events.view')) {
            array_push($alerts, ...$this->providerAlerts(), ...$this->catalogueAlerts());
        }
        if ($can('tickets.view')) {
            array_push($alerts, ...$this->settlementAlerts());
        }
        if ($can('ledger.view')) {
            array_push($alerts, ...$this->financeAlerts());
        }
        if ($can('settings.view')) {
            array_push($alerts, ...$this->technicalAlerts());
        }
        if ($can('audit.view')) {
            array_push($alerts, ...$this->securityAlerts());
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn (array $a, array $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function providerAlerts(): array
    {
        if (! $this->credits->enabled() || $this->credits->provider() === 'fake') {
            return [];
        }

        $alerts = [];
        $remaining = $this->credits->remaining();
        if ($remaining <= $this->credits->reserve()) {
            $alerts[] = $this->alert('provider_credits', 'critical', 'Données sportives',
                'Crédits du fournisseur épuisés',
                "Il reste {$remaining} crédit(s), sous la réserve de {$this->credits->reserve()} : plus aucune synchronisation des matchs et des cotes avant le {$this->credits->nextReset()->toDateString()}.",
                '/admin/system');
        } elseif ($remaining <= $this->credits->reserve() * 2) {
            $alerts[] = $this->alert('provider_credits', 'warning', 'Données sportives',
                'Crédits du fournisseur bientôt épuisés',
                "Il reste {$remaining} crédit(s) jusqu'au {$this->credits->nextReset()->toDateString()}.",
                '/admin/system');
        }

        $lastCall = $this->credits->lastCallAt();
        if ($lastCall === null || $lastCall->lt(now()->subDay())) {
            $alerts[] = $this->alert('provider_silent', 'warning', 'Données sportives',
                'Aucune synchronisation depuis plus de 24 h',
                $lastCall ? "Dernier appel au fournisseur : {$lastCall->toIso8601String()}." : 'Aucun appel au fournisseur enregistré.',
                '/admin/system');
        }

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function catalogueAlerts(): array
    {
        $alerts = [];

        $staleLive = Event::query()
            ->where('status', EventStatus::Live)
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subHours(self::LIVE_STALE_HOURS)))
            ->count();
        if ($staleLive > 0) {
            $alerts[] = $this->alert('stale_live_events', 'warning', 'Données sportives',
                "{$staleLive} match(s) « en direct » sans mise à jour",
                'Statut ou score non rafraîchi depuis plus de '.self::LIVE_STALE_HOURS.' h : à vérifier avant tout règlement.',
                '/admin/events');
        }

        $suspended = Market::query()->where('status', MarketStatus::Suspended)->count();
        if ($suspended > 0) {
            $alerts[] = $this->alert('markets_suspended', 'info', 'Marchés',
                "{$suspended} marché(s) suspendu(s)",
                'Suspendus automatiquement (cotes trop anciennes) ou par un administrateur.',
                '/admin/events');
        }

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function settlementAlerts(): array
    {
        $overdue = DB::table('bet_tickets')
            ->where('status', BetTicketStatus::Pending->value)
            ->whereExists(fn ($q) => $q->from('bet_selections')
                ->whereColumn('bet_selections.bet_ticket_id', 'bet_tickets.id')
                ->where('bet_selections.event_starts_at', '<', now()->subHours(self::SETTLEMENT_DELAY_HOURS)))
            ->count();

        return $overdue === 0 ? [] : [$this->alert('settlement_overdue', 'warning', 'Règlement',
            "{$overdue} ticket(s) en attente de règlement",
            'Au moins un de leurs matchs a commencé il y a plus de '.self::SETTLEMENT_DELAY_HOURS.' h : résultat manquant ou règlement en échec.',
            '/admin/tickets?status=pending')];
    }

    /** @return list<array<string, mixed>> */
    private function financeAlerts(): array
    {
        $alerts = [];

        $corrections = FinanceCorrection::query()->where('status', FinanceCorrectionStatus::Pending)->count();
        if ($corrections > 0) {
            $alerts[] = $this->alert('finance_corrections', 'warning', 'Finance',
                "{$corrections} correction(s) financière(s) à valider",
                'Une correction ne prend effet qu’après la validation d’un second administrateur.',
                '/admin/finance');
        }

        $discrepancies = Cache::remember('admin.alerts.reconciliation', self::RECONCILIATION_TTL_SECONDS,
            fn () => (int) $this->reconciliation->run()['discrepancies_count']);
        if ($discrepancies > 0) {
            $alerts[] = $this->alert('reconciliation', 'critical', 'Finance',
                "{$discrepancies} écart(s) de rapprochement",
                'Le grand livre présente des incohérences (chaîne de soldes, montants de tickets ou écritures orphelines).',
                '/admin/finance');
        }

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function technicalAlerts(): array
    {
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return $failed === 0 ? [] : [$this->alert('failed_jobs', 'critical', 'Technique',
            "{$failed} tâche(s) automatique(s) en échec sur 24 h",
            'Règlement, synchronisation ou autre traitement en file d’attente : voir la supervision technique.',
            '/admin/system')];
    }

    /** @return list<array<string, mixed>> */
    private function securityAlerts(): array
    {
        $failures = AuditLog::query()
            ->where('action', AuditAction::MfaFailed)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return $failures < self::MFA_FAILURES_THRESHOLD ? [] : [$this->alert('mfa_failures', 'warning', 'Sécurité',
            "{$failures} échecs de double authentification sur 24 h",
            'Tentatives de connexion administrative inhabituelles possibles.',
            '/admin/audit')];
    }

    /** @return array{key: string, severity: string, category: string, title: string, detail: string, link: string|null} */
    private function alert(string $key, string $severity, string $category, string $title, string $detail, ?string $link): array
    {
        return compact('key', 'severity', 'category', 'title', 'detail', 'link');
    }
}
