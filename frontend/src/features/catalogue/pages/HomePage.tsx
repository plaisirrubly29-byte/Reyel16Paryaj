import { useQuery } from '@tanstack/react-query';
import { api } from '../../../shared/api/client';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Spinner } from '../../../shared/components/Spinner';

type Health = { status: string; version: string; time: string };

export function HomePage() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: ({ signal }) => api<Health>('/health', { signal }),
  });

  return (
    <section className="stack">
      <h1>REYEL 16 PAYAJ</h1>
      <p>Plateforme de paris sportifs — V1 en développement.</p>
      {health.isPending && <Spinner />}
      {health.data && <p className="muted">API : {health.data.status} ({health.data.version})</p>}
      <ErrorAlert error={health.error} onRetry={() => health.refetch()} />
    </section>
  );
}
