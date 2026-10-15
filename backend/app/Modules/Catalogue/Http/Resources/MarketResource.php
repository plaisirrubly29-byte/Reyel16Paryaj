<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\SportsData\Models\Market;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Market */
class MarketResource extends JsonResource
{
    private const LABELS = ['h2h' => 'Résultat du match (1X2)'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'key' => $this->key,
            'name' => self::LABELS[$this->key] ?? $this->key,
            // À renvoyer avec la sélection lors du pari : le serveur refuse une version périmée (P4-03).
            'odds_version' => $this->odds_version,
            'odds_updated_at' => $this->odds_updated_at?->toIso8601String(),
            'selections' => $this->selections->map(fn ($s) => [
                'id' => (string) $s->id,
                'key' => $s->key,
                'label' => match ($s->key) {
                    'home' => '1',
                    'draw' => 'X',
                    'away' => '2',
                    default => $s->key,
                },
                'name' => $s->name,
                // Cote en chaîne décimale (ex. "2.10") : jamais de nombre flottant.
                'odds' => self::odds($s->odds),
            ])->values(),
        ];
    }

    /** "2.100" → "2.10", "1.005" → "1.005". */
    public static function odds(string $odds): string
    {
        return str_ends_with($odds, '0') ? substr($odds, 0, -1) : $odds;
    }
}
