<?php

namespace App\Modules\SportsData\Data;

final readonly class SportData
{
    public function __construct(
        public string $key,
        public string $group,
        public string $title,
        public bool $active,
        /** Compétition à vainqueur final (marché "outright"), sans matchs individuels. */
        public bool $hasOutrights,
    ) {}
}
