import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope } from '../../../shared/api/client';
import { ME_KEY } from './auth';

export type MfaSetup = { secret: string; otpauth_uri: string };

/** Génère un secret TOTP (non actif tant qu'il n'est pas confirmé par un premier code). */
export function useMfaSetup() {
  return useMutation({
    mutationFn: async () => (await api<Envelope<MfaSetup>>('/auth/mfa/setup', { method: 'POST' })).data,
  });
}

/** Active le MFA ; renvoie les codes de secours, affichés une seule fois. */
export function useMfaConfirm() {
  return useMutation({
    mutationFn: async (code: string) =>
      (await api<Envelope<{ recovery_codes: string[] }>>('/auth/mfa/confirm', { method: 'POST', body: { code } })).data.recovery_codes,
  });
}

/** Valide la session avec un code TOTP ou un code de secours. */
export function useMfaVerify() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { code: string } | { recovery_code: string }) =>
      api<null>('/auth/mfa/verify', { method: 'POST', body: input }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ME_KEY }),
  });
}
