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

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Devises différentes : {$this->currency} / {$other->currency}");
        }
    }
}
