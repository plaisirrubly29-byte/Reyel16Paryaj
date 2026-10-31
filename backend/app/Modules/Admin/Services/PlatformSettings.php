<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Models\SettingVersion;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres de la plateforme modifiables depuis l'administration (P6-08), versionnés.
 *
 * Les valeurs par défaut viennent de config/ (.env) ; la dernière version enregistrée s'applique par-dessus,
 * au démarrage de chaque requête ou commande (apply). Les services lisent toujours config('betting.…') : rien
 * ne change pour eux. Un processus long (queue:work) garde les valeurs de son démarrage jusqu'à son redémarrage.
 *
 * Modifier = créer une version complète (jamais de mise à jour), avec motif, auditée (settings.updated).
 */
class PlatformSettings
{
    /**
     * Paramètres modifiables : clé config => définition. L'ordre est celui de l'écran.
     *
     * @var array<string, array{group: string, label: string, type: 'money'|'integer'|'boolean'|'choice', rules: list<string>, help?: string, options?: list<string>}>
     */
    public const DEFINITIONS = [
        'betting.min_stake_minor' => ['group' => 'Paris', 'label' => 'Mise minimale', 'type' => 'money', 'rules' => ['integer', 'min:1', 'max:100000000']],
        'betting.max_stake_minor' => ['group' => 'Paris', 'label' => 'Mise maximale', 'type' => 'money', 'rules' => ['integer', 'min:1', 'max:100000000']],
        'betting.max_payout_minor' => ['group' => 'Paris', 'label' => 'Plafond de gain par ticket', 'type' => 'money', 'rules' => ['integer', 'min:1', 'max:10000000000'],
            'help' => 'Le gain potentiel est plafonné à ce montant.'],
        'betting.allow_multiple' => ['group' => 'Paris', 'label' => 'Paris combinés autorisés', 'type' => 'boolean', 'rules' => ['boolean']],
        'betting.max_selections' => ['group' => 'Paris', 'label' => 'Sélections maximum par combiné', 'type' => 'integer', 'rules' => ['integer', 'min:1', 'max:30']],
        'betting.allow_live' => ['group' => 'Paris', 'label' => 'Paris en direct autorisés', 'type' => 'boolean', 'rules' => ['boolean']],
        'wallet.welcome_credit_minor' => ['group' => 'Portefeuille', 'label' => 'Crédit de bienvenue (fonds fictifs)', 'type' => 'money', 'rules' => ['integer', 'min:0', 'max:100000000'],
            'help' => 'Versé à chaque nouvelle inscription ; 0 pour le désactiver.'],
        'sports_data.provider' => ['group' => 'Fournisseur', 'label' => 'Fournisseur de données sportives', 'type' => 'choice', 'rules' => ['string', 'in:fake,theodds'], 'options' => ['fake', 'theodds'],
            'help' => 'fake : données de démonstration ; theodds : The Odds API (clé API requise dans .env).'],
    ];

    /** @var array<string, mixed>|null valeurs de config/ avant application de la dernière version */
    private ?array $defaults = null;

    /**
     * Applique la dernière version par-dessus config/. Sans table (avant les migrations) ou sans base joignable :
     * les valeurs de config/ restent en vigueur.
     */
    public function apply(): void
    {
        $this->defaults ??= $this->configValues();

        try {
            $values = SettingVersion::query()->orderByDesc('version')->first(['values'])?->values;
        } catch (QueryException) {
            return;
        }

        if ($values !== null) {
            $this->set(array_intersect_key($values, self::DEFINITIONS));
        }
    }

    public function current(): ?SettingVersion
    {
        return SettingVersion::query()->with('author:id,name')->orderByDesc('version')->first();
    }

    /** @return list<array<string, mixed>> paramètres en vigueur, avec leur valeur par défaut (config/) */
    public function items(): array
    {
        $defaults = $this->defaults ?? $this->configValues();

        return array_map(fn (string $key, array $definition) => [
            'key' => $key,
            'group' => $definition['group'],
            'label' => $definition['label'],
            'type' => $definition['type'],
            'help' => $definition['help'] ?? null,
            'options' => $definition['options'] ?? null,
            'currency' => $definition['type'] === 'money' ? (string) config('wallet.currency') : null,
            'value' => config($key),
            'default' => $defaults[$key],
        ], array_keys(self::DEFINITIONS), self::DEFINITIONS);
    }

    /**
     * Règles de validation de la requête : values.betting.min_stake_minor…, toutes facultatives.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = ['values' => ['required', 'array']];
        foreach (self::DEFINITIONS as $key => $definition) {
            $rules["values.{$key}"] = ['sometimes', 'required', ...$definition['rules']];
        }

        return $rules;
    }

    /**
     * Crée la version suivante. $baseVersion : version affichée à l'administrateur (0 si aucune) ; une version
     * enregistrée entre-temps est refusée (409) plutôt qu'écrasée.
     *
     * @param  array<string, mixed>  $values  clés de DEFINITIONS (les autres sont refusées)
     *
     * @throws ApiException settings_changed, no_change
     * @throws ValidationException valeurs incohérentes entre elles
     */
    public function update(User $actor, array $values, string $reason, int $baseVersion): SettingVersion
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFINITIONS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => __('Paramètre inconnu : :keys.', ['keys' => implode(', ', $unknown)])]);
        }

        try {
            return DB::transaction(function () use ($actor, $values, $reason, $baseVersion) {
                $latest = SettingVersion::query()->orderByDesc('version')->lockForUpdate()->first();
                $currentVersion = $latest?->version ?? 0;

                if ($currentVersion !== $baseVersion) {
                    throw new ApiException('settings_changed', __('Les paramètres ont été modifiés entre-temps : rechargez la page.'), 409, [
                        'version' => $currentVersion,
                    ]);
                }

                // Base : la version verrouillée (pas config(), qu'une autre requête a pu rendre obsolète).
                $before = array_merge($this->defaults ?? $this->configValues(), $this->cast(array_intersect_key($latest->values ?? [], self::DEFINITIONS)));
                $after = array_merge($before, $this->cast($values));
                $this->assertConsistent($after);

                $changes = [];
                foreach ($after as $key => $value) {
                    if ($value !== $before[$key]) {
                        $changes[$key] = ['from' => $before[$key], 'to' => $value];
                    }
                }

                if ($changes === []) {
                    throw new ApiException('no_change', __('Aucune valeur n\'a changé.'), 422);
                }

                $version = SettingVersion::create([
                    'version' => $currentVersion + 1,
                    'values' => $after,
                    'changes' => $changes,
                    'reason' => $reason,
                    'created_by' => $actor->id,
                ]);

                // Résolu ici : AuditLogger retient la requête en cours, et ce service vit tout le processus.
                app(AuditLogger::class)->record(AuditAction::SettingsUpdated, $version,
                    before: array_map(fn (array $change) => $change['from'], $changes),
                    after: array_map(fn (array $change) => $change['to'], $changes),
                    reason: $reason,
                    actor: $actor,
                    metadata: ['version' => $version->version],
                );

                return $version;
            });
        } catch (QueryException $e) {
            // Première version créée en même temps par deux administrateurs (pas de ligne à verrouiller) : index unique.
            if (SettingVersion::query()->where('version', $baseVersion + 1)->exists()) {
                throw new ApiException('settings_changed', __('Les paramètres ont été modifiés entre-temps : rechargez la page.'), 409, [
                    'version' => $baseVersion + 1,
                ]);
            }

            throw $e;
        } finally {
            $this->apply();
        }
    }

    /** @return array<string, mixed> */
    private function configValues(): array
    {
        return $this->cast(array_combine(array_keys(self::DEFINITIONS), array_map(fn (string $key) => config($key), array_keys(self::DEFINITIONS))));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function cast(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (self::DEFINITIONS[$key]['type']) {
                'money', 'integer' => (int) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'choice' => (string) $value,
            };
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function set(array $values): void
    {
        foreach ($this->cast($values) as $key => $value) {
            config([$key => $value]);
        }
    }

    /** @param array<string, mixed> $values */
    private function assertConsistent(array $values): void
    {
        $errors = [];

        if ($values['betting.max_stake_minor'] < $values['betting.min_stake_minor']) {
            $errors['values.betting.max_stake_minor'] = __('La mise maximale doit être supérieure ou égale à la mise minimale.');
        }
        if ($values['betting.max_payout_minor'] < $values['betting.max_stake_minor']) {
            $errors['values.betting.max_payout_minor'] = __('Le plafond de gain doit être supérieur ou égal à la mise maximale.');
        }
        if ($values['sports_data.provider'] === 'theodds' && blank(config('sports_data.theodds.key'))) {
            $errors['values.sports_data.provider'] = __('The Odds API exige une clé (SPORTS_DATA_API_KEY) dans la configuration du serveur.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
