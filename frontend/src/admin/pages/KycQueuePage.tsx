import { Link, useSearchParams } from 'react-router-dom';
import { DOCUMENT_TYPE_LABELS } from '../../features/kyc/api/kyc';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Pagination } from '../../shared/components/Pagination';
import { dateTime } from '../api/events';
import { declaredName, KYC_SUBMISSION_STATUS_LABELS, useKycSubmissions, type AdminKycSubmission, type KycSubmissionStatus } from '../api/kyc';
import { PageHeader } from '../components/PageHeader';

const STATUSES = Object.keys(KYC_SUBMISSION_STATUS_LABELS) as KycSubmissionStatus[];

export function KycStatusBadge({ status }: { status: KycSubmissionStatus }) {
  const tone = { pending: 'status-suspended', approved: 'status-active', rejected: 'status-closed' }[status];

  return <span className={`badge ${tone}`}>{KYC_SUBMISSION_STATUS_LABELS[status]}</span>;
}

const columns: Column<AdminKycSubmission>[] = [
  {
    key: 'submission',
    header: 'Dossier',
    render: (s) => (
      <div className="cell-stack">
        <Link to={`/admin/kyc/${s.id}`}>Dossier n° {s.id}</Link>
        <span className="muted small">Envoyé le {dateTime(s.created_at)}</span>
      </div>
    ),
  },
  {
    key: 'player',
    header: 'Joueur',
    render: (s) => (
      <div className="cell-stack">
        <span>{declaredName(s.user)}</span>
        <Link className="small" to={`/admin/users/${s.user.id}`}>Joueur n° {s.user.id}</Link>
      </div>
    ),
  },
  { key: 'document', header: 'Pièce', render: (s) => DOCUMENT_TYPE_LABELS[s.document_type] ?? s.document_type },
  { key: 'decided', header: 'Décision', render: (s) => (s.decided_at ? <span className="small">{s.decided_by?.name ?? '—'} · {dateTime(s.decided_at)}</span> : '—') },
  { key: 'status', header: 'Statut', render: (s) => <KycStatusBadge status={s.status} /> },
];

/** File des dossiers KYC (kyc.view) : « À vérifier » par défaut, le plus ancien en premier. */
export function KycQueuePage() {
  const [params, setParams] = useSearchParams();
  const statusParam = params.get('status');
  const status = statusParam === 'all' ? undefined : STATUSES.includes(statusParam as KycSubmissionStatus) ? (statusParam as KycSubmissionStatus) : 'pending';
  const userId = params.get('user_id') ?? undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);
  const submissions = useKycSubmissions({ status, userId, page });
  const total = submissions.data?.meta.total;

  function update(changes: Record<string, string | undefined>) {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(changes)) {
      if (value) next.set(key, value); else next.delete(key);
    }
    if (!('page' in changes)) next.delete('page');
    setParams(next);
  }

  return (
    <section className="admin-page">
      <PageHeader
        title="Vérification d'identité"
        subtitle={status === 'pending' && total !== undefined
          ? `${total} dossier${total > 1 ? 's' : ''} à vérifier, du plus ancien au plus récent.`
          : 'Dossiers KYC des joueurs : pièce d\'identité, selfie et décision motivée.'}
      />

      <div className="admin-card">
        <div className="filters">
          <div className="field">
            <label htmlFor="kyc-filter">Dossiers</label>
            <select id="kyc-filter" value={status ?? 'all'} onChange={(e) => update({ status: e.target.value })}>
              {STATUSES.map((s) => <option key={s} value={s}>{KYC_SUBMISSION_STATUS_LABELS[s]}</option>)}
              <option value="all">Tous</option>
            </select>
          </div>
          {userId && (
            <p className="small">
              Joueur n° {userId} · <button type="button" className="link-button" onClick={() => update({ user_id: undefined })}>Tous les joueurs</button>
            </p>
          )}
        </div>

        <ErrorAlert error={submissions.error} onRetry={() => submissions.refetch()} />
        <DataTable
          caption="Dossiers KYC"
          columns={columns}
          rows={submissions.data?.data}
          rowKey={(s) => s.id}
          loading={submissions.isFetching}
          empty={status === 'pending' ? 'Aucun dossier à vérifier.' : 'Aucun dossier.'}
        />
        <Pagination meta={submissions.data?.meta} onPage={(p) => update({ page: String(p) })} />
      </div>
    </section>
  );
}
