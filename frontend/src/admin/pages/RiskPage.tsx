import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Icon } from '../../shared/components/Icon';
import { Spinner } from '../../shared/components/Spinner';
import { formatMoney } from '../../shared/format/money';
import { useMe } from '../../features/auth/api/auth';
import { moneySetting, usePlatformSettings } from '../api/settings';
import { useExposure, type ExposureEvent } from '../api/supervision';
import { PageHeader } from '../components/PageHeader';

const MARKET_LABELS: Record<string, string> = { h2h: 'Résultat du match', totals: 'Plus/Moins', spreads: 'Handicap' };

/** Plafonds d'engagement (V2-D05), en unité mineure ; absents sans la permission settings.view. */
type Limits = { event?: number; market?: number };

/**
 * Part du plafond consommée par un pire cas (perte possible). 0 quand la maison gagne quelle que soit l'issue ;
 * null sans plafond connu.
 */
function usage(worstCaseMinor: number, limitMinor: number | undefined): number | null {
  if (limitMinor === undefined || limitMinor <= 0) return null;
  return Math.max(0, worstCaseMinor) / limitMinor;
}

const percent = (ratio: number) => `${Math.floor(ratio * 100)} %`;

/**
 * « 62 % du plafond », ou « Plafond atteint » : au plafond, un pari qui augmente encore le pire cas est refusé.
 * Rien sous 1 % : la plupart des matchs sont très loin du plafond, la mention n'y apprendrait rien.
 */
function LimitUsage({ ratio, scope }: { ratio: number; scope: 'match' | 'marché' }) {
  const reached = ratio >= 1;
  if (ratio < 0.01) return null;

  return (
    <span className={['limit-usage', reached ? 'is-reached' : ratio >= 0.5 && 'is-near'].filter(Boolean).join(' ')}>
      <meter min={0} max={1} value={Math.min(ratio, 1)} aria-label={`Part du plafond du ${scope} atteinte`} />
      {reached ? 'Plafond atteint' : `${percent(ratio)} du plafond`}
    </span>
  );
}

function ExposureRow({ event, currency, limits }: { event: ExposureEvent; currency: string; limits: Limits }) {
  const [open, setOpen] = useState(false);
  const money = (minor: number, signed = false) => formatMoney({ minor, currency }, 'fr-FR', { signed });
  const risky = event.worst_case_minor > 0;
  const eventUsage = usage(event.worst_case_minor, limits.event);

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
          {eventUsage !== null && risky && <LimitUsage ratio={eventUsage} scope="match" />}
        </span>
        <Icon name="chevron" size={16} />
      </button>

      {open && (
        <div className="exposure-detail">
          {event.markets.map((m) => {
            const marketUsage = usage(m.worst_case_minor, limits.market);

            return (
              <table key={`${m.market_key}-${m.line ?? ''}`} className="table exposure-table">
                <caption>
                  {MARKET_LABELS[m.market_key] ?? m.market_key}{m.line ? ` ${m.line}` : ''} · mises {money(m.stakes_minor)} · pire cas {money(-m.worst_case_minor, true)}
                  {marketUsage !== null && m.worst_case_minor > 0 && <> · <LimitUsage ratio={marketUsage} scope="marché" /></>}
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
            );
          })}
          {event.markets.length === 0 && <p className="muted small">Aucun pari simple sur ce match.</p>}
          {event.multiple_tickets > 0 && (
            <p className="muted small">
              Aussi présent dans {event.multiple_tickets} combiné(s), gains potentiels {money(event.multiple_potential_payout_minor)} :
              non comptés dans le pire cas affiché, car ils dépendent aussi d’autres matchs.
              {limits.event !== undefined && ' Pour le plafond, un combiné compte sur ce match dès que toutes ses autres sélections sont gagnées ou annulées.'}
            </p>
          )}
        </div>
      )}
    </li>
  );
}

/**
 * Exposition de la maison sur les tickets en cours, par match, et plafonds d'engagement (V2-D05).
 * Les plafonds se lisent dans les paramètres (settings.view) : sans cette permission, l'exposition s'affiche seule.
 */
export function RiskPage() {
  const { data: me } = useMe();
  const canSeeLimits = !!me?.permissions.includes('settings.view');
  const exposure = useExposure();
  const settings = usePlatformSettings(canSeeLimits);
  const data = exposure.data;

  const eventLimit = moneySetting(settings.data, 'betting.max_event_liability_minor');
  const marketLimit = moneySetting(settings.data, 'betting.max_market_liability_minor');
  const limits: Limits = { event: eventLimit?.minor, market: marketLimit?.minor };
  const atLimit = data?.events.filter((e) => (usage(e.worst_case_minor, limits.event) ?? 0) >= 1
    || e.markets.some((m) => (usage(m.worst_case_minor, limits.market) ?? 0) >= 1)).length ?? 0;

  return (
    <section className="admin-page">
      <PageHeader
        title="Risques & exposition"
        subtitle="Tickets en cours seulement, actualisé chaque minute. Matchs classés du plus risqué au moins risqué."
      />

      {eventLimit && marketLimit && (
        <section className="admin-card stack" aria-labelledby="risk-limits-title">
          <h2 id="risk-limits-title" className="card-title">Plafonds d'engagement</h2>
          <dl className="summary-grid risk-limits">
            <div className="summary-item"><dt>Perte maximale sur un match</dt><dd>{formatMoney(eventLimit)}</dd></div>
            <div className="summary-item"><dt>Perte maximale sur un marché</dt><dd>{formatMoney(marketLimit)}</dd></div>
            <div className="summary-item">
              <dt>Matchs au plafond</dt>
              <dd className={atLimit > 0 ? 'text-warning' : undefined}>{data ? atLimit : '—'}</dd>
            </div>
          </dl>
          <p className="muted small">
            Quand le pire cas d'un match ou d'un marché atteint son plafond, un nouveau pari qui l'augmenterait encore est refusé
            au joueur, sans lui montrer ni le plafond ni l'exposition. Un pari qui réduit le pire cas reste accepté.
            {me?.permissions.includes('settings.update') && <> Les plafonds se modifient dans <Link to="/admin/settings">Paramètres</Link>, groupe « Risque ».</>}
          </p>
        </section>
      )}
      {canSeeLimits && <ErrorAlert error={settings.error} onRetry={() => settings.refetch()} />}

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
            {data.events.map((e) => <ExposureRow key={e.event_id} event={e} currency={data.currency} limits={limits} />)}
          </ol>
        </section>
      )}
    </section>
  );
}
