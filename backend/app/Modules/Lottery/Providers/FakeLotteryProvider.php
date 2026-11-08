<?php

namespace App\Modules\Lottery\Providers;

use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Data\DrawData;
use App\Modules\Lottery\Data\DrawResultData;
use App\Modules\Lottery\Exceptions\LotteryException;
use Carbon\CarbonImmutable;

/**
 * Faux fournisseur déterministe pour le développement et les tests (aucun appel réseau).
 *
 * Quatre tirages quotidiens ; un résultat n'est publié qu'une fois l'heure du tirage passée. Les numéros sont tirés
 * d'un hachage de la clé et de la date (toujours les mêmes pour un même tirage). Les lots suivent la convention
 * courante, à confirmer avec le vrai fournisseur : 1er lot = deux derniers chiffres du pick 3, 2e et 3e lots = deux
 * premiers et deux derniers chiffres du pick 4.
 *
 * En test : $fake->failWith(LotteryException::UNAVAILABLE) simule une panne ; $fake->setResult(...) impose un
 * résultat (y compris invalide, ou différent d'un résultat déjà importé) ; $fake->withoutResults() n'en publie aucun.
 */
class FakeLotteryProvider implements LotteryProvider
{
    /** clé => [nom, heure locale, fuseau] */
    public const DRAWS = [
        'fl_midday' => ['Florid Midi', '13:30', 'America/New_York'],
        'ny_midday' => ['New York Midi', '14:30', 'America/New_York'],
        'fl_evening' => ['Florid Swa', '21:45', 'America/New_York'],
        'ny_evening' => ['New York Swa', '22:30', 'America/New_York'],
    ];

    private ?string $failure = null;

    private bool $generated = true;

    /** @var array<string, DrawResultData> "clé|date" => résultat imposé */
    private array $overrides = [];

    public function name(): string
    {
        return 'fake';
    }

    public function failWith(?string $reason): self
    {
        $this->failure = $reason;

        return $this;
    }

    /** Plus de résultats générés : seuls ceux imposés par setResult() sont publiés. */
    public function withoutResults(): self
    {
        $this->generated = false;

        return $this;
    }

    public function setResult(string $drawKey, string $date, string $lot1, string $lot2, string $lot3, ?string $pick3 = null, ?string $pick4 = null): self
    {
        $this->overrides["{$drawKey}|{$date}"] = new DrawResultData($drawKey, $date, $lot1, $lot2, $lot3, $pick3, $pick4, CarbonImmutable::now(), "fake-{$drawKey}-{$date}");

        return $this;
    }

    public function draws(): array
    {
        $this->throwIfFailing();

        return array_map(
            fn (string $key, array $d) => new DrawData($key, $d[0], $d[1], $d[2]),
            array_keys(self::DRAWS),
            self::DRAWS,
        );
    }

    public function results(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->throwIfFailing();

        $results = [];
        foreach (self::DRAWS as $key => [, $time, $timezone]) {
            for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $date = $day->toDateString();
                if (isset($this->overrides["{$key}|{$date}"])) {
                    $results[] = $this->overrides["{$key}|{$date}"];

                    continue;
                }

                $drawAt = CarbonImmutable::parse("{$date} {$time}", $timezone);
                if ($this->generated && $drawAt->lessThanOrEqualTo(CarbonImmutable::now())) {
                    $results[] = $this->generate($key, $date, $drawAt);
                }
            }
        }

        // Résultats imposés pour un tirage que le fournisseur ne liste pas (cas d'erreur à tester).
        foreach ($this->overrides as $override) {
            if (! isset(self::DRAWS[$override->drawKey])) {
                $results[] = $override;
            }
        }

        return $results;
    }

    private function generate(string $key, string $date, CarbonImmutable $drawAt): DrawResultData
    {
        $digits = substr((string) hexdec(substr(hash('sha256', "{$key}|{$date}"), 0, 12)), -7);
        $digits = str_pad($digits, 7, '0', STR_PAD_LEFT);
        $pick3 = substr($digits, 0, 3);
        $pick4 = substr($digits, 3, 4);

        return new DrawResultData(
            $key, $date,
            substr($pick3, 1, 2), substr($pick4, 0, 2), substr($pick4, 2, 2),
            $pick3, $pick4,
            $drawAt->addMinutes(5)->utc(),
            "fake-{$key}-{$date}",
        );
    }

    private function throwIfFailing(): void
    {
        if ($this->failure !== null) {
            throw new LotteryException($this->failure, "Faux fournisseur de tirages : échec simulé ({$this->failure}).");
        }
    }
}
