import { useEffect, useId, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import {
  DOCUMENT_ACCEPT, DOCUMENT_TYPE_LABELS, DOCUMENT_TYPES, MAX_FILE_BYTES, SELFIE_ACCEPT, useKyc, useSubmitKyc,
  type KycDocumentType, type KycState,
} from '../api/kyc';

/** « 9 novembre 2026 » (date locale du joueur). */
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * Vérification d'identité du joueur (V2-C03) : statut du compte, envoi d'une pièce d'identité et d'un selfie,
 * motif d'un refus. Le serveur décide (revue par l'équipe Conformité) ; l'écran affiche son statut.
 */
export function KycPage() {
  const kyc = useKyc();

  return (
    <section className="rg stack">
      <header className="rg-head">
        <h1>Vérification d'identité</h1>
        <p className="muted">
          Une identité vérifiée est exigée pour retirer vos gains
          {kyc.data && <>, et pour déposer plus de <MoneyText value={kyc.data.deposit_threshold} /> au total</>}.
        </p>
      </header>

      {kyc.isPending && <div className="table-state"><Spinner /></div>}
      {kyc.isError && <ErrorAlert error={kyc.error} onRetry={() => kyc.refetch()} />}
      {kyc.data && <KycContent state={kyc.data} />}
    </section>
  );
}

function KycContent({ state }: { state: KycState }) {
  const latest = state.latest_submission;

  if (state.status === 'approved') {
    return (
      <Alert tone="success" title="Identité vérifiée">
        Votre identité a été vérifiée{state.verified_at && <> le {formatDate(state.verified_at)}</>}. Vous pouvez{' '}
        <Link to="/wallet">déposer et retirer</Link> sans autre démarche.
      </Alert>
    );
  }

  if (state.status === 'pending') {
    return (
      <Alert tone="info" title="Dossier en cours d'examen">
        {latest ? <>Votre dossier ({DOCUMENT_TYPE_LABELS[latest.document_type]}) a été envoyé le {formatDate(latest.submitted_at)}.</> : 'Votre dossier a été envoyé.'}{' '}
        Notre équipe le vérifie : votre statut s'affichera ici dès sa décision.
      </Alert>
    );
  }

  return (
    <>
      {state.status === 'rejected' && (
        <Alert tone="error" title="Dossier refusé">
          {latest?.rejection_reason ? <>Motif : « {latest.rejection_reason} »</> : 'Votre dernier dossier n\'a pas pu être validé.'}
          {' '}Envoyez un nouveau dossier ci-dessous.
        </Alert>
      )}
      <KycForm />
    </>
  );
}

function KycForm() {
  const submit = useSubmitKyc();
  const [documentType, setDocumentType] = useState<KycDocumentType>('cin');
  const [front, setFront] = useState<File | null>(null);
  const [back, setBack] = useState<File | null>(null);
  const [selfie, setSelfie] = useState<File | null>(null);
  const [tried, setTried] = useState(false);

  const type = DOCUMENT_TYPES.find((d) => d.value === documentType)!;
  const error = submit.error instanceof ApiError ? submit.error : null;
  const serverErrors = error?.fieldErrors ?? {};
  const backError = error?.code === 'kyc_back_required' ? 'Envoyez aussi le verso de la pièce.' : serverErrors.back;
  const missing = !front || !selfie || (type.back && !back);

  function send(e: FormEvent) {
    e.preventDefault();
    setTried(true);
    if (missing || [front, back, selfie].some((f) => f && f.size > MAX_FILE_BYTES)) return;
    submit.mutate({ documentType, front: front!, back: type.back ? back : null, selfie: selfie! });
  }

  return (
    <form className="rg-card" onSubmit={send} noValidate aria-labelledby="kyc-form">
      <header className="money-card-head">
        <span className="money-card-icon" aria-hidden="true"><Icon name="shield" size={22} /></span>
        <div>
          <h2 id="kyc-form">Envoyer mon dossier</h2>
          <p className="muted small">Une pièce d'identité en cours de validité et un selfie. Photos nettes, sans reflet, les quatre coins visibles.</p>
        </div>
      </header>

      {error && error.code !== 'validation_failed' && error.code !== 'kyc_back_required' && <ErrorAlert error={error} />}

      <fieldset className="kyc-types">
        <legend>Pièce d'identité</legend>
        {DOCUMENT_TYPES.map((d) => (
          <label key={d.value} className="kyc-type">
            <input type="radio" name="document-type" value={d.value} checked={documentType === d.value} onChange={() => setDocumentType(d.value)} />
            <span><strong>{d.label}</strong><span className="muted small">{d.hint}</span></span>
          </label>
        ))}
      </fieldset>

      <div className="kyc-files">
        <FilePicker
          label={type.back ? 'Recto de la pièce' : 'Photo de la pièce'}
          accept={DOCUMENT_ACCEPT}
          hint="JPG, PNG, WEBP ou PDF, 5 Mo au plus"
          file={front}
          onChange={setFront}
          error={serverErrors.front ?? (tried && !front ? 'Ajoutez la photo de votre pièce.' : undefined)}
        />
        {type.back && (
          <FilePicker
            label="Verso de la pièce"
            accept={DOCUMENT_ACCEPT}
            hint="JPG, PNG, WEBP ou PDF, 5 Mo au plus"
            file={back}
            onChange={setBack}
            error={backError ?? (tried && !back ? 'Ajoutez le verso de votre pièce.' : undefined)}
          />
        )}
        <FilePicker
          label="Selfie"
          accept={SELFIE_ACCEPT}
          capture="user"
          hint="Votre visage bien éclairé, sans lunettes de soleil ni casquette"
          file={selfie}
          onChange={setSelfie}
          error={serverErrors.selfie ?? (tried && !selfie ? 'Ajoutez votre selfie.' : undefined)}
        />
      </div>

      <p className="muted small">
        <Icon name="lock" size={14} /> Vos documents sont chiffrés et consultés uniquement par notre équipe de conformité.
      </p>

      <div>
        <Button type="submit" loading={submit.isPending}>Envoyer mon dossier</Button>
      </div>
    </form>
  );
}

type FilePickerProps = {
  label: string;
  hint: string;
  accept: string;
  capture?: 'user';
  file: File | null;
  onChange: (file: File | null) => void;
  error?: string;
};

/** Choix d'un fichier (ou prise de photo sur téléphone) avec aperçu ; taille contrôlée avant l'envoi. */
function FilePicker({ label, hint, accept, capture, file, onChange, error }: FilePickerProps) {
  const id = useId();
  const preview = usePreviewUrl(file);
  const tooBig = file !== null && file.size > MAX_FILE_BYTES;
  const message = tooBig ? 'Ce fichier dépasse 5 Mo.' : error;

  return (
    <div className={['field', 'kyc-file', message && 'field-invalid'].filter(Boolean).join(' ')}>
      <label htmlFor={id}>{label}</label>
      <div className="kyc-drop">
        {preview ? <img src={preview} alt="" className="kyc-thumb" /> : (
          <span className="kyc-thumb kyc-thumb-empty" aria-hidden="true"><Icon name={file ? 'list' : 'user'} size={26} /></span>
        )}
        <span className="kyc-file-name">
          {file ? <><strong>{file.name}</strong><span className="muted small">{Math.max(1, Math.round(file.size / 1024))} Ko</span></> : <span className="muted small">{hint}</span>}
        </span>
        <input
          id={id}
          type="file"
          accept={accept}
          capture={capture}
          onChange={(e) => onChange(e.target.files?.[0] ?? null)}
          aria-invalid={message ? true : undefined}
          aria-describedby={message ? `${id}-error` : undefined}
        />
      </div>
      {message && <small id={`${id}-error`} className="field-error" role="alert">{message}</small>}
    </div>
  );
}

/** URL d'aperçu d'une image choisie, libérée quand le fichier change. */
function usePreviewUrl(file: File | null): string | null {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    if (!file || !file.type.startsWith('image/') || typeof URL.createObjectURL !== 'function') {
      setUrl(null);
      return;
    }
    const next = URL.createObjectURL(file);
    setUrl(next);
    return () => URL.revokeObjectURL(next);
  }, [file]);

  return url;
}
