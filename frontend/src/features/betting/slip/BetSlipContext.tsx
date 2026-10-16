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
  label: string; // 1 / X / 2
  pick: string; // nom de la sélection (équipe ou « Match nul »)
  match: string; // « Domicile – Extérieur »
  startsAt: string;
};

export type ToggleResult = 'added' | 'replaced' | 'removed' | 'full';

type BetSlip = {
  selections: SlipSelection[];
  has: (selectionId: string) => boolean;
  /**
   * Ajoute la sélection (un seul choix par match : remplace un autre choix du même match), ou la retire
   * si elle est déjà choisie. Refusé ('full') si le coupon contient déjà MAX_SELECTIONS matchs.
   */
  toggle: (selection: SlipSelection) => ToggleResult;
  remove: (selectionId: string) => void;
  clear: () => void;
  /** Vrai juste après un ajout refusé parce que le coupon est complet. */
  full: boolean;
};

const STORAGE_KEY = 'r16p.betslip';
export const MAX_SELECTIONS = 20;
const PRUNE_EVERY_MS = 60_000;

const BetSlipContext = createContext<BetSlip | null>(null);

/** Une sélection n'a de sens que tant que le match n'a pas commencé. */
function upcoming(selections: SlipSelection[], now = Date.now()): SlipSelection[] {
  return selections.filter((s) => Date.parse(s.startsAt) > now);
}

function isSelection(value: unknown): value is SlipSelection {
  const s = value as SlipSelection;
  return typeof s === 'object' && s !== null
    && typeof s.selectionId === 'string' && typeof s.eventId === 'string' && typeof s.marketId === 'string'
    && typeof s.odds === 'string' && typeof s.startsAt === 'string' && typeof s.oddsVersion === 'number';
}

function load(): SlipSelection[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    return Array.isArray(parsed) ? upcoming(parsed.filter(isSelection)).slice(0, MAX_SELECTIONS) : [];
  } catch {
    return [];
  }
}

export function BetSlipProvider({ children }: { children: ReactNode }) {
  const [selections, setSelections] = useState<SlipSelection[]>(load);
  const [full, setFull] = useState(false);
  const { data: user, isSuccess } = useMe();
  const previousUserId = useRef<string | null | undefined>(undefined);

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(selections));
    } catch {
      // Stockage indisponible (navigation privée) : le coupon reste en mémoire.
    }
  }, [selections]);

  // Retire régulièrement les matchs qui ont commencé (ils ne sont plus pariables).
  useEffect(() => {
    const timer = window.setInterval(() => {
      setSelections((current) => {
        const kept = upcoming(current);
        return kept.length === current.length ? current : kept;
      });
    }, PRUNE_EVERY_MS);
    return () => window.clearInterval(timer);
  }, []);

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
    } else if (current.some((s) => s.eventId === selection.eventId)) {
      result = 'replaced';
      next = [...current.filter((s) => s.eventId !== selection.eventId), selection];
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
    has: (id) => selections.some((s) => s.selectionId === id),
    toggle,
    remove: (id) => { setFull(false); setSelections((current) => current.filter((s) => s.selectionId !== id)); },
    clear: () => { setFull(false); setSelections([]); },
  }), [selections, full, toggle]);

  return <BetSlipContext.Provider value={value}>{children}</BetSlipContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useBetSlip(): BetSlip {
  const slip = useContext(BetSlipContext);
  if (!slip) throw new Error('useBetSlip doit être utilisé dans <BetSlipProvider>.');
  return slip;
}
