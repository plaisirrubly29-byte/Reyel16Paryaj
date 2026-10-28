import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useMe } from '../../auth/api/auth';

/**
 * Coupon de pari (sélections choisies par le joueur), conservé dans le navigateur.
 *
 * Ce n'est qu'une liste d'intentions : la cote, la disponibilité du marché et la mise sont
 * revalidées par le serveur au moment du pari (POST /bets, P4-03). D'où la version de cote
 * mémorisée avec chaque sélection.
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
  /** Vrai juste après un ajout refusé parce que le coupon est complet. */
  full: boolean;
  /** Panneau du coupon ouvert (mobile : ouvert depuis la barre d'onglets). */
  panelOpen: boolean;
  setPanelOpen: (open: boolean) => void;
  /** Mise saisie en gourdes (texte). Gardée pendant la navigation, mais pas enregistrée dans le navigateur. */
  stake: string;
  setStake: (value: string) => void;
};

const STORAGE_KEY = 'r16p.betslip';
export const MAX_SELECTIONS = 20;

const BetSlipContext = createContext<BetSlip | null>(null);

function isSelection(value: unknown): value is SlipSelection {
  const s = value as SlipSelection;
  return typeof s === 'object' && s !== null
    && typeof s.selectionId === 'string' && typeof s.eventId === 'string' && typeof s.marketId === 'string'
    && typeof s.odds === 'string' && typeof s.startsAt === 'string' && typeof s.oddsVersion === 'number';
}

function load(): SlipSelection[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    return Array.isArray(parsed) ? parsed.filter(isSelection).slice(0, MAX_SELECTIONS) : [];
  } catch {
    return [];
  }
}

export function BetSlipProvider({ children }: { children: ReactNode }) {
  const [selections, setSelections] = useState<SlipSelection[]>(load);
  const [full, setFull] = useState(false);
  const [panelOpen, setPanelOpen] = useState(false);
  const [stake, setStake] = useState('');
  const { data: user, isSuccess } = useMe();
  const previousUserId = useRef<string | null | undefined>(undefined);

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(selections));
    } catch {
      // Stockage indisponible (navigation privée) : le coupon reste en mémoire.
    }
  }, [selections]);

  // Déconnexion (ou changement de compte) : le coupon d'un joueur ne passe pas au suivant.
  useEffect(() => {
    if (!isSuccess) return;
    const currentId = user?.id ?? null;
    if (previousUserId.current !== undefined && previousUserId.current !== null && previousUserId.current !== currentId) {
      setSelections([]);
    }
    previousUserId.current = currentId;
  }, [isSuccess, user?.id]);

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
  }, [selections]);

  const value = useMemo<BetSlip>(() => ({
    selections,
    full,
    panelOpen,
    setPanelOpen,
    stake,
    setStake,
    has: (id) => selections.some((s) => s.selectionId === id),
    toggle,
    remove: (id) => { setFull(false); setSelections((current) => current.filter((s) => s.selectionId !== id)); },
    removeMany: (ids) => { setFull(false); setSelections((current) => current.filter((s) => !ids.includes(s.selectionId))); },
    updateOdds: (changes) => setSelections((current) => current.map((s) => {
      const change = changes.find((c) => c.selectionId === s.selectionId);
      return change ? { ...s, odds: change.odds, oddsVersion: change.oddsVersion } : s;
    })),
    clear: () => { setFull(false); setSelections([]); },
  }), [selections, full, panelOpen, stake, toggle]);

  return <BetSlipContext.Provider value={value}>{children}</BetSlipContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useBetSlip(): BetSlip {
  const slip = useContext(BetSlipContext);
  if (!slip) throw new Error('useBetSlip doit être utilisé dans <BetSlipProvider>.');
  return slip;
}
