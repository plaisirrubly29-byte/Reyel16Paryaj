import type { ReactNode } from 'react';
import { DataTable } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Spinner } from '../../shared/components/Spinner';
import { useSystemStatus, type SystemStatus } from '../api/supervision';
import { PageHeader } from '../components/PageHeader';

const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : 'Jamais');
const hoursAgo = (iso: string | null) => (iso ? (Date.now() - new Date(iso).getTime()) / 3_600_000 : Infinity);

type Health = 'ok' | 'warning' | 'down' | 'off';
const HEALTH_LABELS: Record<Health, string> = { ok: 'Opérationnel', warning: 'À surveiller', down: 'En panne', off: 'Non disponible' };

function HealthBadge({ health, label }: { health: Health; label?: string }) {
  return (
    <span className={`health-badge is-${health}`}>
      <span className="service-dot" aria-hidden="true" />
      {label ?? HEALTH_LABELS[health]}
    </span>
  );
}

function ServiceCard({ title, health, healthLabel, children }: { title: string; health: Health; healthLabel?: string; children: ReactNode }) {
  return (
    <section className="admin-card service-card">
      <header className="service-card-head">
        <h2 className="card-title">{title}</h2>
        <HealthBadge health={health} label={healthLabel} />
      </header>
      {children}
    </section>
  );
}

function providerHealth(p: SystemStatus['sports_provider']): Health {
  if (p.provider === 'fake') return 'off';
  if (p.credits.remaining <= p.credits.reserve) return 'down';
  if (hoursAgo(p.last_call_at) > 24 || p.credits.remaining <= p.credits.reserve * 2) return 'warning';
  return 'ok';
}

/**
 * Supervision technique en lecture seule (permission settings.view) : aucun secret,
 * aucune commande à distance — on constate, on n'exécute pas.
 */
export function SystemPage() {
  const status = useSystemStatus();
  const s = status.data;

  return (
    <section className="admin-page">
      <PageHeader
        title="Supervision technique"
        subtitle={s ? `État relevé à ${new Date(s.generated_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}, actualisé chaque minute.` : 'Fournisseurs, tâches automatiques et traitements en échec.'}
      />

      <ErrorAlert error={status.error} onRetry={() => status.refetch()} />
      {status.isPending && <div className="table-state"><Spinner /></div>}

      {s && (
        <>
          <div className="service-grid">
            <ServiceCard title="Planificateur" health={s.scheduler.running ? 'ok' : 'down'} healthLabel={s.scheduler.running ? 'Actif' : 'Arrêté'}>
              <p className="muted small">
                Lance la synchronisation des matchs, l’import des résultats et le règlement des tickets.
                {!s.scheduler.running && ' Sans lui, rien ne se met à jour : vérifiez la tâche cron ou schedule:work sur le serveur.'}
              </p>
              <dl className="details"><dt>Dernier signal</dt><dd>{dateTime(s.scheduler.last_heartbeat)}</dd></dl>
            </ServiceCard>

            <ServiceCard title="Données sportives" health={providerHealth(s.sports_provider)}>
              <dl className="details">
                <dt>Fournisseur</dt><dd>{s.sports_provider.provider === 'theodds' ? 'The Odds API' : s.sports_provider.provider === 'fake' ? 'Données de test (aucun fournisseur réel)' : s.sports_provider.provider}</dd>
                <dt>Crédits restants</dt>
                <dd>
                  <strong>{s.sports_provider.credits.remaining}</strong> / {s.sports_provider.credits.monthly}
                  <span className="muted small"> · réserve {s.sports_provider.credits.reserve} · remise à zéro le {new Date(`${s.sports_provider.credits.next_reset}T12:00:00`).toLocaleDateString('fr-FR')}</span>
                </dd>
                <dt>Dernier appel</dt><dd>{dateTime(s.sports_provider.last_call_at)}</dd>
                <dt>Dernière mise à jour d’un match</dt><dd>{dateTime(s.sports_provider.last_event_sync_at)}</dd>
                <dt>Fréquence</dt><dd><code>{s.sports_provider.sync_cron}</code></dd>
              </dl>
            </ServiceCard>

            <ServiceCard title="Tâches en file d’attente" health={s.queue.failed_24h > 0 ? 'down' : 'ok'} healthLabel={s.queue.failed_24h > 0 ? `${s.queue.failed_24h} échec(s) sur 24 h` : 'Aucun échec'}>
              <p className="muted small">Règlements et traitements différés (connexion <code>{s.queue.connection}</code>).</p>
            </ServiceCard>

            <ServiceCard title="Paiements" health="off" healthLabel="Non intégrés">
              <p className="muted small">Aucun prestataire de paiement : la V1 fonctionne en fonds fictifs. Intégration bloquée par la validation juridique (P7-01).</p>
            </ServiceCard>

            <ServiceCard title="Sauvegardes" health="off" healthLabel="Non suivies ici">
              <p className="muted small">Les sauvegardes sont faites hors de l’application (procédure P8-04) : aucun résultat vérifié n’est remonté, donc rien n’est présenté comme réussi.</p>
            </ServiceCard>
          </div>

          <section className="admin-card">
            <h2 className="card-title">Synchronisation par compétition</h2>
            <DataTable
              caption="Dernière synchronisation par compétition"
              rows={s.sports_provider.competitions}
              rowKey={(c) => c.key}
              empty="Aucune compétition configurée."
              columns={[
                { key: 'name', header: 'Compétition', render: (c) => c.name },
                { key: 'key', header: 'Clé fournisseur', render: (c) => <code className="small">{c.key}</code> },
                { key: 'sync', header: 'Dernière synchronisation', render: (c) => dateTime(c.last_synced_at) },
                {
                  key: 'state',
                  header: 'Fraîcheur',
                  render: (c) => <HealthBadge health={hoursAgo(c.last_synced_at) > 24 ? 'warning' : 'ok'} label={hoursAgo(c.last_synced_at) > 24 ? 'Ancienne' : 'Récente'} />,
                },
              ]}
            />
          </section>

          <div className="detail-grid detail-grid-even">
            <section className="admin-card">
              <h2 className="card-title">Derniers appels en échec au fournisseur</h2>
              <DataTable
                caption="Appels en échec au fournisseur"
                rows={s.sports_provider.recent_failures}
                rowKey={(f) => `${f.called_at}-${f.endpoint}`}
                empty="Aucun appel en échec."
                columns={[
                  { key: 'date', header: 'Date', render: (f) => dateTime(f.called_at) },
                  { key: 'endpoint', header: 'Appel', render: (f) => <code className="small">{f.endpoint}</code> },
                  { key: 'status', header: 'Code', render: (f) => f.status },
                ]}
              />
            </section>
            <section className="admin-card">
              <h2 className="card-title">Dernières tâches en échec</h2>
              <DataTable
                caption="Tâches en échec"
                rows={s.queue.recent_failures}
                rowKey={(j) => j.id}
                empty="Aucune tâche en échec."
                columns={[
                  { key: 'date', header: 'Date', render: (j) => dateTime(j.failed_at) },
                  { key: 'job', header: 'Tâche', render: (j) => <code className="small">{j.job.split('\\').pop()}</code> },
                  { key: 'queue', header: 'File', render: (j) => j.queue },
                ]}
              />
            </section>
          </div>
        </>
      )}
    </section>
  );
}
