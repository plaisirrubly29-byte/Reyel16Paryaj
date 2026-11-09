import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { useBetSlip } from '../../features/betting/slip/BetSlipContext';
import { useLotteryAvailable } from '../../features/lottery/api/lottery';
import { virtualGamesEnabled } from '../../features/virtual/api/virtualGames';
import { Icon, type IconName } from '../../shared/components/Icon';

type Game = { key: string; label: string; hint: string; icon: IconName; to?: string };

/** Jeux de la plateforme, dans l'ordre du menu. Sans lien (`to`) : affiché « Bientôt », non cliquable. */
function useGames(): Game[] {
  const lottery = useLotteryAvailable();
  return [
    { key: 'sport', label: 'Sport', hint: 'Football, basket, tennis…', icon: 'ball', to: '/sports' },
    { key: 'borlette', label: 'Borlette', hint: 'Tirages et boules du jour', icon: 'lottery', to: lottery ? '/borlette' : undefined },
    { key: 'virtual', label: 'Jeux virtuels', hint: 'Football et courses virtuels', icon: 'gamepad', to: virtualGamesEnabled() ? '/jeux-virtuels' : undefined },
    { key: 'casino', label: 'Casino', hint: 'Machines à sous, tables', icon: 'dice' },
  ];
}

/**
 * Onglet « Jeux » de la barre mobile (à la place d'« Accueil » : la plateforme s'ouvre toujours sur l'accueil,
 * le logo y ramène). Ouvre un panneau qui monte du bas avec les jeux : Sport, Borlette, Jeux virtuels, Casino.
 * Se referme au changement de page, avec Échap, en touchant le fond, ou quand le panier s'ouvre.
 */
export function GamesTab() {
  const [open, setOpen] = useState(false);
  const slip = useBetSlip();
  const { pathname } = useLocation();
  const games = useGames();

  useEffect(() => { setOpen(false); }, [pathname]);
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
      <button type="button" aria-expanded={open} aria-controls="games-menu" onClick={toggle}>
        <span className="tab-icon"><Icon name="games" /></span>
        Jeux
      </button>

      {/* Rendu hors de la barre d'onglets, dont les styles de liens et de boutons ne doivent pas s'appliquer. */}
      {open && createPortal(
        <>
          <div className="games-backdrop" aria-hidden="true" onClick={() => setOpen(false)} />
          <div id="games-menu" className="games-sheet" role="dialog" aria-label="Jeux">
            <div className="games-sheet-head">
              <strong>Choisir un jeu</strong>
              <button type="button" className="games-close" aria-label="Fermer" onClick={() => setOpen(false)}>
                <Icon name="close" size={18} />
              </button>
            </div>
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
                      ? <Link to={g.to} className={`game-tile game-${g.key}`} onClick={() => setOpen(false)}>{body}</Link>
                      : <span className={`game-tile game-${g.key} is-soon`} aria-disabled="true">{body}</span>}
                  </li>
                );
              })}
            </ul>
          </div>
        </>,
        document.body,
      )}
    </>
  );
}
