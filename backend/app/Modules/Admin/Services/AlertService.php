<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Compliance\Enums\AmlAlertStatus;
use App\Modules\Compliance\Models\AmlAlert;
use App\Modules\Settlement\Enums\TicketVoidStatus;
use App\Modules\Settlement\Models\TicketVoid;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Services\CreditBudget;
use Illuminate\Support\Carbon;
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

    /** Délai après le début d'un match au-delà duquel une sélection encore en attente est anormale. */
    public const SETTLEMENT_DELAY_HOURS = 6;

    /** Seuil d'échecs MFA sur 24 h signalé aux auditeurs. */
    public const MFA_FAILURES_THRESHOLD = 5;

    /**
     * Échecs de connexion répétés sur 1 h (revue P8-02, F12) : par compte visé, ou par adresse IP. Le limiteur « login »
     * plafonne déjà à 30 essais par heure et par compte ; le seuil par IP reste large pour les cybercafés.
     */
    public const LOGIN_FAILURES_WINDOW_MINUTES = 60;

    public const LOGIN_FAILURES_PER_ACCOUNT = 10;

    public const LOGIN_FAILURES_PER_IP = 30;

    /** Période pendant laquelle un changement de rôle administratif reste signalé (M3, décision D-07). */
    public const ROLE_CHANGES_HOURS = 72;

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
        if ($can('tickets.void_approve')) {
            array_push($alerts, ...$this->ticketVoidAlerts());
        }
        if ($can('ledger.view')) {
            array_push($alerts, ...$this->financeAlerts());
        }
        if ($can('aml.view')) {
            array_push($alerts, ...$this->amlAlerts());
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

    /**
     * Sélections encore en attente plus de SETTLEMENT_DELAY_HOURS après le début du match (audit du 2026-11-22, A4) :
     * - sans résultat : The Odds API ne signale ni annulation ni report, la mise du joueur reste bloquée tant que
     *   personne ne saisit le résultat, n'annule ou ne reporte le match ;
     * - avec un résultat : le règlement a échoué (marché inconnu, statistiques absentes…).
     *
     * @return list<array<string, mixed>>
     */
    private function settlementAlerts(): array
    {
        $alerts = [];
        $late = fn () => DB::table('bet_selections')
            ->join('events', 'events.id', '=', 'bet_selections.event_id')
            ->where('bet_selections.status', BetSelectionStatus::Pending->value)
            ->where('bet_selections.event_starts_at', '<', now()->subHours(self::SETTLEMENT_DELAY_HOURS));
        $withoutResult = [EventStatus::Scheduled->value, EventStatus::Live->value, EventStatus::Postponed->value];

        $stuck = $late()->whereIn('events.status', $withoutResult)
            ->selectRaw('count(*) as selections, count(distinct bet_selections.event_id) as events, min(bet_selections.event_starts_at) as oldest')
            ->first();
        if ((int) $stuck->selections > 0) {
            $alerts[] = $this->alert('selections_stuck', 'warning', 'Règlement',
                "{$stuck->selections} sélection(s) bloquée(s) sur {$stuck->events} match(s) sans résultat",
                'Match commencé il y a plus de '.self::SETTLEMENT_DELAY_HOURS.' h (le plus ancien : '.Carbon::parse($stuck->oldest)->toDateTimeString()
                    .') : saisir le résultat, annuler ou reporter le match depuis sa fiche.',
                '/admin/events?stuck=1');
        }

        $overdue = DB::table('bet_tickets')
            ->where('status', BetTicketStatus::Pending->value)
            ->whereIn('id', $late()->whereNotIn('events.status', $withoutResult)->select('bet_selections.bet_ticket_id'))
            ->count();
        if ($overdue > 0) {
            $alerts[] = $this->alert('settlement_overdue', 'warning', 'Règlement',
                "{$overdue} ticket(s) en attente de règlement",
                'Le résultat de leur match est connu depuis plus de '.self::SETTLEMENT_DELAY_HOURS.' h après son début, mais le règlement n\'a pas abouti.',
                '/admin/tickets?status=pending');
        }

        return $alerts;
    }

    /** Annulations de ticket en attente du second administrateur (audit du 2026-11-22, A2). @return list<array<string, mixed>> */
    private function ticketVoidAlerts(): array
    {
        $pending = TicketVoid::query()->where('status', TicketVoidStatus::Pending)->count();

        return $pending === 0 ? [] : [$this->alert('ticket_voids', 'warning', 'Règlement',
            "{$pending} annulation(s) de ticket à valider",
            'Un ticket n’est annulé (mise rendue) qu’après la validation d’un second administrateur.',
            '/admin/tickets?void_status=pending')];
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
    private function amlAlerts(): array
    {
        $open = AmlAlert::query()->where('status', AmlAlertStatus::Open)->count();

        return $open === 0 ? [] : [$this->alert('aml_alerts', 'warning', 'Conformité',
            "{$open} alerte(s) AML à examiner",
            'Gros dépôt, cumul de dépôts ou retrait sans jeu : à classer ou à signaler par la Conformité.',
            '/admin/aml?status=open')];
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

        $alerts = $failures < self::MFA_FAILURES_THRESHOLD ? [] : [$this->alert('mfa_failures', 'warning', 'Sécurité',
            "{$failures} échecs de double authentification sur 24 h",
            'Tentatives de connexion administrative inhabituelles possibles.',
            '/admin/audit')];

        $accounts = $this->repeatedLoginFailures('subject_id', self::LOGIN_FAILURES_PER_ACCOUNT);
        $addresses = $this->repeatedLoginFailures('ip_address', self::LOGIN_FAILURES_PER_IP);
        if ($accounts > 0 || $addresses > 0) {
            $alerts[] = $this->alert('login_failures', 'warning', 'Sécurité',
                "Échecs de connexion répétés : {$accounts} compte(s), {$addresses} adresse(s) IP sur ".self::LOGIN_FAILURES_WINDOW_MINUTES.' min',
                'Au moins '.self::LOGIN_FAILURES_PER_ACCOUNT.' échecs sur un même compte, ou '.self::LOGIN_FAILURES_PER_IP.' depuis une même adresse : essais de mots de passe possibles. Détail dans le journal d\'audit (auth.login_failed).',
                '/admin/audit');
        }

        // M3 (D-07) : tout Super Admin voit qu'un rôle a été donné ou retiré, y compris par un autre Super Admin.
        $roleChanges = AuditLog::query()
            ->where('action', AuditAction::RolesUpdated)
            ->where('created_at', '>=', now()->subHours(self::ROLE_CHANGES_HOURS))
            ->count();
        if ($roleChanges > 0) {
            $alerts[] = $this->alert('role_changes', 'warning', 'Sécurité',
                "{$roleChanges} changement(s) de rôles administratifs sur ".self::ROLE_CHANGES_HOURS.' h',
                'Vérifiez dans le journal d\'audit que chaque attribution ou retrait de rôle était prévu.',
                '/admin/audit');
        }

        return $alerts;
    }

    /** Nombre de comptes visés (subject_id) ou d'adresses (ip_address) ayant atteint $threshold échecs de connexion. */
    private function repeatedLoginFailures(string $column, int $threshold): int
    {
        return AuditLog::query()
            ->where('action', AuditAction::LoginFailed)
            ->where('created_at', '>=', now()->subMinutes(self::LOGIN_FAILURES_WINDOW_MINUTES))
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('count(*) >= ?', [$threshold])
            ->select($column)
            ->get()
            ->count();
    }

    /** @return array{key: string, severity: string, category: string, title: string, detail: string, link: string|null} */
    private function alert(string $key, string $severity, string $category, string $title, string $detail, ?string $link): array
    {
        return compact('key', 'severity', 'category', 'title', 'detail', 'link');
    }
}
