import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { api, type Paginated } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../shared/components/Icon';
import { Spinner } from '../../shared/components/Spinner';
import { formatMoney } from '../../shared/format/money';
import { useMe } from '../../features/auth/api/auth';
import {
  useAlerts,
  useDashboard,
  type AdminAlert,
  type AuditEntry,
  type DailyPoint,
  type DashboardSummary,
  type PeriodPreset,
  type PeriodQuery,
} from '../api/dashboard';
import { DailyBarChart, Sparkline } from '../components/Charts';
import { ADMIN_SECTIONS, canAccess } from '../navigation';

const AUDIT_LABELS: Record<string, string> = {
  'roles.updated': 'Rôles modifiés',
  'user.suspended': 'Compte suspendu',
  'user.reactivated': 'Compte réactivé',
  'user.self_excluded': 'Auto-exclusion',
  'user.self_exclusion_ended': 'Fin d’auto-exclusion',
  'responsible_gaming.viewed': 'Jeu responsable consulté',
  'mfa.reset': 'MFA réinitialisé',
  'mfa.failed': 'Échec MFA',
  'mfa.recovery_codes_regenerated': 'Codes de secours régénérés',
  'user.personal_data_viewed': 'Données personnelles consultées',
  'market.suspended': 'Marché suspendu',
  'market.reopened': 'Marché rouvert',
  'finance.correction_requested': 'Correction demandée',
  'finance.correction_approved': 'Correction validée',
  'finance.correction_rejected': 'Correction rejetée',
  'audit.viewed': 'Journal consulté',
  'audit.exported': 'Journal exporté',
};

const SUBJECT_LABELS: Record<string, string> = { user: 'Utilisateur', market: 'Marché', ledger_entry: 'Écriture', bet: 'Ticket', finance_correction: 'Correction' };

const STATUS_LABELS = { pending: 'En cours', won: 'Gagnés', lost: 'Perdus', void: 'Annulés' } as const;

const PRESETS: { value: PeriodPreset; label: string }[] = [
  { value: 'today', label: 'Aujourd’hui' },
  { value: 'yesterday', label: 'Hier' },
  { value: '7d', label: '7 jours' },
  { value: '30d', label: '30 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: 'last_month', label: 'Mois dernier' },
  { value: 'custom', label: 'Personnalisée' },
];

const SEVERITY_LABELS: Record<AdminAlert['severity'], string> = { critical: 'Critique', warning: 'Attention', info: 'Info' };

const DATE = /^\d{4}-\d{2}-\d{2}$/;

const money = (minor: number, currency: string) => formatMoney({ minor, currency });

/** Montant court pour les axes (« 12,5 k »), décimales tirées de la devise comme formatMoney. */
function compactMoney(minor: number, currency: string): string {
  const digits = new Intl.NumberFormat('fr-FR', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
  return new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(minor / 10 ** digits);
}

const count = (n: number) => n.toLocaleString('fr-FR');
const plural = (n: number, word: string) => `${count(n)} ${word}${n > 1 ? 's' : ''}`;
const localDate = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

function relativeTime(iso: string): string {
  const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
  const rtf = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' });
  const units: [Intl.RelativeTimeFormatUnit, number][] = [['day', 86_400], ['hour', 3_600], ['minute', 60]];
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
  }
  return 'à l’instant';
}

function greeting(): string {
  const hour = new Date().getHours();
  return hour < 5 || hour >= 18 ? 'Bonsoir' : 'Bonjour';
}

/** Période lue dans l'URL (lien partageable) ; 7 jours par défaut. */
function usePeriod(): [PeriodQuery, (next: PeriodQuery) => void] {
  const [params, setParams] = useSearchParams();
  const preset = PRESETS.some((p) => p.value === params.get('period')) ? (params.get('period') as PeriodPreset) : '7d';
  const from = DATE.test(params.get('from') ?? '') ? params.get('from')! : undefined;
  const to = DATE.test(params.get('to') ?? '') ? params.get('to')! : undefined;
  const period: PeriodQuery = preset === 'custom' && from && to ? { period: 'custom', from, to } : { period: preset === 'custom' ? '7d' : preset };

  function setPeriod(next: PeriodQuery) {
    const p = new URLSearchParams();
    p.set('period', next.period);
    if (next.period === 'custom' && next.from && next.to) { p.set('from', next.from); p.set('to', next.to); }
    setParams(p, { replace: true });
  }

  return [period, setPeriod];
}

function PeriodPicker({ value, onChange }: { value: PeriodQuery; onChange: (p: PeriodQuery) => void }) {
  const [custom, setCustom] = useState(value.period === 'custom');
  const [from, setFrom] = useState(value.from ?? '');
  const [to, setTo] = useState(value.to ?? '');
  const active = custom ? 'custom' : value.period;

  return (
    <div className="period-picker">
      <div className="segmented" role="radiogroup" aria-label="Période">
        {PRESETS.map((p) => (
          <button
            key={p.value}
            type="button"
            role="radio"
            aria-checked={active === p.value}
            className={active === p.value ? 'is-active' : undefined}
            onClick={() => {
              if (p.value === 'custom') { setCustom(true); return; }
              setCustom(false);
              onChange({ period: p.value });
            }}
          >
            {p.label}
          </button>
        ))}
      </div>
      {custom && (
        <form
          className="period-custom"
          onSubmit={(e) => { e.preventDefault(); if (from && to) onChange({ period: 'custom', from, to }); }}
        >
          <label>Du <input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} required /></label>
          <label>au <input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} required /></label>
          <button type="submit" className="btn btn-secondary btn-sm" disabled={!from || !to}>Appliquer</button>
        </form>
      )}
    </div>
  );
}

type KpiProps = { icon: IconName; label: string; value: string; definition: string; hint?: ReactNode; points?: DailyPoint[]; to?: string; muted?: boolean };

/** Tuile d'indicateur. La définition métier est lisible au survol et par les lecteurs d'écran. */
function Kpi({ icon, label, value, definition, hint, points, to, muted }: KpiProps) {
  const body = (
    <>
      <div className="kpi-head">
        <span className="kpi-icon" aria-hidden="true"><Icon name={icon} size={18} /></span>
        <span className="kpi-label">{label}</span>
        <span className="kpi-info" title={definition}>
          <Icon name="help" size={14} />
          <span className="sr-only">Définition : {definition}</span>
        </span>
      </div>
      <strong className="kpi-value">{value}</strong>
      {hint && <span className="kpi-hint">{hint}</span>}
      {points && points.length > 1 && <Sparkline points={points} />}
    </>
  );
  const className = ['kpi', to && 'kpi-link', muted && 'is-muted'].filter(Boolean).join(' ');

  return to ? <Link to={to} className={className}>{body}</Link> : <article className={className}>{body}</article>;
}

function Panel({ title, subtitle, action, children, className }: { title: string; subtitle?: string; action?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={['dash-panel', className].filter(Boolean).join(' ')}>
      <header className="dash-panel-head">
        <div>
          <h2>{title}</h2>
          {subtitle && <p>{subtitle}</p>}
        </div>
        {action}
      </header>
      {children}
    </section>
  );
}

/** Centre d'alertes : calculées par le serveur sur les données réelles, chacune avec son écran de traitement. */
function AlertCenter() {
  const alerts = useAlerts();

  return (
    <Panel title="Centre d’alertes" subtitle="Points d’attention du moment, selon votre rôle" className="span-3">
      <ErrorAlert error={alerts.error} onRetry={() => alerts.refetch()} />
      {alerts.isPending && <div className="table-state"><Spinner /></div>}
      {alerts.data?.length === 0 && (
        <p className="dash-panel-empty"><Icon name="check" size={16} /> Aucune alerte : tout est en ordre.</p>
      )}
      {!!alerts.data?.length && (
        <ul className="alert-list">
          {alerts.data.map((a) => (
            <li key={a.key} className={`alert-row is-${a.severity}`}>
              <span className="severity-chip">{SEVERITY_LABELS[a.severity]}</span>
              <div className="alert-text">
                <strong>{a.title}</strong>
                <span>{a.category} · {a.detail}</span>
              </div>
              {a.link && <Link to={a.link} className="dash-panel-link">Ouvrir<Icon name="arrowRight" size={14} /></Link>}
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

function StatusBreakdown({ breakdown }: { breakdown: NonNullable<DashboardSummary['betting']>['status_breakdown'] }) {
  const total = breakdown.reduce((s, b) => s + b.count, 0);

  return total === 0 ? (
    <p className="dash-panel-empty">Aucun ticket placé sur la période.</p>
  ) : (
    <ul className="breakdown">
      {breakdown.map((b) => {
        const pct = Math.round((b.count / total) * 100);
        return (
          <li key={b.status}>
            <div className="breakdown-row">
              <span className={`status-dot is-${b.status}`} aria-hidden="true" />
              <span>{STATUS_LABELS[b.status]}</span>
              <strong>{count(b.count)}</strong>
              <span className="breakdown-pct">{pct} %</span>
            </div>
            <div className="breakdown-track" aria-hidden="true"><span style={{ width: `${pct}%` }} /></div>
          </li>
        );
      })}
    </ul>
  );
}

/** Mises par sport : une seule série (barres horizontales d'une même couleur), valeurs écrites en clair. */
function StakesBySport({ rows, currency }: { rows: NonNullable<DashboardSummary['betting']>['stakes_by_sport']; currency: string }) {
  const max = Math.max(...rows.map((r) => r.stakes_minor), 1);

  return rows.length === 0 ? (
    <p className="dash-panel-empty">Aucune mise sur la période.</p>
  ) : (
    <ul className="breakdown">
      {rows.map((r) => (
        <li key={r.sport}>
          <div className="breakdown-row">
            <span>{r.name}</span>
            <strong>{money(r.stakes_minor, currency)}</strong>
            <span className="breakdown-pct">{plural(r.tickets, 'ticket')}</span>
          </div>
          <div className="breakdown-track" aria-hidden="true"><span style={{ width: `${(r.stakes_minor / max) * 100}%` }} /></div>
        </li>
      ))}
    </ul>
  );
}

function Stat({ label, value, icon, tone }: { label: string; value: string; icon: IconName; tone?: 'live' | 'warning' }) {
  return (
    <div className={['mini-stat', tone && `is-${tone}`].filter(Boolean).join(' ')}>
      <span className="mini-stat-icon" aria-hidden="true"><Icon name={icon} size={16} /></span>
      <span className="mini-stat-label">{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

/** Vue d'ensemble : alertes, indicateurs de la période choisie, tendances et activité récente. */
export function AdminHomePage() {
  const { data: user } = useMe();
  const permissions = user?.permissions ?? [];
  const canReadAudit = permissions.includes('audit.view');
  const [period, setPeriod] = usePeriod();
  const dashboard = useDashboard(period);

  const audit = useQuery({
    queryKey: ['admin', 'audit-logs', { per_page: 6 }],
    queryFn: ({ signal }) => api<Paginated<AuditEntry>>('/admin/audit-logs', { signal, query: { per_page: 6 } }),
    enabled: canReadAudit,
  });

  const shortcuts = ADMIN_SECTIONS.filter((s) => s.to !== '/admin' && canAccess(permissions, s.permissions));
  const data = dashboard.data;
  const cur = data?.currency ?? 'HTG';
  const firstName = user?.name.split(' ')[0];
  const today = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const range = data && (data.period.from === data.period.to ? `le ${localDate(data.period.from)}` : `du ${localDate(data.period.from)} au ${localDate(data.period.to)}`);

  return (
    <div className="dash">
      <header className="dash-hero">
        <div>
          <p className="dash-date">{today}</p>
          <h1>Vue d'ensemble</h1>
          <p className="dash-sub">{greeting()}{firstName ? ` ${firstName}` : ''}, voici l’activité de la plateforme.</p>
        </div>
        <div className="dash-hero-actions">
          {data && (
            <span className="dash-updated">
              <span className="dash-live-dot" aria-hidden="true" />
              Actualisé {new Date(data.generated_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
            </span>
          )}
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => { dashboard.refetch(); audit.refetch(); }} disabled={dashboard.isFetching}>
            <Icon name="refresh" size={16} /> Actualiser
          </button>
        </div>
      </header>

      <div className="dash-grid">
        <AlertCenter />
      </div>

      <div className="dash-toolbar">
        <PeriodPicker key={period.period} value={period} onChange={setPeriod} />
        {range && <p className="dash-range">Période {range} <span className="muted">({data.period.timezone})</span></p>}
      </div>

      <ErrorAlert error={dashboard.error} onRetry={() => dashboard.refetch()} />
      {dashboard.isPending && <div className="table-state"><Spinner /></div>}

      {data && (
        <>
          <div className="kpi-grid">
            {data.betting && (
              <>
                <Kpi icon="wallet" label="Mises" value={money(data.betting.stakes_minor, cur)} points={data.betting.stakes_daily_minor}
                  definition="Montant des tickets placés pendant la période (date de placement), réglés ou non."
                  hint={`${plural(data.betting.tickets_placed, 'ticket')} placé${data.betting.tickets_placed > 1 ? 's' : ''}`} to="/admin/tickets" />
                <Kpi icon="trophy" label="Gains payés" value={money(data.betting.payouts_minor, cur)}
                  definition="Montants versés aux tickets gagnants réglés pendant la période (date de règlement)."
                  hint={`${plural(data.betting.tickets_settled, 'ticket')} réglé${data.betting.tickets_settled > 1 ? 's' : ''} · remboursements ${money(data.betting.refunds_minor, cur)}`} />
              </>
            )}
            {data.finance && (
              <Kpi icon="scale" label="Produit brut" value={formatMoney({ minor: data.finance.gross_gaming_revenue_minor, currency: cur }, 'fr-FR', { signed: true })}
                definition="Mises des tickets réglés pendant la période (hors annulés) moins les gains versés. Ce n'est ni un solde ni un bénéfice net."
                hint={`Mises réglées ${money(data.finance.settled_stakes_minor, cur)}`} to="/admin/finance" />
            )}
            {data.betting && (
              <Kpi icon="coupon" label="Tickets en cours" value={count(data.betting.pending_count)} points={data.betting.tickets}
                definition="Tickets non encore réglés, à l'instant présent. L'exposition est le total de leurs gains potentiels : un maximum théorique, pas une perte certaine."
                hint={<>Exposition max. <strong>{money(data.betting.pending_exposure_minor, cur)}</strong></>} to="/admin/risk" />
            )}
            {data.users && (
              <Kpi icon="users" label="Joueurs inscrits" value={count(data.users.total)} points={data.users.registrations}
                definition="Tous les comptes, quel que soit leur statut. Les inscriptions du jour et de la période sont datées à la création du compte."
                hint={`+${count(data.users.new_today)} aujourd’hui · +${count(data.users.new_in_period)} sur la période · ${plural(data.users.suspended, 'suspendu')}`} to="/admin/users" />
            )}
            {data.finance && !data.finance.payments_available && (
              <Kpi icon="wallet" label="Dépôts et retraits" value="Non disponibles" muted
                definition="Aucun prestataire de paiement n'est intégré : la V1 fonctionne en fonds fictifs (tâche P7-01, bloquée par la validation juridique)."
                hint="Fonds fictifs en V1 · paiements bloqués (P7-01)" />
            )}
          </div>

          {data.betting && (
            <div className="dash-grid">
              <Panel title="Mises par jour" subtitle="Tickets placés, tous sports confondus" className="span-2">
                <DailyBarChart points={data.betting.stakes_daily_minor} label="Mises" format={(v) => money(v, cur)} formatAxis={(v) => compactMoney(v, cur)} />
              </Panel>
              <Panel title="Issue des tickets" subtitle="Tickets placés sur la période">
                <StatusBreakdown breakdown={data.betting.status_breakdown} />
              </Panel>
            </div>
          )}

          {(data.betting || data.users) && (
            <div className="dash-grid">
              {data.betting && (
                <Panel title="Mises par sport" subtitle="Les combinés multisports sont comptés à part">
                  <StakesBySport rows={data.betting.stakes_by_sport} currency={cur} />
                </Panel>
              )}
              {data.users && (
                <Panel title="Nouveaux joueurs" subtitle="Inscriptions par jour" className={data.betting ? 'span-2' : 'span-3'}>
                  <DailyBarChart points={data.users.registrations} label="Inscriptions" format={(v) => plural(v, 'inscription')} formatAxis={(v) => count(v)} />
                </Panel>
              )}
            </div>
          )}
        </>
      )}

      {(data?.catalogue || canReadAudit) && (
        <div className="dash-grid">
          {data?.catalogue && (
            <Panel title="Catalogue" subtitle="Matchs et marchés, à l’instant présent">
              <div className="mini-stats">
                <Stat icon="live" label="Matchs en direct" value={count(data.catalogue.events_live)} tone={data.catalogue.events_live > 0 ? 'live' : undefined} />
                <Stat icon="calendar" label="Matchs programmés" value={count(data.catalogue.events_scheduled)} />
                <Stat icon="check" label="Marchés ouverts" value={count(data.catalogue.markets_open)} />
                <Stat icon="alert" label="Marchés suspendus" value={count(data.catalogue.markets_suspended)} tone={data.catalogue.markets_suspended > 0 ? 'warning' : undefined} />
              </div>
            </Panel>
          )}

          {canReadAudit && (
            <Panel title="Activité récente" subtitle="Journal d'audit" className={data?.catalogue ? 'span-2' : 'span-3'} action={<Link to="/admin/audit" className="dash-panel-link">Tout voir<Icon name="arrowRight" size={14} /></Link>}>
              <ErrorAlert error={audit.error} onRetry={() => audit.refetch()} />
              {audit.isPending && <div className="table-state"><Spinner /></div>}
              {audit.data && audit.data.data.length === 0 && <p className="dash-panel-empty">Aucune action journalisée.</p>}
              {audit.data && audit.data.data.length > 0 && (
                <ol className="timeline">
                  {audit.data.data.map((e) => (
                    <li key={e.id}>
                      <span className="timeline-dot" aria-hidden="true"><Icon name="activity" size={14} /></span>
                      <div className="timeline-body">
                        <p><strong>{AUDIT_LABELS[e.action] ?? e.action}</strong>{e.subject && <span className="muted"> · {SUBJECT_LABELS[e.subject.type] ?? e.subject.type} #{e.subject.id}</span>}</p>
                        <p className="timeline-meta">
                          <span>{e.actor?.email ?? 'Système'}</span>
                          {e.reason && <> · <q>{e.reason}</q></>}
                        </p>
                      </div>
                      <time dateTime={e.created_at} title={new Date(e.created_at).toLocaleString('fr-FR')}>{relativeTime(e.created_at)}</time>
                    </li>
                  ))}
                </ol>
              )}
            </Panel>
          )}
        </div>
      )}

      {shortcuts.length > 0 && (
        <section className="stack-sm">
          <h2 className="dash-section-title">Accès rapide</h2>
          <nav className="quick-grid" aria-label="Raccourcis">
            {shortcuts.map((s) => (
              <Link key={s.to} to={s.to} className="quick-card">
                <span className="quick-icon" aria-hidden="true"><Icon name={s.icon} size={20} /></span>
                <span className="quick-text">
                  <strong>{s.label}</strong>
                  <span>{s.description}</span>
                </span>
                <Icon name="chevron" size={16} />
              </Link>
            ))}
          </nav>
        </section>
      )}

      {shortcuts.length === 0 && !canReadAudit && (
        <Alert tone="info">Aucune section n'est associée à votre rôle pour le moment.</Alert>
      )}
    </div>
  );
}
