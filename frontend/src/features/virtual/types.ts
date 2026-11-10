/**
 * Jeux virtuels (V2-D08, décision D-11) : ce que les écrans reçoivent du serveur. Le contrat définitif sera écrit dans
 * docs/api/openapi.yaml avec le prestataire retenu ; ces types en sont la proposition côté frontend.
 */
import type { Money } from '../../shared/api/client';

export type VirtualGameKey = 'football' | 'courses';

/** Un jeu virtuel du prestataire (football, courses). */
export type VirtualGame = {
  key: VirtualGameKey;
  name: string;
  description: string;
};

/** Cote d'une sélection, en chaîne comme pour les paris sportifs (« 2.10 ») : affichée telle que reçue. */
export type VirtualSelection = { id: string; label: string; name: string; odds: string };

export type VirtualMarket = { id: string; key: string; name: string; selections: VirtualSelection[] };

/** Partant d'une course : numéro de dossard et nom. */
export type VirtualRunner = { number: number; name: string };

/**
 * scheduled : mises ouvertes jusqu'à `betting_closes_at` ; running : épreuve en cours (mises fermées) ;
 * finished : résultat publié.
 */
export type VirtualEventStatus = 'scheduled' | 'running' | 'finished';

export type VirtualResult =
  | { kind: 'football'; home: number; away: number }
  | { kind: 'courses'; podium: VirtualRunner[] };

/** État en direct pendant l'épreuve : minute et score du match, ou avancement de la course (0 à 100). */
export type VirtualLive =
  | { kind: 'football'; minute: number; home: number; away: number }
  | { kind: 'courses'; progress: number };

/** Une épreuve : un match de football virtuel ou une course. */
export type VirtualEvent = {
  id: string;
  game: VirtualGameKey;
  /** « Ligue virtuelle · Journée 12 », « Course 48 ». */
  name: string;
  /** Football : les deux équipes ; courses : null. */
  home_team: string | null;
  away_team: string | null;
  runners: VirtualRunner[];
  starts_at: string;
  betting_closes_at: string;
  ends_at: string;
  status: VirtualEventStatus;
  markets: VirtualMarket[];
  live: VirtualLive | null;
  result: VirtualResult | null;
};

/**
 * Réponse d'une liste ou d'une épreuve. `server_time` sert au compte à rebours : il est calé sur l'horloge du serveur,
 * pas sur celle du téléphone (souvent décalée).
 */
export type VirtualResponse<T> = { data: T; meta: { server_time: string } };

export type VirtualBetStatus = 'pending' | 'won' | 'lost';

/** Pari simple sur une épreuve virtuelle, tel que le serveur l'enregistre puis le règle. */
export type VirtualBet = {
  id: string;
  reference: string;
  event: { id: string; game: VirtualGameKey; name: string; title: string; starts_at: string };
  market: { key: string; name: string };
  selection: { id: string; label: string; name: string };
  odds: string;
  stake: Money;
  potential_payout: Money;
  status: VirtualBetStatus;
  /** Gain versé une fois réglé (0 si perdu) ; null tant que l'épreuve n'est pas terminée. */
  payout: Money | null;
  placed_at: string;
  settled_at: string | null;
};
