<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\ResultCorrection;
use App\Modules\Settlement\Models\Settlement;
use App\Modules\Settlement\Services\ResultImportService;
use App\Modules\Settlement\Services\ResultRecorder;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use App\Modules\SportsData\Services\CatalogueSyncService;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P5-06 : correction de résultat à double contrôle — demande, aperçu, validation par un autre administrateur,
 * réévaluation des tickets (écritures compensatoires, révisions), refus, historique.
 *
 * Arsenal–Chelsea (fake-1) : Arsenal 2.10, Chelsea 3.40, nul 3.25. Résultat initial 2-1 (Arsenal gagne).
 */
class ResultCorrectionApiTest extends TestCase
{
    use RefreshDatabase;

    private const BALANCE = 1000_00;

    private const STAKE = 100_00;

    private FakeSportsDataProvider $fake;

    private Event $event;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config(['sports_data.max_odds_age_minutes' => 300, 'sports_data.results_delay_minutes' => 105, 'wallet.simulated' => true]);
        $this->fake = new FakeSportsDataProvider;
        $this->fake->setStartsAt('fake-1', CarbonImmutable::parse('2026-10-21 15:00:00'));
        $this->fake->setStartsAt('fake-2', CarbonImmutable::parse('2026-10-22 17:30:00'));
        $this->app->instance(SportsDataProvider::class, $this->fake);

        $this->travelTo('2026-10-20 10:00:00');
        app(CatalogueSyncService::class)->sync('soccer_epl');
        $this->event = Event::where('external_id', 'fake-1')->firstOrFail();
        $this->operations = User::factory()->withRole(RoleSlug::Operations)->create();
    }

    private function player(): User
    {
        $player = User::factory()->create();
        $ledger = app(LedgerService::class);
        $ledger->creditSimulated($ledger->walletFor($player), Money::ofMinor(self::BALANCE, 'HTG'), 'test:'.Str::uuid());

        return $player;
    }

    private function selection(string $event, string $key): Selection
    {
        return Event::where('external_id', $event)->firstOrFail()
            ->markets()->where('key', 'h2h')->firstOrFail()
            ->selections()->where('key', $key)->firstOrFail();
    }

    /** @param  list<array{0: string, 1: string}>  $picks  [match, issue] */
    private function bet(User $player, array $picks, int $stake = self::STAKE): BetTicket
    {
        $id = $this->actingAs($player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => $stake, 'currency' => 'HTG'],
            'selections' => array_map(function (array $pick) {
                $selection = $this->selection(...$pick);

                return ['selection_id' => $selection->id, 'odds' => (string) $selection->odds];
            }, $picks),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        return BetTicket::findOrFail($id);
    }

    private function balance(User $player): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($player))->minor;
    }

    /** Le fournisseur publie le score de fake-1, importé et réglé. */
    private function finish(string $home, string $away): void
    {
        $this->travelTo('2026-10-21 17:30:00');
        $this->fake->setResult('fake-1', $home, $away);
        $this->assertTrue(app(ResultImportService::class)->import('soccer_epl')->succeeded());
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    /** @param  array<string, mixed>  $body */
    private function adminPost(User $as, string $uri, array $body): TestResponse
    {
        return $this->actingAsVerifiedAdmin($as)->postJson("/api/v1/admin/{$uri}", $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function requestCorrection(?User $as = null, string $home = '1', string $away = '2', string $status = 'final'): TestResponse
    {
        return $this->adminPost($as ?? $this->operations, "events/{$this->event->id}/result-corrections", [
            'status' => $status,
            'home_score' => $status === 'final' ? $home : null,
            'away_score' => $status === 'final' ? $away : null,
            'reason' => 'Score officiel publié par la ligue',
        ]);
    }

    private function approve(string $id, ?User $as = null): TestResponse
    {
        return $this->adminPost($as ?? $this->admin(RoleSlug::SuperAdmin), "result-corrections/{$id}/approve", ['reason' => 'Vérifié sur le site de la ligue']);
    }

    // Droits

    public function test_only_result_roles_can_request_and_approve(): void
    {
        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1');

        foreach ([RoleSlug::Finance, RoleSlug::Support, RoleSlug::Compliance] as $role) {
            $this->requestCorrection($this->admin($role))->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->postJson("/api/v1/admin/events/{$this->event->id}/result-corrections", [
            'status' => 'final', 'home_score' => '1', 'away_score' => '2', 'reason' => 'Tentative de joueur',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        $id = $this->requestCorrection()->assertCreated()->json('data.id');
        $this->approve($id, $this->admin(RoleSlug::Finance))->assertForbidden();
        $this->assertSame(1, Result::count());
    }

    public function test_the_requester_can_never_approve_their_own_correction(): void
    {
        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1');
        $id = $this->requestCorrection()->assertCreated()->json('data.id');

        $this->approve($id, $this->operations)->assertForbidden()->assertJsonPath('error.code', 'self_approval_forbidden');
        $this->assertSame(1, Result::count());
    }

    public function test_a_role_given_by_an_admin_less_than_24_hours_ago_cannot_approve(): void
    {
        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1');
        $id = $this->requestCorrection()->assertCreated()->json('data.id');

        // Un Super Admin donne le rôle Opérations à un second compte, puis s'en servirait pour valider.
        $newcomer = User::factory()->create();
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin))
            ->putJson("/api/v1/admin/users/{$newcomer->id}/roles", ['roles' => ['operations'], 'reason' => 'Renfort du week-end'],
                ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();
        $newcomer->refresh();

        $this->approve($id, $newcomer)->assertForbidden()->assertJsonPath('error.code', 'approver_role_too_recent');
        $this->assertSame(1, Result::count());

        $this->travel(25)->hours();
        $this->approve($id, $newcomer->fresh())->assertOk();
    }

    // Demande et aperçu

    public function test_a_request_changes_nothing_and_shows_its_impact(): void
    {
        $winner = $this->player();
        $loser = $this->player();
        $this->bet($winner, [['fake-1', 'home']]);
        $this->bet($loser, [['fake-1', 'away']]);
        $this->finish('2', '1');

        $id = $this->requestCorrection()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current', ['version' => 1, 'status' => 'final', 'score' => '2-1'])
            ->assertJsonPath('data.proposed', ['status' => 'final', 'score' => '1-2'])
            ->assertJsonPath('data.requested_by.id', (string) $this->operations->id)
            ->assertJsonPath('data.preview.tickets_count', 2)
            ->assertJsonPath('data.preview.ledger_delta_minor', 340_00 - 210_00)
            ->json('data.id');

        // Rien n'a changé : même résultat, mêmes soldes, mêmes tickets.
        $this->assertSame(1, Result::count());
        $this->assertSame(self::BALANCE - self::STAKE + 210_00, $this->balance($winner));
        $this->assertSame(self::BALANCE - self::STAKE, $this->balance($loser));

        $log = AuditLog::where('action', AuditAction::ResultCorrectionRequested)->sole();
        $this->assertSame($this->operations->id, $log->actor_id);
        $this->assertSame('Score officiel publié par la ligue', $log->reason);

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::SuperAdmin))->getJson("/api/v1/admin/result-corrections/{$id}")
            ->assertOk()
            ->assertJsonPath('data.preview.tickets.0.from', ['status' => 'won', 'payout_minor' => 210_00])
            ->assertJsonPath('data.preview.tickets.0.to', ['status' => 'lost', 'payout_minor' => 0]);
    }

    public function test_invalid_or_useless_requests_are_refused(): void
    {
        $this->requestCorrection()->assertStatus(409)->assertJsonPath('error.code', 'no_result_to_correct');

        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1');

        $this->requestCorrection(home: '2', away: '1')->assertStatus(409)->assertJsonPath('error.code', 'result_unchanged');
        $this->requestCorrection(home: 'deux')->assertStatus(422)->assertJsonPath('error.code', 'invalid_result');
        $this->adminPost($this->operations, "events/{$this->event->id}/result-corrections", ['status' => 'final', 'reason' => 'Sans score'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->requestCorrection()->assertCreated();
        $this->requestCorrection(home: '0', away: '0')->assertStatus(409)->assertJsonPath('error.code', 'correction_already_pending');
    }

    public function test_a_settled_result_can_only_be_corrected_within_72_hours(): void
    {
        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1'); // réglé le 2026-10-21 à 17:30

        $this->travelTo('2026-10-24 17:31:00');
        $this->requestCorrection()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'correction_window_closed')
            ->assertJsonPath('error.details.deadline', '2026-10-24T17:30:00+00:00');

        // Le fournisseur non plus : sa correction tardive est ignorée.
        $this->fake->setResult('fake-1', '1', '2');
        $this->assertSame(1, app(ResultImportService::class)->import('soccer_epl')->unchanged);
        $this->assertSame(0, ResultCorrection::count());

        $this->travelTo('2026-10-24 17:29:00');
        $this->requestCorrection()->assertCreated();
    }

    // Validation : réévaluation et écritures compensatoires

    public function test_approval_reevaluates_tickets_with_compensating_entries(): void
    {
        $winner = $this->player();
        $loser = $this->player();
        $won = $this->bet($winner, [['fake-1', 'home']]);
        $lost = $this->bet($loser, [['fake-1', 'away']]);
        $this->finish('2', '1');
        $id = $this->requestCorrection()->json('data.id');
        $approver = $this->admin(RoleSlug::SuperAdmin);

        $this->approve($id, $approver)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', (string) $approver->id)
            ->assertJsonPath('data.impact.tickets_count', 2);

        // Nouvelle version saisie et validée, qui remplace la précédente.
        $v2 = Result::where('version', 2)->sole();
        $this->assertSame('manual', $v2->source);
        $this->assertSame($approver->id, $v2->entered_by);
        $this->assertSame('Score officiel publié par la ligue', $v2->correction_reason);
        $this->assertSame(['1', '2'], [$v2->home_score, $v2->away_score]);

        // Gagnant devenu perdant : gain repris par écriture compensatoire.
        $this->assertSame('lost', $won->fresh()->status->value);
        $reversal = LedgerEntry::where('type', LedgerEntryType::Reversal)->sole();
        $this->assertSame(-210_00, $reversal->amount_minor);
        $this->assertSame("settlement:{$won->id}:r2:reversal", $reversal->reference);
        $this->assertSame(self::BALANCE - self::STAKE, $this->balance($winner));

        // Perdant devenu gagnant : gain versé avec la cote acceptée.
        $this->assertSame('won', $lost->fresh()->status->value);
        $this->assertSame(340_00, $lost->fresh()->payout_minor);
        $this->assertSame(self::BALANCE - self::STAKE + 340_00, $this->balance($loser));

        // Révisions du règlement : jamais modifiées, une de plus par ticket.
        $this->assertSame([1, 2], Settlement::where('bet_ticket_id', $won->id)->orderBy('revision')->pluck('revision')->all());
        $this->assertSame('lost', Settlement::where('bet_ticket_id', $won->id)->where('revision', 2)->sole()->status->value);

        $log = AuditLog::where('action', AuditAction::ResultCorrectionApproved)->sole();
        $this->assertSame($approver->id, $log->actor_id);
        $this->assertSame(['result' => ['status' => 'final', 'score' => '2-1', 'version' => 1]], $log->before);
        $this->assertSame(['result' => ['status' => 'final', 'score' => '1-2', 'version' => 2]], $log->after);
        $this->assertSame(2, $log->metadata['tickets_count']);
    }

    public function test_a_spent_payout_is_taken_back_even_below_zero_and_blocks_betting(): void
    {
        $player = $this->player();
        $this->bet($player, [['fake-1', 'home']], 1000_00); // toute la mise
        $this->finish('2', '1'); // gain 2 100,00
        app(CatalogueSyncService::class)->sync('soccer_epl'); // cotes fraîches pour fake-2
        $this->bet($player, [['fake-2', 'home']], 1500_00); // le joueur remise une partie du gain

        $this->approve($this->requestCorrection()->json('data.id'))->assertOk();

        $this->assertSame(600_00 - 2100_00, $this->balance($player)); // reprise intégrale : solde négatif
        $this->actingAs($player)->postJson('/api/v1/bets', [
            'stake' => ['minor' => 10_00, 'currency' => 'HTG'],
            'selections' => [['selection_id' => $this->selection('fake-2', 'away')->id, 'odds' => (string) $this->selection('fake-2', 'away')->odds]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('error.code', 'insufficient_funds');
    }

    public function test_a_cancelled_match_refunds_every_stake(): void
    {
        $winner = $this->player();
        $loser = $this->player();
        $this->bet($winner, [['fake-1', 'home']]);
        $this->bet($loser, [['fake-1', 'away']]);
        $this->finish('2', '1');

        $this->approve($this->requestCorrection(status: 'cancelled')->json('data.id'))->assertOk();

        $this->assertSame(ResultStatus::Cancelled, Result::where('version', 2)->sole()->status);
        $this->assertSame(self::BALANCE, $this->balance($winner)); // gain repris, mise remboursée
        $this->assertSame(self::BALANCE, $this->balance($loser));
        $this->assertSame(2, LedgerEntry::where('type', LedgerEntryType::Refund)->count());
    }

    public function test_a_multiple_lost_on_this_match_is_reopened_then_settled_later(): void
    {
        $player = $this->player();
        $multiple = $this->bet($player, [['fake-1', 'away'], ['fake-2', 'home']]); // 3.40 × 1.55
        $this->finish('2', '1');
        $this->assertSame('lost', $multiple->fresh()->status->value); // perdu dès le premier match

        $this->approve($this->requestCorrection()->json('data.id'))->assertOk();

        $this->assertSame('pending', $multiple->fresh()->status->value); // fake-2 pas encore joué
        $this->assertNull($multiple->fresh()->payout_minor);

        $this->travelTo('2026-10-22 19:30:00');
        $this->fake->setResult('fake-2', '3', '0');
        app(ResultImportService::class)->import('soccer_epl');

        $this->assertSame('won', $multiple->fresh()->status->value);
        $this->assertSame(527_00, $multiple->fresh()->payout_minor); // 100 × 3.40 × 1.55
        $this->assertSame([1, 2], Settlement::where('bet_ticket_id', $multiple->id)->orderBy('revision')->pluck('revision')->all());
        $this->assertSame(self::BALANCE - self::STAKE + 527_00, $this->balance($player));
    }

    public function test_approval_is_refused_when_the_result_changed_since_the_request(): void
    {
        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1');
        $id = $this->requestCorrection()->json('data.id');

        app(ResultRecorder::class)->record($this->event, ResultStatus::Final, '3', '1', source: 'manual', reason: 'Autre saisie');

        $this->approve($id)->assertStatus(409)->assertJsonPath('error.code', 'result_changed');
    }

    // Refus, retrait

    public function test_a_request_can_be_withdrawn_or_rejected_but_only_once(): void
    {
        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1');

        $withdrawn = $this->requestCorrection()->json('data.id');
        $this->adminPost($this->operations, "result-corrections/{$withdrawn}/reject", ['reason' => 'Erreur de saisie'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertTrue(AuditLog::where('action', AuditAction::ResultCorrectionRejected)->sole()->metadata['withdrawn']);

        $rejected = $this->requestCorrection()->json('data.id');
        $this->adminPost($this->admin(RoleSlug::SuperAdmin), "result-corrections/{$rejected}/reject", ['reason' => 'Score non confirmé'])->assertOk();
        $this->approve($rejected)->assertStatus(409)->assertJsonPath('error.code', 'correction_not_pending');

        $this->assertSame(1, Result::count());
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntryType::Reversal)->count());
    }

    // Fournisseur et historique

    public function test_a_provider_correction_after_settlement_waits_for_approval(): void
    {
        $winner = $this->player();
        $this->bet($winner, [['fake-1', 'home']]);
        $this->finish('2', '1');

        $this->fake->setResult('fake-1', '1', '2');
        $report = app(ResultImportService::class)->import('soccer_epl');
        $this->assertSame(1, $report->awaitingApproval);
        app(ResultImportService::class)->import('soccer_epl'); // pas de seconde demande

        $correction = ResultCorrection::sole();
        $this->assertNull($correction->requested_by);
        $this->assertSame('fake', $correction->source);
        $this->assertSame(1, Result::count());

        // Demande « système » : n'importe quel administrateur results.approve peut la valider.
        $this->approve((string) $correction->id, $this->operations)->assertOk();
        $this->assertSame(self::BALANCE - self::STAKE, $this->balance($winner));
    }

    public function test_the_full_history_is_visible_to_every_admin_role(): void
    {
        $this->bet($this->player(), [['fake-1', 'home']]);
        $this->finish('2', '1');
        $approver = $this->admin(RoleSlug::SuperAdmin);
        $this->approve($this->requestCorrection()->json('data.id'), $approver);

        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Support))
            ->getJson("/api/v1/admin/events/{$this->event->id}/results")
            ->assertOk()
            ->assertJsonPath('data.results.0', fn (array $v1) => $v1['version'] === 1 && $v1['source'] === 'fake' && $v1['entered_by'] === null)
            ->assertJsonPath('data.results.1.version', 2)
            ->assertJsonPath('data.results.1.source', 'manual')
            ->assertJsonPath('data.results.1.entered_by.id', (string) $approver->id)
            ->assertJsonPath('data.results.1.correction_reason', 'Score officiel publié par la ligue')
            ->assertJsonPath('data.corrections.0.status', 'approved')
            ->assertJsonPath('data.corrections.0.impact.tickets_count', 1);
    }
}
