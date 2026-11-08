<?php

namespace App\Modules\Lottery\Data;

/** Un tirage proposé par le fournisseur (ex. « New York Midi », 14:30, America/New_York, tous les jours). */
final readonly class DrawData
{
    public function __construct(
        public string $key,
        public string $name,
        /** Heure locale du tirage, "HH:MM". */
        public string $drawTime,
        public string $timezone,
        /** @var list<int>|null jours ISO (1 = lundi … 7 = dimanche) ; null = tous les jours */
        public ?array $days = null,
    ) {}
}
