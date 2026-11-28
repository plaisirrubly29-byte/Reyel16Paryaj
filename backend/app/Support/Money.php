<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Montant monétaire immuable stocké en unité mineure (ex. centimes).
 *
 * Règle d'architecture 5.1 : les montants financiers ne sont jamais des float.
 * En base, utiliser une colonne bigInteger (unité mineure) + une colonne devise.
 */
final class Money
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function ofMinor(int $minor, string $currency): self
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Devise ISO 4217 invalide : {$currency}");
        }

        return new self($minor, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    /**
     * Montant lisible en français, ex. « 1 500,00 HTG » (espaces insécables : le montant ne se coupe pas en fin de
     * ligne). Calcul en entiers, sans float ni extension intl (absente des images Docker, #280). Deux décimales.
     */
    public function format(): string
    {
        $absolute = abs($this->minor);
        $units = number_format(intdiv($absolute, 100), 0, '', "\u{00A0}");
        $cents = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return ($this->minor < 0 ? '-' : '')."{$units},{$cents}\u{00A0}{$this->currency}";
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Devises différentes : {$this->currency} / {$other->currency}");
        }
    }
}
