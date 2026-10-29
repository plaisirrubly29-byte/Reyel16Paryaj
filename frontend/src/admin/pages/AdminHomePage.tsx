import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, type Paginated } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../shared/components/Icon';
import { Spinner } from '../../shared/components/Spinner';
import { formatMoney } from '../../shared/format/money';
import { useMe } from '../../features/auth/api/auth';
import { useDashboard, type AuditEntry, type DailyPoint, type DashboardSummary } from '../api/dashboard';
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

const money = (minor: number, currency: string) => formatMoney({ minor, currency });

/** Montant court pour les axes (« 12,5 k HTG »), décimales tirées de la devise comme formatMoney. */
function compactMoney(minor: number, currency: string): string {
  const digits = new Intl.NumberFormat('fr-FR', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
  return new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(minor / 10 ** digits);
}

const count = (n: number) => n.toLocaleString('fr-FR');

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

/** Somme des 7 derniers jours comparée aux 7 précédents, en %. null si pas de base de comparaison. */
function weekTrend(points: DailyPoint[]): number | null {
  const sum = (list: DailyPoint[]) => list.reduce((s, p) => s + p.value, 0);
  const recent = sum(points.slice(-7));
  const before = sum(points.slice(-14, -7));
  return before > 0 ? Math.round(((recent - before) / before) * 100) : null;
}

type KpiProps = { icon: IconName; label: string; value: string; hint?: ReactNode; trend?: number | null; points?: DailyPoint[]; to?: string };

function Kpi({ icon, label, value, hint, trend, points, to }: KpiProps) {
  const body = (
    <>
      <div className="kpi-head">
        <span className="kpi-icon" aria-hidden="true"><Icon name={icon} size={18} /></span>
        <span className="kpi-label">{label}</span>
        {trend !== undefined && trend !== null && (
          <span className={`kpi-trend ${trend >= 0 ? 'is-up' : 'is-down'}`} title="7 derniers jours comparés aux 7 précédents">
            <Icon name={trend >= 0 ? 'trendUp' : 'trendDown'} size={14} />
            {trend > 0 ? '+' : ''}{trend} %
          </span>
        )}
      </div>
      <strong className="kpi-value">{value}</strong>
      {hint && <span className="kpi-hint">{hint}</span>}
      {points && <Sparkline points={points} />}
    </>
  );

  return to ? <Link to={to} className="kpi kpi-link">{body}</Link> : <article className="kpi">{body}</article>;
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

function Attention({ data }: { data: DashboardSummary }) {
  const items: { icon: IconName; tone: 'warning' | 'info'; text: string; to: string; cta: string }[] = [];
  const corrections = data.finance?.pending_corrections ?? 0;
  const suspended = data.catalogue?.markets_suspended ?? 0;

  if (corrections > 0) {
    items.push({ icon: 'scale', tone: 'warning', text: `${corrections} correction${corrections > 1 ? 's' : ''} financière${corrections > 1 ? 's' : ''} en attente de validation`, to: '/admin/finance', cta: 'Valider' });
  }
  if (suspended > 0) {
    items.push({ icon: 'alert', tone: 'warning', text: `${suspended} marché${suspended > 1 ? 's' : ''} suspendu${suspended > 1 ? 's' : ''}`, to: '/admin/events', cta: 'Examiner' });
  }
  if (items.length === 0) return null;

  return (
    <ul className="attention" aria-label="À traiter">
      {items.map((item) => (
        <li key={item.to} className={`attention-item is-${item.tone}`}>
          <Icon name={item.icon} size={18} />
          <span>{item.text}</span>
          <Link to={item.to}>{item.cta}<Icon name="arrowRight" size={14} /></Link>
        </li>
      ))}
    </ul>
  );
}

function StatusBreakdown({ breakdown }: { breakdown: NonNullable<DashboardSummary['betting']>['status_breakdown'] }) {
  const total = breakdown.reduce((s, b) => s + b.count, 0);

  return total === 0 ? (
    <p className="dash-panel-empty">Aucun ticket sur la période.</p>
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

function Stat({ label, value, icon, tone }: { label: string; value: string; icon: IconName; tone?: 'live' | 'warning' }) {
  return (
    <div className={['mini-stat', tone && `is-${tone}`].filter(Boolean).join(' ')}>
      <span className="mini-stat-icon" aria-hidden="true"><Icon name={icon} size={16} /></span>
      <span className="mini-stat-label">{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

/** Vue d'ensemble : indicateurs clés, tendances sur 14 jours, points d'attention et activité récente. */
export function AdminHomePage() {
  const { data: user } = useMe();
  const permissions = user?.permissions ?? [];
  const canReadAudit = permissions.includes('audit.view');
  const dashboard = useDashboard();

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

      <ErrorAlert error={dashboard.error} onRetry={() => dashboard.refetch()} />
      {dashboard.isPending && <div className="table-state"><Spinner /></div>}

      {data && (
        <>
          <Attention data={data} />

          <div className="kpi-grid">
            {data.betting && (
              <>
                <Kpi icon="wallet" label="Mises du jour" value={money(data.betting.stake_today_minor, cur)} trend={weekTrend(data.betting.stakes_minor)} points={data.betting.stakes_minor} hint={`${count(data.betting.tickets_today)} ticket${data.betting.tickets_today > 1 ? 's' : ''} aujourd’hui`} to="/admin/tickets" />
                <Kpi icon="coupon" label="Tickets en cours" value={count(data.betting.pending_count)} hint={<>Exposition max. <strong>{money(data.betting.pending_exposure_minor, cur)}</strong></>} points={data.betting.tickets} trend={weekTrend(data.betting.tickets)} to="/admin/tickets" />
              </>
            )}
            {data.users && (
              <Kpi icon="users" label="Joueurs inscrits" value={count(data.users.total)} hint={`+${count(data.users.new_7d)} sur 7 jours · ${count(data.users.suspended)} suspendu${data.users.suspended > 1 ? 's' : ''}`} points={data.users.registrations} trend={weekTrend(data.users.registrations)} to="/admin/users" />
            )}
            {data.finance && (
              <Kpi
                icon="scale"
                label={`Produit brut (${data.finance.period_days} j)`}
                value={formatMoney({ minor: data.finance.gross_gaming_revenue_minor, currency: cur }, 'fr-FR', { signed: true })}
                hint={`Mises ${money(data.finance.settled_stakes_minor, cur)} · gains ${money(data.finance.payouts_minor, cur)}`}
                to="/admin/finance"
              />
            )}
          </div>

          {data.betting && (
            <div className="dash-grid">
              <Panel title="Mises par jour" subtitle="14 derniers jours, tous tickets confondus" className="span-2">
                <DailyBarChart
                  points={data.betting.stakes_minor}
                  label="Mises"
                  format={(v) => money(v, cur)}
                  formatAxis={(v) => compactMoney(v, cur)}
                />
              </Panel>
              <Panel title="Issue des tickets" subtitle="Tickets placés sur 14 jours">
                <StatusBreakdown breakdown={data.betting.status_breakdown} />
              </Panel>
            </div>
          )}
        </>
      )}

      {(data?.catalogue || canReadAudit) && (
          <div className="dash-grid">
            {data?.catalogue && (
              <Panel title="Catalogue" subtitle="Matchs et marchés proposés" action={<Link to="/admin/events" className="dash-panel-link">Gérer<Icon name="arrowRight" size={14} /></Link>}>
                <div className="mini-stats">
                  <Stat icon="live" label="Matchs en direct" value={count(data.catalogue.events_live)} tone={data.catalogue.events_live > 0 ? 'live' : undefined} />
                  <Stat icon="calendar" label="Dans les 24 h" value={count(data.catalogue.events_next_24h)} />
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
