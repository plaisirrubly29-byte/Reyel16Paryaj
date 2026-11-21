import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Paginated } from '../../shared/api/client';
import type { AdminEvent } from './events';
import type { TicketStatus } from './tickets';

export type ResultStatus = 'final' | 'cancelled';
export type CorrectionStatus = 'pending' | 'approved' | 'rejected';

export const CORRECTION_STATUS_LABELS: Record<CorrectionStatus, string> = {
  pending: 'À valider',
  approved: 'Validée',
  rejected: 'Refusée',
};

export type Outcome = { status: ResultStatus; score: string | null };

/** Tickets dont l'issue change (aperçu avant validation, ou réévaluation appliquée). */
export type CorrectionImpact = {
  tickets_count: number;
  /** Somme des variations de gain : négatif = argent repris aux joueurs. */
  ledger_delta_minor: number;
  /** Premier résultat validé (V2-D04) : tickets en attente, réglés juste après. */
  pending_tickets_count?: number;
  tickets: {
    ticket_id: string;
    reference: string;
    user_id: string;
    from: { status: TicketStatus; payout_minor: number };
    to: { status: TicketStatus; payout_minor: number };
    ledger_delta_minor: number;
  }[];
};

/** Demande de correction de résultat à double contrôle (P5-06). */
export type ResultCorrection = {
  id: string;
  event: { id: string; name: string };
  status: CorrectionStatus;
  /** initial : premier résultat saisi à la main, le fournisseur n'en ayant publié aucun (V2-D04). */
  kind: 'initial' | 'correction';
  /** null pour un premier résultat. */
  current: (Outcome & { version: number }) | null;
  proposed: Outcome;
  /** « manual » : administrateur ; sinon nom du fournisseur. */
  source: string;
  reason: string;
  /** null : correction envoyée par le fournisseur après règlement. */
  requested_by: { id: string; name: string } | null;
  decided_by: { id: string; name: string } | null;
  decision_reason: string | null;
  decided_at: string | null;
  applied_result_id: string | null;
  impact: CorrectionImpact | null;
  /** Demande en attente : ce que la validation changerait maintenant (détail seulement). */
  preview?: CorrectionImpact;
  created_at: string;
};

export type ResultVersion = Outcome & {
  id: string;
  version: number;
  source: string;
  external_id: string | null;
  entered_by: { id: string; name: string } | null;
  correction_reason: string | null;
  created_at: string;
};

export type ResultHistory = { event_id: string; results: ResultVersion[]; corrections: ResultCorrection[] };

export const outcomeLabel = (o: Outcome) => (o.status === 'cancelled' ? 'Match annulé' : (o.score ?? '—'));

/** Résultat en vigueur lors de la demande ; aucun pour un premier résultat saisi à la main (V2-D04). */
export const currentLabel = (c: ResultCorrection) => (c.current ? outcomeLabel(c.current) : 'Aucun résultat');

export const sourceLabel = (source: string) => (source === 'manual' ? 'Administration' : `Fournisseur (${source})`);

/** Premier résultat saisi à la main (V2-D04), et non correction d'un résultat déjà en vigueur. */
export const isFirstResult = (c: Pick<ResultCorrection, 'kind'>) => c.kind === 'initial';

/**
 * Délai après le coup d'envoi avant qu'un premier résultat puisse être saisi à la main. Indicatif : sert à
 * signaler les matchs à traiter ; le serveur décide (409 result_too_early, details.available_at).
 */
export const MANUAL_RESULT_DELAY_HOURS = 3;

/**
 * Match commencé depuis assez longtemps, sans résultat du fournisseur : à saisir à la main (V2-D04).
 * Un match annulé ou reporté n'attend pas de score : il suit ses propres règles (D-05).
 */
export function awaitsManualResult(event: Pick<AdminEvent, 'starts_at' | 'status' | 'result_received_at'>, now = Date.now()): boolean {
  return event.result_received_at === null
    && event.status !== 'cancelled' && event.status !== 'postponed'
    && now >= new Date(event.starts_at).getTime() + MANUAL_RESULT_DELAY_HOURS * 3_600_000;
}

export function useResultCorrections(filters: { status?: CorrectionStatus; page: number }) {
  return useQuery({
    queryKey: ['admin', 'result-corrections', filters],
    queryFn: ({ signal }) => api<Paginated<ResultCorrection>>('/admin/result-corrections', { signal, query: { ...filters, per_page: 20 } }),
    placeholderData: keepPreviousData,
  });
}

export function useResultCorrection(id: string) {
  return useQuery({
    queryKey: ['admin', 'result-corrections', id],
    queryFn: async ({ signal }) => (await api<Envelope<ResultCorrection>>(`/admin/result-corrections/${encodeURIComponent(id)}`, { signal })).data,
  });
}

/** Historique complet du résultat d'un match (versions et demandes de correction). */
export function useResultHistory(eventId: string, enabled = true) {
  return useQuery({
    queryKey: ['admin', 'events', eventId, 'results'],
    queryFn: async ({ signal }) => (await api<Envelope<ResultHistory>>(`/admin/events/${encodeURIComponent(eventId)}/results`, { signal })).data,
    enabled,
  });
}

export type CorrectionRequest = {
  status: ResultStatus;
  home_score: string | null;
  away_score: string | null;
  /** D-03 : score du temps réglementaire confirmé (exigé pour le football). */
  regulation_time: boolean;
  reason: string;
  idempotencyKey: string;
};

/** Demander une correction (permission results.enter) : rien ne change avant la validation d'un autre administrateur. */
export function useRequestCorrection(eventId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ idempotencyKey, ...body }: CorrectionRequest) =>
      (await api<Envelope<ResultCorrection>>(`/admin/events/${encodeURIComponent(eventId)}/result-corrections`, { method: 'POST', body, idempotencyKey })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'events', eventId, 'results'] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'result-corrections'], exact: false });
    },
  });
}

export type CorrectionDecision = { decision: 'approve' | 'reject'; reason: string; idempotencyKey: string };

/** Valider (results.approve, jamais le demandeur) ou refuser / retirer une demande, avec motif. */
export function useDecideCorrection(correction: ResultCorrection) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ decision, reason, idempotencyKey }: CorrectionDecision) =>
      (await api<Envelope<ResultCorrection>>(`/admin/result-corrections/${encodeURIComponent(correction.id)}/${decision}`, {
        method: 'POST',
        body: { reason },
        idempotencyKey,
      })).data,
    onSuccess: (updated) => {
      queryClient.setQueryData(['admin', 'result-corrections', correction.id], updated);
      // Les listes changent ; la demande affichée vient d'être remplacée par la réponse (pas de rechargement).
      queryClient.invalidateQueries({ queryKey: ['admin', 'result-corrections'], predicate: (q) => q.queryKey[2] !== correction.id });
      queryClient.invalidateQueries({ queryKey: ['admin', 'events'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['admin', 'tickets'], exact: false });
    },
  });
}
