import { useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';

type Health = { status: string; version: string; time: string };

export function HomePage() {
  const [health, setHealth] = useState<Health | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    api<Health>('/health', { signal: controller.signal })
      .then(setHealth)
      .catch((e) => { if (e.name !== 'AbortError') setError(true); });
    return () => controller.abort();
  }, []);

  return (
    <section>
      <h1>REYEL 16 PAYAJ</h1>
      <p>Plateforme de paris sportifs — V1 en développement.</p>
      <p className="status">
        API : {health ? `${health.status} (${health.version})` : error ? 'injoignable' : '…'}
      </p>
    </section>
  );
}
