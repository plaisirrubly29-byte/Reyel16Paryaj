import type { SlipSelection } from '../slip/BetSlipContext';

/**
 * Ticket de pari SIMULÉ (V1, démonstration) : il est gardé dans le navigateur, par joueur.
 * Aucun débit réel n'est fait ; le vrai ticket viendra de POST /bets (P4-03) quand le backend sera branché.
 */
export type TicketSelection = Pick<SlipSelection, 'match' | 'pick' | 'label' | 'odds' | 'market' | 'startsAt'>;

export type Ticket = {
  /** Betcode : code court que le joueur peut partager. */
  code: string;
  placedAt: string;
  /** Mise et gain potentiel en centimes (HTG). */
  stakeMinor: number;
  potentialMinor: number;
  /** Cote combinée à 2 décimales (« 6.30 »). */
  combinedOdds: string;
  selections: TicketSelection[];
};

/** Sans caractères faciles à confondre (0/O, 1/I). */
const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

export function generateBetCode(): string {
  const bytes = new Uint32Array(8);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (n) => ALPHABET[n % ALPHABET.length]).join('');
}

function storageKey(userId: string): string {
  return `r16p.tickets.${userId}`;
}

function isTicket(value: unknown): value is Ticket {
  const t = value as Ticket;
  return typeof t === 'object' && t !== null
    && typeof t.code === 'string' && typeof t.placedAt === 'string'
    && typeof t.stakeMinor === 'number' && typeof t.potentialMinor === 'number'
    && typeof t.combinedOdds === 'string' && Array.isArray(t.selections);
}

/** Tickets du joueur, les plus récents d'abord. Un stockage illisible donne une liste vide. */
export function loadTickets(userId: string): Ticket[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(storageKey(userId)) ?? '[]');
    return Array.isArray(parsed) ? parsed.filter(isTicket) : [];
  } catch {
    return [];
  }
}

/** Ajoute un ticket au début de la liste du joueur. */
export function addTicket(userId: string, ticket: Ticket): void {
  try {
    localStorage.setItem(storageKey(userId), JSON.stringify([ticket, ...loadTickets(userId)]));
  } catch {
    // Stockage indisponible (navigation privée) : le ticket reste affiché le temps de la session.
  }
}
