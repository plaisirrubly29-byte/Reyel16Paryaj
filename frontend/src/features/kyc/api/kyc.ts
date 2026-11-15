import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type Money } from '../../../shared/api/client';
import { ME_KEY } from '../../auth/api/auth';

/** Vérification d'identité du joueur (V2-C03) — GET/POST /me/kyc, docs/api/openapi.yaml. */
export type KycStatus = 'none' | 'pending' | 'approved' | 'rejected';
export type KycDocumentType = 'cin' | 'passport' | 'driving_licence' | 'nif';

export type KycState = {
  status: KycStatus;
  verified_at: string | null;
  /** Cumul des dépôts autorisés sans identité vérifiée. */
  deposit_threshold: Money;
  latest_submission: {
    id: string;
    document_type: KycDocumentType;
    status: 'pending' | 'approved' | 'rejected';
    submitted_at: string;
    decided_at: string | null;
    /** Motif d'un refus, pour que le joueur sache quoi corriger. */
    rejection_reason: string | null;
  } | null;
};

export const DOCUMENT_TYPES: { value: KycDocumentType; label: string; hint: string; back: boolean }[] = [
  { value: 'cin', label: "Carte d'identification nationale (CIN)", hint: 'Recto et verso', back: true },
  { value: 'passport', label: 'Passeport', hint: 'Page avec la photo', back: false },
  { value: 'driving_licence', label: 'Permis de conduire', hint: 'Recto et verso', back: true },
  { value: 'nif', label: 'Carte NIF', hint: 'Recto', back: false },
];

export const DOCUMENT_TYPE_LABELS = Object.fromEntries(DOCUMENT_TYPES.map((d) => [d.value, d.label])) as Record<KycDocumentType, string>;

/** Règles de backend/config/kyc.php, vérifiées aussi par le serveur. */
export const MAX_FILE_BYTES = 5_120 * 1024;
export const DOCUMENT_ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';
export const SELFIE_ACCEPT = 'image/jpeg,image/png,image/webp';

export const KYC_KEY = ['kyc'] as const;

export function useKyc() {
  return useQuery({
    queryKey: KYC_KEY,
    queryFn: async ({ signal }) => (await api<Envelope<KycState>>('/me/kyc', { signal })).data,
  });
}

export type KycSubmission = { documentType: KycDocumentType; front: File; back: File | null; selfie: File };

/** Envoi du dossier (multipart) ; le compte passe « en cours de vérification ». */
export function useSubmitKyc() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ documentType, front, back, selfie }: KycSubmission) => {
      const form = new FormData();
      form.append('document_type', documentType);
      form.append('front', front);
      if (back) form.append('back', back);
      form.append('selfie', selfie);

      return (await api<Envelope<KycState>>('/me/kyc', { method: 'POST', body: form })).data;
    },
    onSuccess: (state) => {
      queryClient.setQueryData(KYC_KEY, state);
      queryClient.invalidateQueries({ queryKey: ME_KEY });
    },
  });
}
