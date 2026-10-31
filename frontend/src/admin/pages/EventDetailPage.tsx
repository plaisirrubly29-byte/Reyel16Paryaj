import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Alert } from '../../shared/components/Alert';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Spinner } from '../../shared/components/Spinner';
import { useMe } from '../../features/auth/api/auth';
import { since, useAdminEvent, useChangeMarketStatus, type AdminEvent, type AdminMarket } from '../api/events';
import { CORRECTION_STATUS_LABELS, outcomeLabel, sourceLabel, useRequestCorrection, useResultHistory, type ResultStatus } from '../api/results';
import { EventStatusBadge, MarketStatusBadge } from '../components/MarketStatusBadge';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';
import { dateTime } from '../api/events';

/** Fiche d'un match (events.view) : origine et fraîcheur, marchés (suspendre / rouvrir), résultat et corrections. */
export function EventDetailPage() {
  const { id = '' } = useParams();
  const event = useAdminEvent(id);
  const e = event.data;

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/events', label: 'Événements & marchés' }}
        title={e ? `${e.home_team} – ${e.away_team}` : 'Match'}
        subtitle={e ? `${e.sport.name} · ${e.competition.name} · ${dateTime(e.starts_at)}` : undefined}
        actions={e && <EventStatusBadge status={e.status} />}
      />

      {event.isPending && <Spinner label="Chargement du match" />}
      <ErrorAlert error={event.error} onRetry={() => event.refetch()} />

      {e && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Score</dt><dd>{e.score ? `${e.score.home} – ${e.score.away}` : '—'}</dd></div>
            <div className="summary-item"><dt>Origine</dt><dd><span className="mono">{e.origin.provider}</span> · <span className="mono">{e.origin.external_id}</span></dd></div>
            <div className="summary-item"><dt>Dernière synchronisation</dt><dd title={e.last_synced_at ? dateTime(e.last_synced_at) : undefined}>{since(e.last_synced_at)}</dd></div>
            <div className="summary-item"><dt>Résultat reçu</dt><dd>{e.result_received_at ? dateTime(e.result_received_at) : '—'}</dd></div>
          </dl>

          <section className="admin-card stack">
            <h2 className="card-title">Marchés</h2>
            {e.markets?.length === 0 && <p className="muted">Aucun marché pour ce match.</p>}
            <ul className="market-list">
              {e.markets?.map((m) => <MarketItem key={m.id} market={m} />)}
            </ul>
          </section>

          <ResultSection event={e} />
        </>
      )}
    </section>
  );
}

/** Un marché : cotes, origine, fraîcheur, et suspension / réouverture avec motif (markets.suspend). */
function MarketItem({ market }: { market: AdminMarket }) {
  const { data: me } = useMe();
  const change = useChangeMarketStatus(market);
  const canChange = me?.permissions.includes('markets.suspend') && (market.status === 'open' || market.status === 'suspended');
  const target = market.status === 'open' ? 'suspended' : 'open';
  const age = market.freshness.age_seconds === null ? null : Math.round(market.freshness.age_seconds / 60);

  return (
    <li className="market-item">
      <div className="market-head">
        <strong>{market.name}{market.line ? ` ${market.line}` : ''}</strong>
        <MarketStatusBadge status={market.status} />
        {market.bettable ? <span className="badge status-active">Pariable</span> : <span className="badge">Non pariable</span>}
      </div>

      {market.suspension && (
        <p className="small">
          <strong>{market.suspension.automatic ? 'Suspension automatique' : 'Suspendu par l\'administration'}</strong> : {market.suspension.reason}
        </p>
      )}

      <p className="selection-odds small">
        {market.selections?.map((s) => <span key={s.id} className="odds-pill" title={s.name}>{s.label} {s.odds}</span>)}
      </p>

      <p className="muted small">
        Cote <strong className={market.freshness.stale ? 'text-warning' : undefined}>{market.freshness.stale ? 'périmée' : 'fraîche'}</strong>
        {age !== null && <> (mise à jour il y a {age} min, seuil {market.freshness.max_age_minutes} min)</>}
        {' '}· version {market.odds_version} · source <span className="mono">{market.origin.bookmaker ?? market.origin.provider ?? '—'}</span>
      </p>

      {canChange && (
        <details className="market-action">
          <summary>{target === 'suspended' ? 'Suspendre ce marché' : 'Rouvrir ce marché'}</summary>
          <ReasonForm
            id={`market-${market.id}`}
            title={target === 'suspended' ? 'Suspendre le marché' : 'Rouvrir le marché'}
            description={target === 'suspended'
              ? 'Le marché disparaît du site et n\'est plus pariable. La synchronisation ne le rouvrira pas.'
              : 'Le marché redevient visible ; il n\'est pariable que si sa cote est assez récente.'}
            submitLabel={target === 'suspended' ? 'Suspendre' : 'Rouvrir'}
            variant={target === 'suspended' ? 'danger' : 'primary'}
            successMessage="Statut du marché mis à jour."
            onSubmit={(reason, idempotencyKey) => change.mutateAsync({ status: target, reason, idempotencyKey })}
          />
        </details>
      )}
    </li>
  );
}

/** Résultat du match : versions successives, demandes de correction, et nouvelle demande (results.enter). */
function ResultSection({ event }: { event: AdminEvent }) {
  const { data: me } = useMe();
  const history = useResultHistory(event.id);
  const h = history.data;
  const pending = h?.corrections.find((c) => c.status === 'pending');

  return (
    <section className="admin-card stack">
      <h2 className="card-title">Résultat et corrections</h2>
      <ErrorAlert error={history.error} onRetry={() => history.refetch()} />

      {h && h.results.length === 0 && <p className="muted">Pas encore de résultat pour ce match.</p>}
      {h && h.results.length > 0 && (
        <ol className="result-history">
          {h.results.map((r) => (
            <li key={r.id}>
              <strong>Version {r.version} : {outcomeLabel(r)}</strong>
              <span className="muted small">
                {' '}— {sourceLabel(r.source)}{r.entered_by ? `, validée par ${r.entered_by.name}` : ''}, {dateTime(r.created_at)}
              </span>
              {r.correction_reason && <div className="small">Motif : {r.correction_reason}</div>}
            </li>
          ))}
        </ol>
      )}

      {h && h.corrections.length > 0 && (
        <ul className="stack-sm">
          {h.corrections.map((c) => (
            <li key={c.id} className="small">
              <Link to={`/admin/settlements/${c.id}`}>Demande n° {c.id}</Link> : {outcomeLabel(c.current)} → <strong>{outcomeLabel(c.proposed)}</strong>
              {' '}— {CORRECTION_STATUS_LABELS[c.status]}
            </li>
          ))}
        </ul>
      )}

      {pending && <Alert tone="info">Une correction attend la validation d'un second administrateur : <Link to={`/admin/settlements/${pending.id}`}>voir la demande</Link>.</Alert>}

      {h && h.results.length > 0 && !pending && me?.permissions.includes('results.enter') && <CorrectionRequestForm eventId={event.id} />}
    </section>
  );
}

function CorrectionRequestForm({ eventId }: { eventId: string }) {
  const request = useRequestCorrection(eventId);
  const [status, setStatus] = useState<ResultStatus>('final');
  const [home, setHome] = useState('');
  const [away, setAway] = useState('');
  const scoreMissing = status === 'final' && (home.trim() === '' || away.trim() === '');

  return (
    <details className="market-action">
      <summary>Demander une correction du résultat</summary>
      <ReasonForm
        id="correction"
        title="Demande de correction"
        description="Rien ne change tant qu'un autre administrateur n'a pas validé la demande. À la validation, les tickets déjà réglés sont réévalués (gains repris ou versés)."
        submitLabel="Envoyer la demande"
        incomplete={scoreMissing}
        successMessage="Demande envoyée : elle attend la validation d'un second administrateur."
        onSubmit={(reason, idempotencyKey) => request.mutateAsync({
          status,
          home_score: status === 'final' ? home.trim() : null,
          away_score: status === 'final' ? away.trim() : null,
          reason,
          idempotencyKey,
        })}
      >
        <div className="field">
          <label htmlFor="correction-status">Issue</label>
          <select id="correction-status" value={status} onChange={(ev) => setStatus(ev.target.value as ResultStatus)}>
            <option value="final">Score final</option>
            <option value="cancelled">Match annulé (mises remboursées)</option>
          </select>
        </div>
        {status === 'final' && (
          <div className="score-inputs">
            <div className="field">
              <label htmlFor="correction-home">Domicile</label>
              <input id="correction-home" inputMode="numeric" maxLength={3} value={home} onChange={(ev) => setHome(ev.target.value)} />
            </div>
            <div className="field">
              <label htmlFor="correction-away">Extérieur</label>
              <input id="correction-away" inputMode="numeric" maxLength={3} value={away} onChange={(ev) => setAway(ev.target.value)} />
            </div>
          </div>
        )}
      </ReasonForm>
    </details>
  );
}
