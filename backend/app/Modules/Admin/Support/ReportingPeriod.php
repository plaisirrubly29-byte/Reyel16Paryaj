<?php

namespace App\Modules\Admin\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Période d'un rapport admin. Les journées sont découpées dans le fuseau d'affichage
 * (config app.display_timezone) ; les bornes sont exprimées en UTC pour interroger la base.
 */
final class ReportingPeriod
{
    public const PRESETS = ['today', 'yesterday', '7d', '30d', 'month', 'last_month', 'custom'];

    /** Plafond d'une période personnalisée (séries quotidiennes lisibles, requêtes bornées). */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $preset,
        /** Premier jour inclus, minuit local. */
        public readonly CarbonImmutable $start,
        /** Lendemain du dernier jour inclus, minuit local (borne exclue). */
        public readonly CarbonImmutable $end,
    ) {}

    public static function fromRequest(?string $preset, ?string $from = null, ?string $to = null): self
    {
        $tz = self::timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $preset ??= '7d';

        [$start, $end] = match ($preset) {
            'today' => [$today, $today->addDay()],
            'yesterday' => [$today->subDay(), $today],
            '7d' => [$today->subDays(6), $today->addDay()],
            '30d' => [$today->subDays(29), $today->addDay()],
            'month' => [$today->startOfMonth(), $today->addDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()],
            'custom' => [
                CarbonImmutable::parse((string) $from, $tz)->startOfDay(),
                CarbonImmutable::parse((string) $to, $tz)->startOfDay()->addDay(),
            ],
            default => throw new InvalidArgumentException("Période inconnue : {$preset}"),
        };

        return new self($preset, $start, $end);
    }

    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'UTC');
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->end->utc();
    }

    /** Nombre de journées locales couvertes. */
    public function days(): int
    {
        return (int) round($this->start->diffInDays($this->end));
    }

    /** @return list<string> dates locales Y-m-d de la période, dans l'ordre */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->start; $day->lt($this->end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** @return array{preset: string, from: string, to: string, days: int, timezone: string} */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->start->toDateString(),
            'to' => $this->end->subDay()->toDateString(),
            'days' => $this->days(),
            'timezone' => self::timezone(),
        ];
    }
}
