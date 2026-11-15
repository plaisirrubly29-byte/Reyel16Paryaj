import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, fetchBlob, type Envelope, type Paginated } from '../../shared/api/client';
import type { KycDocumentType, KycStatus } from '../../features/kyc/api/kyc';

/** Dossier KYC vu par l'équipe (GET /admin/kyc, V2-C03) — docs/api/openapi.yaml. */
export type KycSubmissionStatus = 'pending' | 'approved' | 'rejected';
export type KycFileKind = 'front' | 'back' | 'selfie';

export type AdminKycSubmission = {
  id: string;
  user: {
    id: string;
    name: string;
    first_name: string | null;
    last_name: string | null;
    birth_date: string | null;
    country: string | null;
    kyc_status: KycStatus;
  };
  document_type: KycDocumentType;
  status: KycSubmissionStatus;
  /** Présents sur le détail seulement ; contenu par GET …/files/{id} (consultation auditée). */
  files?: { id: string; kind: KycFileKind; mime_type: string; size_bytes: number }[];
  decided_by: { id: string; name: string } | null;
  decided_at: string | null;
  decision_reason: string | null;
  created_at: string;
};

export const KYC_SUBMISSION_STATUS_LABELS: Record<KycSubmissionStatus, string> = {
  pending: 'À vérifier',
  approved: 'Validé',
  rejected: 'Refusé',
};

export const KYC_FILE_LABELS: Record<KycFileKind, string> = { front: 'Recto', back: 'Verso', selfie: 'Selfie' };

/** Nom déclaré dans le profil (à comparer avec la pièce), sinon nom du compte. */
export function declaredName(user: AdminKycSubmission['user']): string {
  return [user.first_name, user.last_name].filter(Boolean).join(' ') || user.name;
}

const KEY = ['admin', 'kyc'] as const;

export function useKycSubmissions(filters: { status?: KycSubmissionStatus; userId?: string; page: number }) {
  return useQuery({
    queryKey: [...KEY, 'list', filters],
    queryFn: ({ signal }) => api<Paginated<AdminKycSubmission>>('/admin/kyc', {
      signal,
      query: { status: filters.status, user_id: filters.userId, page: filters.page, per_page: 20 },
    }),
    placeholderData: keepPreviousData,
  });
}

export function useKycSubmission(id: string) {
  return useQuery({
    queryKey: [...KEY, 'detail', id],
    queryFn: async ({ signal }) => (await api<Envelope<AdminKycSubmission>>(`/admin/kyc/${encodeURIComponent(id)}`, { signal })).data,
  });
}

/**
 * Pièce ou selfie déchiffré (chaque consultation est auditée côté serveur) : gardé en mémoire le temps de la
 * revue, jamais rechargé en arrière-plan. Sans signal d'annulation : un composant remonté reprend la requête en
 * cours au lieu d'en envoyer une seconde (une seule consultation dans le journal).
 */
export function useKycFile(submissionId: string, fileId: string) {
  return useQuery({
    queryKey: [...KEY, 'file', submissionId, fileId],
    queryFn: () => fetchBlob(`/admin/kyc/${encodeURIComponent(submissionId)}/files/${encodeURIComponent(fileId)}`),
    staleTime: Infinity,
    gcTime: 5 * 60_000,
    refetchOnWindowFocus: false,
  });
}

/** Valider (kyc.approve) ou refuser (kyc.reject) un dossier en attente, avec motif audité. */
export function useDecideKyc(submission: AdminKycSubmission) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ decision, reason, idempotencyKey }: { decision: 'approve' | 'reject'; reason: string; idempotencyKey: string }) =>
      (await api<Envelope<AdminKycSubmission>>(`/admin/kyc/${encodeURIComponent(submission.id)}/${decision}`, {
        method: 'POST',
        body: { reason },
        idempotencyKey,
      })).data,
    onSuccess: (updated) => {
      queryClient.setQueryData([...KEY, 'detail', submission.id], updated);
      queryClient.invalidateQueries({ queryKey: [...KEY, 'list'] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'], exact: false });
    },
  });
}
