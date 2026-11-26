import { useId } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Icon } from '../../../shared/components/Icon';
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

/**
 * Étape 1 du dépôt ou du retrait : une carte par portefeuille mobile (logo officiel, nom, ligne d'aide). Le nom lu
 * par les lecteurs d'écran reste celui du moyen (« MonCash ») ; la ligne d'aide en est la description.
 */
export function MethodPicker({ title, options, onPick, describe }: {
  title: string;
  options: MethodOption[];
  onPick: (key: string) => void;
  /** Ligne d'aide sous le nom du moyen (ex. « Payer depuis votre compte »). */
  describe?: (option: MethodOption) => string;
}) {
  const id = useId();

  return (
    <div className="method-picker" role="group" aria-label={title}>
      <p className="method-picker-title">{title}</p>
      <div className="method-tiles">
        {options.map((o) => (
          <button
            key={o.key}
            type="button"
            className={`method-tile method-tile-${o.key}${LOGOS[o.key] ? ' has-logo' : ''}`}
            aria-label={o.label}
            aria-describedby={describe ? `${id}-${o.key}` : undefined}
            onClick={() => onPick(o.key)}
          >
            <span className="method-tile-media"><MethodLogo option={o} /></span>
            <span className="method-tile-text">
              <span className="method-tile-label">{o.label}</span>
              {describe && <span id={`${id}-${o.key}`} className="method-tile-hint">{describe(o)}</span>}
            </span>
            <span className="method-tile-arrow" aria-hidden="true"><Icon name="chevron" size={18} /></span>
          </button>
        ))}
      </div>
    </div>
  );
}

/** Chargement des moyens de paiement : deux cartes grisées à la place des vraies, sans saut de mise en page. */
export function MethodPickerSkeleton() {
  return (
    <div className="method-tiles method-tiles-loading" role="status" aria-label="Chargement des moyens de paiement…">
      {[0, 1].map((i) => (
        <span key={i} className="method-tile method-tile-skeleton" aria-hidden="true">
          <span className="skeleton skeleton-logo" />
          <span className="method-tile-text"><span className="skeleton skeleton-line" /><span className="skeleton skeleton-line short" /></span>
        </span>
      ))}
    </div>
  );
}

/** Étape 2 : rappel du moyen choisi (petit logo), avec « Changer » pour revenir au choix. */
export function MethodChosen({ option, verb, onChange }: { option: MethodOption; verb: string; onChange?: () => void }) {
  return (
    <div className={`method-chosen method-tile-${option.key}`}>
      <MethodLogo option={option} decorative />
      <span className="method-chosen-text"><span className="muted small">{verb}</span> <strong>{option.label}</strong></span>
      {onChange && <button type="button" className="method-chosen-change" onClick={onChange}>Changer</button>}
    </div>
  );
}
