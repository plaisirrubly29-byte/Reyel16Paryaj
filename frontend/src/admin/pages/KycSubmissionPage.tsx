import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMe } from '../../features/auth/api/auth';
import { DOCUMENT_TYPE_LABELS } from '../../features/kyc/api/kyc';
import { Alert } from '../../shared/components/Alert';
import { Button } from '../../shared/components/Button';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Spinner } from '../../shared/components/Spinner';
import { dateTime } from '../api/events';
import { declaredName, KYC_FILE_LABELS, useDecideKyc, useKycFile, useKycSubmission, type AdminKycSubmission } from '../api/kyc';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';
import { KycStatusBadge } from './KycQueuePage';

/** Dossier KYC : identité déclarée, pièces déchiffrées à la demande (consultation auditée), décision motivée. */
export function KycSubmissionPage() {
  const { id = '' } = useParams();
  const submission = useKycSubmission(id);
  const s = submission.data;

  return (
    <section className="admin-page">
      <PageHeader
        back={{ to: '/admin/kyc', label: 'Dossiers KYC' }}
        title={s ? `Dossier n° ${s.id}` : 'Dossier KYC'}
        subtitle={s && <>{declaredName(s.user)} · <Link to={`/admin/users/${s.user.id}`}>joueur n° {s.user.id}</Link></>}
        actions={s && <KycStatusBadge status={s.status} />}
      />

      {submission.isPending && <Spinner label="Chargement du dossier" />}
      <ErrorAlert error={submission.error} onRetry={() => submission.refetch()} />

      {s && (
        <>
          <dl className="summary-grid">
            <div className="summary-item"><dt>Pièce</dt><dd>{DOCUMENT_TYPE_LABELS[s.document_type] ?? s.document_type}</dd></div>
            <div className="summary-item"><dt>Nom déclaré</dt><dd>{[s.user.first_name, s.user.last_name].filter(Boolean).join(' ') || '—'}</dd></div>
            <div className="summary-item"><dt>Date de naissance</dt><dd>{s.user.birth_date ? new Date(`${s.user.birth_date}T12:00:00`).toLocaleDateString('fr-FR') : '—'}</dd></div>
            <div className="summary-item"><dt>Envoyé le</dt><dd>{dateTime(s.created_at)}</dd></div>
          </dl>

          <div className="detail-grid">
            <section className="admin-card stack">
              <h2 className="card-title">Pièces envoyées</h2>
              <p className="muted small">
                Comparez la photo et les informations de la pièce avec le selfie et l'identité déclarée. Chaque pièce
                affichée est enregistrée dans le journal d'audit.
              </p>
              <div className="kyc-review-files">
                {[...(s.files ?? [])].sort((a, b) => FILE_ORDER.indexOf(a.kind) - FILE_ORDER.indexOf(b.kind)).map((f) => (
                  <KycFileViewer key={f.id} submissionId={s.id} file={f} />
                ))}
              </div>
              <p className="small">
                <Link to={`/admin/kyc?status=all&user_id=${encodeURIComponent(s.user.id)}`}>Tous les dossiers de ce joueur</Link>
              </p>
            </section>

            <aside className="admin-card stack">
              {s.status === 'pending' ? <Decision submission={s} /> : <DecisionSummary submission={s} />}
            </aside>
          </div>
        </>
      )}
    </section>
  );
}

type FileInfo = NonNullable<AdminKycSubmission['files']>[number];

const FILE_ORDER: FileInfo['kind'][] = ['front', 'back', 'selfie'];

/** Pièce chargée seulement sur demande : une consultation = une ligne d'audit côté serveur. */
function KycFileViewer({ submissionId, file }: { submissionId: string; file: FileInfo }) {
  const [shown, setShown] = useState(false);
  const label = KYC_FILE_LABELS[file.kind];

  return (
    <figure className="kyc-review-file">
      <figcaption><strong>{label}</strong> <span className="muted small">{file.mime_type === 'application/pdf' ? 'PDF' : 'Image'} · {Math.max(1, Math.round(file.size_bytes / 1024))} Ko</span></figcaption>
      {shown ? <KycFileContent submissionId={submissionId} file={file} label={label} /> : (
        <div className="kyc-review-placeholder">
          <Button variant="secondary" onClick={() => setShown(true)}>Afficher le {label.toLowerCase()}</Button>
        </div>
      )}
    </figure>
  );
}

function KycFileContent({ submissionId, file, label }: { submissionId: string; file: FileInfo; label: string }) {
  const content = useKycFile(submissionId, file.id);
  const url = useObjectUrl(content.data);

  if (content.isPending) return <div className="kyc-review-placeholder"><Spinner label={`Chargement : ${label}`} /></div>;
  if (content.error) return <ErrorAlert error={content.error} onRetry={() => content.refetch()} />;
  if (!url) return null;

  return file.mime_type === 'application/pdf'
    ? <object data={url} type="application/pdf" className="kyc-review-pdf" aria-label={label}><a href={url} target="_blank" rel="noreferrer">Ouvrir le PDF</a></object>
    : <a href={url} target="_blank" rel="noreferrer" title="Ouvrir en grand"><img src={url} alt={label} className="kyc-review-image" /></a>;
}

/** URL locale d'un fichier déchiffré, libérée en quittant la page. */
function useObjectUrl(blob: Blob | undefined): string | null {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    if (!blob || typeof URL.createObjectURL !== 'function') return;
    const next = URL.createObjectURL(blob);
    setUrl(next);
    return () => URL.revokeObjectURL(next);
  }, [blob]);

  return url;
}

/** Validation (kyc.approve) ou refus (kyc.reject), motif obligatoire ; le motif d'un refus est montré au joueur. */
function Decision({ submission }: { submission: AdminKycSubmission }) {
  const { data: me } = useMe();
  const decide = useDecideKyc(submission);
  const canApprove = Boolean(me?.permissions.includes('kyc.approve'));
  const canReject = Boolean(me?.permissions.includes('kyc.reject'));

  return (
    <>
      <h2 className="card-title">Décision</h2>
      {!canApprove && !canReject && <Alert tone="info">La décision est réservée aux administrateurs habilités (Super Admin, Conformité).</Alert>}

      {canApprove && (
        <ReasonForm
          id="approve"
          title="Valider l'identité"
          description="Le joueur pourra retirer ses gains et déposer au-delà du seuil. Le motif reste interne."
          submitLabel="Valider"
          onSubmit={(reason, idempotencyKey) => decide.mutateAsync({ decision: 'approve', reason, idempotencyKey })}
        />
      )}
      {canReject && (
        <ReasonForm
          id="reject"
          title="Refuser le dossier"
          description="Le motif est montré au joueur, qui peut envoyer un nouveau dossier : dites-lui quoi corriger."
          submitLabel="Refuser"
          variant="secondary"
          onSubmit={(reason, idempotencyKey) => decide.mutateAsync({ decision: 'reject', reason, idempotencyKey })}
        />
      )}
    </>
  );
}

function DecisionSummary({ submission }: { submission: AdminKycSubmission }) {
  return (
    <>
      <h2 className="card-title">{submission.status === 'approved' ? 'Identité validée' : 'Dossier refusé'}</h2>
      <dl className="details">
        <div className="details-row"><dt>Par</dt><dd>{submission.decided_by?.name ?? '—'}</dd></div>
        <div className="details-row"><dt>Le</dt><dd>{submission.decided_at ? dateTime(submission.decided_at) : '—'}</dd></div>
        <div className="details-row"><dt>Motif</dt><dd>{submission.decision_reason ?? '—'}</dd></div>
      </dl>
      {submission.status === 'rejected' && <p className="muted small">Le joueur voit ce motif et peut envoyer un nouveau dossier.</p>}
    </>
  );
}
