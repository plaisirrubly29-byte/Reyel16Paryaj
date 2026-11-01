<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Http\Resources\AdminLedgerEntryResource;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Solde d'un joueur vu par l'administration, et dépôts / retraits reçus hors plateforme.
 *
 * Aucun prestataire de paiement n'est intégré (P7-01) : le joueur paie ou est payé auprès de l'opérateur,
 * puis le Super Admin (wallet.adjust) enregistre l'opération au ledger avec un motif. Chaque opération est auditée.
 */
class AdminWalletController extends Controller
{
    /** 1 000 000,00 HTG : plafond d'une opération (gain maximal d'un ticket, D-06). */
    private const MAX_AMOUNT_MINOR = 100_000_000;

    public function __construct(private readonly LedgerService $ledger) {}

    public function show(User $user): JsonResponse
    {
        $wallet = $this->ledger->walletFor($user);
        $balance = $this->ledger->balance($wallet);

        return response()->json(['data' => [
            'user_id' => (string) $user->id,
            'currency' => $wallet->currency,
            'balance' => ['minor' => $balance->minor, 'currency' => $balance->currency],
            'status' => $wallet->status,
        ]]);
    }

    /**
     * @throws ApiException not_a_player, insufficient_funds, wallet_frozen
     */
    public function store(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([LedgerEntryType::Deposit->value, LedgerEntryType::Withdrawal->value])],
            'amount_minor' => ['required', 'integer', 'min:100', 'max:'.self::MAX_AMOUNT_MINOR],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if ($user->isAdmin()) {
            throw new ApiException('not_a_player', __('Seul le solde d\'un joueur peut être crédité ou débité.'), 422);
        }

        $type = LedgerEntryType::from($data['type']);
        $actor = $request->user();

        $entry = DB::transaction(function () use ($user, $type, $data, $actor, $audit) {
            $wallet = $this->ledger->walletFor($user);
            $amount = Money::ofMinor($data['amount_minor'], $wallet->currency);
            // La requête rejouée (même Idempotency-Key) est servie par le middleware : chaque appel est une opération.
            $reference = "admin:{$type->value}:".Str::uuid();

            $entry = $type === LedgerEntryType::Deposit
                ? $this->ledger->deposit($wallet, $amount, $reference, $data['reason'], $actor)
                : $this->ledger->withdraw($wallet, $amount, $reference, $data['reason'], $actor);

            $audit->record($type === LedgerEntryType::Deposit ? AuditAction::WalletDeposited : AuditAction::WalletWithdrawn, $entry,
                after: ['amount_minor' => $entry->amount_minor, 'currency' => $entry->currency, 'balance_after_minor' => $entry->balance_after_minor],
                reason: $data['reason'],
                actor: $actor,
                metadata: ['user_id' => $user->id],
            );

            return $entry;
        });

        return (new AdminLedgerEntryResource($entry->load(['wallet', 'reversedBy'])))->response()->setStatusCode(201);
    }
}
