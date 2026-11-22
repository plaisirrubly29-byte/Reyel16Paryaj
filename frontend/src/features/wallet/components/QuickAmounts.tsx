/** « 1 000 », « 2 500 » : sans décimales inutiles, la devise est affichée à part, plus petite. */
const compact = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });

/** Montant en centimes → saisie du champ (« 1000 », « 1234.50 »), au format lu par parseStakeMinor. */
const minorToInput = (minor: number) => (minor % 100 === 0 ? String(minor / 100) : (minor / 100).toFixed(2));

export type QuickAmount = { minor: number; label?: string };

/**
 * Montants proposés en un toucher sous le champ : le bouton du montant déjà saisi est marqué « pressé ».
 * Rien n'est affiché quand aucun montant n'entre dans les bornes.
 */
export function QuickAmounts({ amounts, currency, current, onPick, label = 'Montants rapides' }: {
  amounts: QuickAmount[];
  currency: string;
  /** Montant saisi (en centimes), pour marquer le bouton correspondant. */
  current: bigint | null;
  onPick: (value: string) => void;
  label?: string;
}) {
  if (amounts.length === 0) return null;

  return (
    <div className="quick-amounts amount-chips" role="group" aria-label={label}>
      {amounts.map((a) => (
        <button
          key={`${a.label ?? ''}${a.minor}`}
          type="button"
          className="amount-chip"
          aria-pressed={current !== null && current === BigInt(a.minor)}
          onClick={() => onPick(minorToInput(a.minor))}
        >
          {a.label ?? <>{compact.format(a.minor / 100)} <small>{currency}</small></>}
        </button>
      ))}
    </div>
  );
}
