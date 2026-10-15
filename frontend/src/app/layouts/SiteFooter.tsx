import { useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';

type Health = { status: string; version: string };

/** Pied de page public : mentions et état de l'API (discret, n'empêche jamais l'affichage). */
export function SiteFooter() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: ({ signal }) => api<Health>('/health', { signal }),
    retry: false,
    staleTime: 60_000,
  });

  const status = health.isPending ? 'vérification…' : health.data?.status === 'ok' ? 'en ligne' : 'indisponible';

  return (
    <footer className="site-footer">
      <p><strong>REYEL 16 PAYAJ</strong> · Version de démonstration · Fonds fictifs uniquement</p>
      <p className="muted small">18+ · Jouez de manière responsable.</p>
      <p className="muted small">
        <span className={`dot ${health.data?.status === 'ok' ? 'dot-ok' : health.isPending ? '' : 'dot-down'}`} aria-hidden="true" />
        Service : {status}
      </p>
    </footer>
  );
}
