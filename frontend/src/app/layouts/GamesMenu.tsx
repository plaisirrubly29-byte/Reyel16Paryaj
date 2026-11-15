import { useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { useBetSlip } from '../../features/betting/slip/BetSlipContext';
import { CompetitionsBrowser } from '../../features/catalogue/components/CompetitionsMenu';
import { useLotteryAvailable } from '../../features/lottery/api/lottery';
import { virtualGamesEnabled } from '../../features/virtual/api/virtualGames';
import { Icon, type IconName } from '../../shared/components/Icon';

type Game = { key: string; label: string; hint: string; icon: IconName; to?: string };

/** Jeux hors paris sportifs, dans l'ordre du menu. Sans lien (`to`) : affiché « Bientôt », non cliquable. */
function useGames(): Game[] {
  const lottery = useLotteryAvailable();
  return [
    { key: 'virtual', label: 'Jeux virtuels', hint: 'Courses et football virtuels', icon: 'gamepad', to: virtualGamesEnabled() ? '/jeux-virtuels' : undefined },
    { key: 'casino', label: 'Casino', hint: 'Machines à sous, tables', icon: 'dice' },
    { key: 'crash', label: 'Reyel Crash', hint: 'Encaissez avant le crash', icon: 'trendUp' },
    { key: 'borlette', label: 'Borlette', hint: 'Tirages et boules du jour', icon: 'lottery', to: lottery ? '/borlette' : undefined },
  ];
}

/**
 * Onglet de la barre mobile qui ouvre un panneau montant du bas (au-dessus de la barre).
 * Le panneau se referme au changement de page (chemin ou filtres), avec Échap, en touchant le fond, ou quand le
 * panier s'ouvre ; ouvrir le panneau referme le panier.
 */
function SheetTab({ id, label, icon, title, className, children }: {
  id: string; label: string; icon: IconName; title: string; className?: string;
  children: (close: () => void) => ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const slip = useBetSlip();
  const { pathname, search } = useLocation();
  const close = () => setOpen(false);

  useEffect(() => { setOpen(false); }, [pathname, search]);
  useEffect(() => { if (slip.panelOpen) setOpen(false); }, [slip.panelOpen]);
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  function toggle() {
    if (!open) slip.setPanelOpen(false);
    setOpen(!open);
  }

  return (
    <>
      <button type="button" aria-expanded={open} aria-controls={id} onClick={toggle}>
        <span className="tab-icon"><Icon name={icon} /></span>
        {label}
      </button>

      {/* Rendu hors de la barre d'onglets, dont les styles de liens et de boutons ne doivent pas s'appliquer. */}
      {open && createPortal(
        <>
          <div className="games-backdrop" aria-hidden="true" onClick={close} />
          <div id={id} className={`games-sheet${className ? ` ${className}` : ''}`} role="dialog" aria-label={label}>
            <div className="games-sheet-head">
              <strong>{title}</strong>
              <button type="button" className="games-close" aria-label="Fermer" onClick={close}>
                <Icon name="close" size={18} />
              </button>
            </div>
            {children(close)}
          </div>
        </>,
        document.body,
      )}
    </>
  );
}

/** Onglet « Menu » : championnats par sport et recherche (contenu du trophée de l'en-tête sur ordinateur). */
export function MenuTab() {
  return (
    <SheetTab id="main-menu" label="Menu" icon="menu" title="Championnats" className="menu-sheet">
      {() => <div className="menu-sheet-body"><CompetitionsBrowser /></div>}
    </SheetTab>
  );
}

/** Onglet « Jeux » : jeux virtuels, casino, Reyel Crash et borlette (les paris sportifs ont leurs propres onglets). */
export function GamesTab() {
  const games = useGames();

  return (
    <SheetTab id="games-menu" label="Jeux" icon="games" title="Choisir un jeu">
      {(close) => (
        <ul className="games-list">
          {games.map((g) => {
            const body = (
              <>
                <span className="game-icon" aria-hidden="true"><Icon name={g.icon} size={26} /></span>
                <span className="game-label">{g.label}</span>
                <span className="game-hint">{g.hint}</span>
                {!g.to && <span className="game-soon">Bientôt</span>}
              </>
            );
            return (
              <li key={g.key}>
                {g.to
                  ? <Link to={g.to} className={`game-tile game-${g.key}`} onClick={close}>{body}</Link>
                  : <span className={`game-tile game-${g.key} is-soon`} aria-disabled="true">{body}</span>}
              </li>
            );
          })}
        </ul>
      )}
    </SheetTab>
  );
}
