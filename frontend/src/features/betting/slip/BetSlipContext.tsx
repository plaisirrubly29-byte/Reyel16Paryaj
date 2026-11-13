import { createContext, useCallback, useContext, useEffect, useMemo, useReducer, useState, type ReactNode } from 'react';
import { useMe } from '../../auth/api/auth';
import { readStoredSlip, SLIP_FORGOTTEN_EVENT, writeStoredSlip } from './storage';

/**
 * Coupon de pari (sélections choisies par le joueur), conservé dans le navigateur.
 *
 * Ce n'est qu'une liste d'intentions : la cote, la disponibilité du marché et la mise sont
 * revalidées par le serveur au moment du pari (POST /bets, P4-03). D'où la version de cote
 * mémorisée avec chaque sélection.
 *
 * Le coupon appartient au compte qui l'a composé (revue P8-02, F11) : sur un appareil partagé, il n'est
 * montré ni au visiteur ni au joueur suivant. Voir `reduce` plus bas.
 */
export type SlipSelection = {
  selectionId: string;
  eventId: string;
  marketId: string;
  oddsVersion: number;
  /** Cote affichée au moment du choix (chaîne décimale). */
  odds: string;
  label: string; // 1 / X / 2, +2.5 / -2.5
  pick: string; // nom de la sélection (équipe, « Match nul », « Plus de 2.5 »…)
  /** Nom du marché (« Résultat du match », « Nombre de buts »…) ; absent dans les anciens coupons. */
  market?: string;
  match: string; // « Domicile – Extérieur »
  startsAt: string;
};

export type ToggleResult = 'added' | 'replaced' | 'removed' | 'full';

type BetSlip = {
  selections: SlipSelection[];
  has: (selectionId: string) => boolean;
  /**
   * Ajoute la sélection (un choix par marché et par match), remplace celui du même marché ou la retire.
   * Refusé ('full') si le coupon contient déjà MAX_SELECTIONS sélections.
   */
  toggle: (selection: SlipSelection) => ToggleResult;
  remove: (selectionId: string) => void;
  /** Retire plusieurs sélections (ex. celles que le serveur ne propose plus). */
  removeMany: (selectionIds: string[]) => void;
  /** Remplace la cote (et sa version) des sélections dont le serveur a changé la cote. */
  updateOdds: (changes: { selectionId: string; odds: string; oddsVersion: number }[]) => void;
  clear: () => void;
  /** Remplace tout le coupon (ex. rejouer un ticket de Mes paris), type de pari redéduit. */
  replace: (selections: SlipSelection[]) => void;
  /** Vrai juste après un ajout refusé parce que le coupon est complet. */
  full: boolean;
  /** Panneau du coupon ouvert (mobile : ouvert depuis la barre d'onglets). */
  panelOpen: boolean;
  setPanelOpen: (open: boolean) => void;
  /**
   * Mise saisie en gourdes (texte) : celle du combiné, ou du pari simple quand le coupon n'a qu'une sélection.
   * Gardée pendant la navigation, mais pas enregistrée dans le navigateur.
   */
  stake: string;
  setStake: (value: string) => void;
  /**
   * Type de pari : choisi par le joueur, sinon déduit (une sélection = simple, plusieurs = multiple).
   * Le combiné demande au moins 2 sélections.
   */
  mode: BetMode;
  setMode: (mode: BetMode) => void;
  /** Mise de chaque pari simple (mode simple avec plusieurs sélections), par sélection. */
  stakes: Record<string, string>;
  setStakeFor: (selectionId: string, value: string) => void;
  /** « Même mise pour tous » : la même mise sur chaque sélection. */
  setAllStakes: (value: string) => void;
};

export type BetMode = 'simple' | 'multiple';

export const MAX_SELECTIONS = 20;

const BetSlipContext = createContext<BetSlip | null>(null);

function isSelection(value: unknown): value is SlipSelection {
  const s = value as SlipSelection;
  return typeof s === 'object' && s !== null
    && typeof s.selectionId === 'string' && typeof s.eventId === 'string' && typeof s.marketId === 'string'
    && typeof s.odds === 'string' && typeof s.startsAt === 'string' && typeof s.oddsVersion === 'number';
}

type OwnedSlip = { owner: string; selections: SlipSelection[] };

type Core = {
  /** Coupon affiché. */
  selections: SlipSelection[];
  /** Compte de la session une fois connu ; null : visiteur. Le coupon affiché est le sien. */
  owner: string | null;
  /**
   * Coupon d'un compte qui n'est pas (ou plus) connecté : caché, mais gardé dans le navigateur pour que
   * son joueur le retrouve en se reconnectant (session expirée pendant la nuit, par exemple).
   */
  held: OwnedSlip | null;
};

type Update = SlipSelection[] | ((current: SlipSelection[]) => SlipSelection[]);

type Action =
  | { type: 'set'; next: Update }
  /** Compte de la session connu ou changé (GET /me) ; null : personne n'est connecté. */
  | { type: 'identity'; id: string | null }
  /** Déconnexion : le coupon est effacé, rien n'est mis de côté. */
  | { type: 'forget' };

function load(): Core {
  const stored = readStoredSlip();
  const selections = Array.isArray(stored.selections) ? stored.selections.filter(isSelection).slice(0, MAX_SELECTIONS) : [];

  // Tant que GET /me n'a pas répondu, le coupon d'un compte reste caché : il n'apparaît que pour ce compte.
  return stored.owner !== null && selections.length > 0
    ? { selections: [], owner: null, held: { owner: stored.owner, selections } }
    : { selections, owner: null, held: null };
}

function reduce(state: Core, action: Action): Core {
  switch (action.type) {
    case 'set': {
      const selections = typeof action.next === 'function' ? action.next(state.selections) : action.next;
      // Un nouveau coupon prend la place de celui mis de côté (une seule place dans le navigateur).
      return { ...state, selections, held: selections.length > 0 ? null : state.held };
    }
    case 'identity': {
      const { id } = action;
      if (state.held) {
        // Son joueur est de retour : le coupon réapparaît. Un autre compte : il est abandonné.
        if (state.held.owner === id) return { selections: state.held.selections, owner: id, held: null };
        return { ...state, owner: id, held: id === null ? state.held : null };
      }
      if (state.owner !== null && state.owner !== id) {
        // La session a pris fin sans déconnexion (expirée, fermée depuis un autre appareil) ou le compte a changé :
        // le coupon n'est plus affiché. Il n'est mis de côté que si personne d'autre ne s'est connecté.
        const held = id === null && state.selections.length > 0 ? { owner: state.owner, selections: state.selections } : null;
        return { selections: [], owner: id, held };
      }
      // Coupon d'un visiteur : le compte qui se connecte le reprend.
      return state.owner === id ? state : { ...state, owner: id };
    }
    case 'forget':
      return { ...state, selections: [], held: null };
  }
}

export function BetSlipProvider({ children }: { children: ReactNode }) {
  const [{ selections, owner, held }, dispatch] = useReducer(reduce, undefined, load);
  const setSelections = useCallback((next: Update) => dispatch({ type: 'set', next }), []);
  const [full, setFull] = useState(false);
  const [panelOpen, setPanelOpen] = useState(false);
  const [stake, setStake] = useState('');
  const [chosenMode, setChosenMode] = useState<BetMode | null>(null);
  const [stakes, setStakes] = useState<Record<string, string>>({});
  const { data: user, isSuccess } = useMe();

  const mode: BetMode = selections.length < 2 ? 'simple' : chosenMode ?? 'multiple';

  // Coupon vidé : le prochain pari repart du type déduit, sans les mises des paris précédents.
  useEffect(() => {
    if (selections.length > 0) return;
    setChosenMode(null);
    setStakes({});
  }, [selections.length]);

  // Le coupon mis de côté reste tel quel dans le navigateur, tant qu'un autre ne le remplace pas.
  useEffect(() => {
    if (!held) writeStoredSlip(owner, selections);
  }, [selections, owner, held]);

  // Le coupon d'un joueur ne passe pas au suivant : il suit le compte de la session.
  useEffect(() => {
    if (isSuccess) dispatch({ type: 'identity', id: user?.id ?? null });
  }, [isSuccess, user?.id]);

  // Déconnexion : le coupon vient d'être effacé du navigateur (useLogout), celui affiché se vide aussi.
  useEffect(() => {
    const forget = () => dispatch({ type: 'forget' });
    window.addEventListener(SLIP_FORGOTTEN_EVENT, forget);
    return () => window.removeEventListener(SLIP_FORGOTTEN_EVENT, forget);
  }, []);

  // Le résultat est calculé sur l'état courant (une fonction passée à setState s'exécute plus tard).
  const toggle = useCallback((selection: SlipSelection): ToggleResult => {
    const current = selections;
    let result: ToggleResult;
    let next: SlipSelection[];

    if (current.some((s) => s.selectionId === selection.selectionId)) {
      result = 'removed';
      next = current.filter((s) => s.selectionId !== selection.selectionId);
    } else if (current.some((s) => s.eventId === selection.eventId && s.marketId === selection.marketId)) {
      result = 'replaced';
      next = [
        ...current.filter((s) => s.eventId !== selection.eventId || s.marketId !== selection.marketId),
        selection,
      ];
    } else if (current.length >= MAX_SELECTIONS) {
      result = 'full';
      next = current;
    } else {
      result = 'added';
      next = [...current, selection];
    }

    setSelections(next);
    setFull(result === 'full');
    return result;
  }, [selections, setSelections]);

  const setMode = useCallback((next: BetMode) => {
    setChosenMode(next);
    // Passage aux paris simples : la mise déjà saisie devient celle de chaque sélection encore sans mise.
    if (next === 'simple' && stake) {
      setStakes((current) => Object.fromEntries(selections.map((s) => [s.selectionId, current[s.selectionId] || stake])));
    }
  }, [selections, stake]);

  const value = useMemo<BetSlip>(() => ({
    selections,
    full,
    panelOpen,
    setPanelOpen,
    stake,
    setStake,
    mode,
    setMode,
    stakes,
    setStakeFor: (id, amount) => setStakes((current) => ({ ...current, [id]: amount })),
    setAllStakes: (amount) => setStakes(Object.fromEntries(selections.map((s) => [s.selectionId, amount]))),
    has: (id) => selections.some((s) => s.selectionId === id),
    toggle,
    remove: (id) => { setFull(false); setSelections((current) => current.filter((s) => s.selectionId !== id)); },
    removeMany: (ids) => { setFull(false); setSelections((current) => current.filter((s) => !ids.includes(s.selectionId))); },
    updateOdds: (changes) => setSelections((current) => current.map((s) => {
      const change = changes.find((c) => c.selectionId === s.selectionId);
      return change ? { ...s, odds: change.odds, oddsVersion: change.oddsVersion } : s;
    })),
    clear: () => { setFull(false); setSelections([]); },
    replace: (next) => { setFull(false); setChosenMode(null); setStakes({}); setSelections(next.slice(0, MAX_SELECTIONS)); },
  }),[selections, setSelections, full, panelOpen, stake, mode, setMode, stakes, toggle]);

  return <BetSlipContext.Provider value={value}>{children}</BetSlipContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useBetSlip(): BetSlip {
  const slip = useContext(BetSlipContext);
  if (!slip) throw new Error('useBetSlip doit être utilisé dans <BetSlipProvider>.');
  return slip;
}
