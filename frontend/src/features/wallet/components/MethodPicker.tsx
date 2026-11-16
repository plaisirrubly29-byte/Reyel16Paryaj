import { useSearchParams } from 'react-router-dom';
import moncashLogo from '../../../assets/payments/moncash.jpg';
import natcashLogo from '../../../assets/payments/natcash.jpg';

/** Moyen de paiement mobile proposé au joueur (MonCash, NatCash…). `key` : identifiant gardé dans l'URL (?moyen=). */
export type MethodOption = { key: string; label: string };

/** Logos officiels des portefeuilles mobiles ; un moyen sans logo garde une pastille avec son initiale. */
const LOGOS: Record<string, string> = { moncash: moncashLogo, natcash: natcashLogo };

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

/** Logo du moyen (image, nom lu par les lecteurs d'écran) ou, à défaut, pastille avec l'initiale. */
function MethodLogo({ option, decorative = false }: { option: MethodOption; decorative?: boolean }) {
  const logo = LOGOS[option.key];
  if (logo) return <img className="method-logo" src={logo} alt={decorative ? '' : option.label} width={512} height={512} />;
  return <span className="method-tile-logo" aria-hidden="true">{option.label.charAt(0)}</span>;
}

/** Étape 1 du dépôt ou du retrait : le joueur touche le logo de son portefeuille mobile. */
export function MethodPicker({ title, options, onPick }: { title: string; options: MethodOption[]; onPick: (key: string) => void }) {
  return (
    <div className="method-picker" role="group" aria-label={title}>
      <p className="method-picker-title">{title}</p>
      <div className="method-tiles">
        {options.map((o) => (
          <button
            key={o.key}
            type="button"
            className={`method-tile method-tile-${o.key}${LOGOS[o.key] ? ' has-logo' : ''}`}
            onClick={() => onPick(o.key)}
          >
            <MethodLogo option={o} />
            {!LOGOS[o.key] && <span className="method-tile-label">{o.label}</span>}
          </button>
        ))}
      </div>
    </div>
  );
}

/** Étape 2 : rappel du moyen choisi (petit logo), avec « Changer » pour revenir au choix. */
export function MethodChosen({ option, verb, onChange }: { option: MethodOption; verb: string; onChange?: () => void }) {
  return (
    <div className={`method-chosen method-tile-${option.key}`}>
      <MethodLogo option={option} decorative />
      <span>{verb} <strong>{option.label}</strong></span>
      {onChange && <button type="button" className="link-button" onClick={onChange}>Changer</button>}
    </div>
  );
}
