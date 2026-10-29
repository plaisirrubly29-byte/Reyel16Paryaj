import { useState } from 'react';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Icon } from '../../shared/components/Icon';
import { Spinner } from '../../shared/components/Spinner';
import { formatMoney } from '../../shared/format/money';
import { useExposure, type ExposureEvent } from '../api/supervision';
import { PageHeader } from '../components/PageHeader';

const MARKET_LABELS: Record<string, string> = { h2h: 'Résultat du match', totals: 'Plus/Moins', spreads: 'Handicap' };

function ExposureRow({ event, currency }: { event: ExposureEvent; currency: string }) {
  const [open, setOpen] = useState(false);
  const money = (minor: number, signed = false) => formatMoney({ minor, currency }, 'fr-FR', { signed });
  const risky = event.worst_case_minor > 0;

  return (
    <li className={['exposure-row', open && 'is-open'].filter(Boolean).join(' ')}>
      <button type="button" className="exposure-summary" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <span className="exposure-name">
          <strong>{event.name}</strong>
          <span className="muted small">{new Date(event.starts_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}</span>
        </span>
        <span className="exposure-figure"><span className="muted small">Simples</span>{event.single_tickets}</span>
        <span className="exposure-figure"><span className="muted small">Mises</span>{money(event.single_stakes_minor)}</span>
        <span className={['exposure-figure', risky ? 'is-risk' : 'is-safe'].join(' ')}>
          <span className="muted small">Pire cas</span>
          {risky ? <Icon name="alert" size={14} /> : <Icon name="check" size={14} />}
          {money(-event.worst_case_minor, true)}
        </span>
        <Icon name="chevron" size={16} />
      </button>

      {open && (
        <div className="exposure-detail">
          {event.markets.map((m) => (
            <table key={`${m.market_key}-${m.line ?? ''}`} className="table exposure-table">
              <caption>
                {MARKET_LABELS[m.market_key] ?? m.market_key}{m.line ? ` ${m.line}` : ''} · mises {money(m.stakes_minor)} · pire cas {money(-m.worst_case_minor, true)}
              </caption>
              <thead><tr><th scope="col">Issue</th><th scope="col" className="num">Tickets</th><th scope="col" className="num">Mises</th><th scope="col" className="num">Gains si elle gagne</th><th scope="col" className="num">Résultat maison</th></tr></thead>
              <tbody>
                {m.selections.map((s) => (
                  <tr key={s.selection_id}>
                    <td>{s.name}</td>
                    <td className="num">{s.tickets}</td>
                    <td className="num">{money(s.stakes_minor)}</td>
                    <td className="num">{money(s.potential_payout_minor)}</td>
                    <td className="num">{money(m.stakes_minor - s.potential_payout_minor, true)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ))}
          {event.markets.length === 0 && <p className="muted small">Aucun pari simple sur ce match.</p>}
          {event.multiple_tickets > 0 && (
            <p className="muted small">
              Aussi présent dans {event.multiple_tickets} combiné(s), gains potentiels {money(event.multiple_potential_payout_minor)} :
              non comptés dans le pire cas, car ils dépendent aussi d’autres matchs.
            </p>
          )}
        </div>
      )}
    </li>
  );
}

/** Exposition de la maison sur les tickets en cours, par match (lecture seule). */
export function RiskPage() {
  const exposure = useExposure();
  const data = exposure.data;

  return (
    <section className="admin-page">
      <PageHeader
        title="Risques & exposition"
        subtitle="Tickets en cours seulement, actualisé chaque minute. Matchs classés du plus risqué au moins risqué."
      />

      <aside className="admin-card method-note">
        <p>
          <strong>Comment lire le pire cas.</strong> Sur un marché, une seule issue peut gagner : le pire cas est le résultat
          de la maison si l’issue la plus coûteuse l’emporte (gains à verser moins mises encaissées). Un montant négatif
          signifie une perte possible, positif un gain quelle que soit l’issue. Pour un match, les marchés sont additionnés :
          c’est un majorant prudent, les marchés d’un même match étant liés.
        </p>
      </aside>

      <ErrorAlert error={exposure.error} onRetry={() => exposure.refetch()} />
      {exposure.isPending && <div className="table-state"><Spinner /></div>}

      {data && data.events.length === 0 && (
        <div className="admin-card"><p className="dash-panel-empty">Aucun ticket en cours : aucune exposition.</p></div>
      )}

      {data && data.events.length > 0 && (
        <section className="admin-card">
          <h2 className="card-title">
            {data.total_events} match{data.total_events > 1 ? 's' : ''} avec des tickets en cours
            {data.total_events > data.events.length && <span className="muted small"> · {data.events.length} plus exposés affichés</span>}
          </h2>
          <ol className="exposure-list">
            {data.events.map((e) => <ExposureRow key={e.event_id} event={e} currency={data.currency} />)}
          </ol>
        </section>
      )}
    </section>
  );
}
