/**
 * Jeux virtuels (V2-D08, décision D-11) : types d'affichage. Ce n'est PAS encore le contrat de l'API : il sera écrit
 * d'abord dans docs/api/openapi.yaml une fois le prestataire retenu. Ces types décrivent seulement ce que les écrans
 * ont besoin de recevoir du serveur (épreuves, marchés, cotes, heure du serveur, résultats).
 */

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

/** Une épreuve : un match de football virtuel ou une course. */
export type VirtualEvent = {
  id: string;
  game: VirtualGameKey;
  /** « Ligue virtuelle · Journée 12 », « Course 48 ». */
  name: string;
  /** Football : les deux équipes ; courses : vide. */
  home_team: string | null;
  away_team: string | null;
  runners: VirtualRunner[];
  starts_at: string;
  betting_closes_at: string;
  ends_at: string;
  status: VirtualEventStatus;
  markets: VirtualMarket[];
  result: VirtualResult | null;
};

/**
 * Réponse d'une liste ou d'une épreuve. `server_time` sert au compte à rebours : il est calé sur l'horloge du serveur,
 * pas sur celle du téléphone (souvent décalée).
 */
export type VirtualResponse<T> = { data: T; meta: { server_time: string; demo: boolean } };
