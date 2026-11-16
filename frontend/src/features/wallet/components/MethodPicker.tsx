import { useSearchParams } from 'react-router-dom';

/** Moyen de paiement mobile proposé au joueur (MonCash, NatCash…). `key` : identifiant gardé dans l'URL (?moyen=). */
export type MethodOption = { key: string; label: string };

/** Moyen choisi pour l'opération en cours, gardé dans l'URL (?moyen=moncash) : le bouton « retour » le défait. */
export function useChosenMethod(): [string | null, (key: string | null) => void] {
  const [params, setParams] = useSearchParams();
  const set = (key: string | null) => {
    const p = new URLSearchParams(params);
    if (key) p.set('moyen', key); else p.delete('moyen');
    setParams(p, { replace: !key });
  };
  return [params.get('moyen'), set];
}

/** Étape 1 du dépôt ou du retrait : le joueur choisit son portefeuille mobile, en grandes tuiles aux couleurs du service. */
export function MethodPicker({ title, options, onPick }: { title: string; options: MethodOption[]; onPick: (key: string) => void }) {
  return (
    <div className="method-picker" role="group" aria-label={title}>
      <p className="method-picker-title">{title}</p>
      <div className="method-tiles">
        {options.map((o) => (
          <button key={o.key} type="button" className={`method-tile method-tile-${o.key}`} onClick={() => onPick(o.key)}>
            <span className="method-tile-logo" aria-hidden="true">{o.label.charAt(0)}</span>
            <span className="method-tile-label">{o.label}</span>
          </button>
        ))}
      </div>
    </div>
  );
}

/** Étape 2 : rappel du moyen choisi, avec « Changer » pour revenir au choix. */
export function MethodChosen({ option, verb, onChange }: { option: MethodOption; verb: string; onChange?: () => void }) {
  return (
    <div className={`method-chosen method-tile-${option.key}`}>
      <span className="method-tile-logo" aria-hidden="true">{option.label.charAt(0)}</span>
      <span>{verb} <strong>{option.label}</strong></span>
      {onChange && <button type="button" className="link-button" onClick={onChange}>Changer</button>}
    </div>
  );
}
