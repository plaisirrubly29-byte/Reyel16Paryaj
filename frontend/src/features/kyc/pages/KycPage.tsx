import { useEffect, useId, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon, type IconName } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import {
  DOCUMENT_ACCEPT, DOCUMENT_TYPE_LABELS, DOCUMENT_TYPES, MAX_FILE_BYTES, SELFIE_ACCEPT, useKyc, useSubmitKyc,
  type KycDocumentType, type KycState, type KycStatus,
} from '../api/kyc';

/** « 9 novembre 2026 » (date locale du joueur). */
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

const STATUS_BADGES: Record<KycStatus, string> = {
  none: 'Non vérifié',
  pending: 'En cours d\'examen',
  approved: 'Vérifié',
  rejected: 'À renvoyer',
};

/** Icône de chaque pièce acceptée dans le choix du document. */
const DOCUMENT_ICONS: Record<KycDocumentType, IconName> = { cin: 'idCard', passport: 'flag', driving_licence: 'idCard', nif: 'list' };

/**
 * Vérification d'identité du joueur (V2-C03) : bandeau avec le statut, étapes (envoi, examen, vérifié), puis le
 * formulaire (pièce, photos, selfie) ou le résultat. Le serveur décide (revue par l'équipe Conformité) ; l'écran
 * affiche son statut.
 */
export function KycPage() {
  const kyc = useKyc();
  const status = kyc.data?.status;

  return (
    <section className="kyc stack">
      <header className="kyc-hero">
        <span className="kyc-hero-icon" aria-hidden="true"><Icon name="shield" size={26} /></span>
        <div className="kyc-hero-text">
          <h1>Vérification d'identité</h1>
          <p>
            Une identité vérifiée est exigée pour retirer vos gains
            {kyc.data && <>, et pour déposer plus de <MoneyText value={kyc.data.deposit_threshold} /> au total</>}.
          </p>
        </div>
        {status && <span className={`kyc-badge kyc-badge-${status}`}>{STATUS_BADGES[status]}</span>}
      </header>

      {kyc.isPending && <div className="table-state"><Spinner /></div>}
      {kyc.isError && <ErrorAlert error={kyc.error} onRetry={() => kyc.refetch()} />}
      {kyc.data && (
        <>
          <KycSteps status={kyc.data.status} />
          <KycContent state={kyc.data} />
        </>
      )}
    </section>
  );
}

/** Les trois étapes du dossier ; l'étape en cours est annoncée aux lecteurs d'écran. */
function KycSteps({ status }: { status: KycStatus }) {
  const current = status === 'approved' ? 3 : status === 'pending' ? 1 : 0;
  const steps = ['Envoi du dossier', 'Examen par notre équipe', 'Identité vérifiée'];

  return (
    <ol className="kyc-steps" aria-label="Étapes de la vérification">
      {steps.map((label, i) => {
        const state = i < current ? 'done' : i === current ? 'current' : 'todo';
        return (
          <li key={label} className={`kyc-step kyc-step-${state}`} aria-current={state === 'current' ? 'step' : undefined}>
            <span className="kyc-step-dot" aria-hidden="true">{state === 'done' ? <Icon name="check" size={14} /> : i + 1}</span>
            <span className="kyc-step-label">{label}</span>
          </li>
        );
      })}
    </ol>
  );
}

function KycContent({ state }: { state: KycState }) {
  const latest = state.latest_submission;

  if (state.status === 'approved') {
    return (
      <div className="kyc-result kyc-result-ok" role="status">
        <span className="kyc-result-icon" aria-hidden="true"><Icon name="check" size={30} /></span>
        <h2>Identité vérifiée</h2>
        <p>
          Votre identité a été vérifiée{state.verified_at && <> le {formatDate(state.verified_at)}</>}. Vous pouvez
          déposer et retirer sans autre démarche.
        </p>
        <Link to="/wallet" className="btn">Aller au solde</Link>
      </div>
    );
  }

  if (state.status === 'pending') {
    return (
      <div className="kyc-result kyc-result-pending" role="status">
        <span className="kyc-result-icon" aria-hidden="true"><Icon name="clock" size={30} /></span>
        <h2>Dossier en cours d'examen</h2>
        <p>
          {latest ? <>Votre dossier ({DOCUMENT_TYPE_LABELS[latest.document_type]}) a été envoyé le {formatDate(latest.submitted_at)}.</> : 'Votre dossier a été envoyé.'}{' '}
          Notre équipe le vérifie : votre statut s'affichera ici dès sa décision.
        </p>
      </div>
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
  const ready = [front, type.back ? back : true, selfie].filter(Boolean).length;
  const needed = type.back ? 3 : 2;

  function send(e: FormEvent) {
    e.preventDefault();
    setTried(true);
    if (missing || [front, back, selfie].some((f) => f && f.size > MAX_FILE_BYTES)) return;
    submit.mutate({ documentType, front: front!, back: type.back ? back : null, selfie: selfie! });
  }

  return (
    <form className="kyc-form" onSubmit={send} noValidate aria-labelledby="kyc-form">
      <h2 id="kyc-form" className="sr-only">Envoyer mon dossier</h2>

      {error && error.code !== 'validation_failed' && error.code !== 'kyc_back_required' && <ErrorAlert error={error} />}

      <section className="kyc-section" aria-labelledby="kyc-doc-title">
        <h3 id="kyc-doc-title" className="kyc-section-title"><span aria-hidden="true">1</span>Choisissez votre pièce</h3>
        <fieldset className="kyc-types">
          <legend className="sr-only">Pièce d'identité</legend>
          {DOCUMENT_TYPES.map((d) => (
            <label key={d.value} className="kyc-type">
              <input type="radio" name="document-type" value={d.value} checked={documentType === d.value} onChange={() => setDocumentType(d.value)} />
              <span className="kyc-type-icon" aria-hidden="true"><Icon name={DOCUMENT_ICONS[d.value]} size={22} /></span>
              <span className="kyc-type-text"><strong>{d.label}</strong><span>{d.hint}</span></span>
              <span className="kyc-type-check" aria-hidden="true"><Icon name="check" size={14} /></span>
            </label>
          ))}
        </fieldset>
      </section>

      <section className="kyc-section" aria-labelledby="kyc-photos-title">
        <h3 id="kyc-photos-title" className="kyc-section-title">
          <span aria-hidden="true">2</span>Ajoutez les photos
          <small className="kyc-progress">{ready} / {needed}</small>
        </h3>
        <div className="kyc-files">
          <FilePicker
            label={type.back ? 'Recto de la pièce' : 'Photo de la pièce'}
            icon="idCard"
            accept={DOCUMENT_ACCEPT}
            hint="JPG, PNG, WEBP ou PDF · 5 Mo max."
            file={front}
            onChange={setFront}
            error={serverErrors.front ?? (tried && !front ? 'Ajoutez la photo de votre pièce.' : undefined)}
          />
          {type.back && (
            <FilePicker
              label="Verso de la pièce"
              icon="idCard"
              accept={DOCUMENT_ACCEPT}
              hint="JPG, PNG, WEBP ou PDF · 5 Mo max."
              file={back}
              onChange={setBack}
              error={backError ?? (tried && !back ? 'Ajoutez le verso de votre pièce.' : undefined)}
            />
          )}
          <FilePicker
            label="Selfie"
            icon="camera"
            accept={SELFIE_ACCEPT}
            capture="user"
            hint="Visage bien éclairé, sans lunettes de soleil ni casquette"
            file={selfie}
            onChange={setSelfie}
            error={serverErrors.selfie ?? (tried && !selfie ? 'Ajoutez votre selfie.' : undefined)}
          />
        </div>
        <ul className="kyc-tips" aria-label="Conseils pour des photos acceptées">
          <li><Icon name="check" size={14} />Pièce en cours de validité</li>
          <li><Icon name="check" size={14} />Photo nette, sans reflet</li>
          <li><Icon name="check" size={14} />Les quatre coins visibles</li>
        </ul>
      </section>

      <footer className="kyc-form-foot">
        <p className="kyc-secure"><Icon name="lock" size={16} />Vos documents sont chiffrés et consultés uniquement par notre équipe de conformité.</p>
        <Button type="submit" loading={submit.isPending} className="kyc-submit">Envoyer mon dossier</Button>
      </footer>
    </form>
  );
}

type FilePickerProps = {
  label: string;
  hint: string;
  icon: IconName;
  accept: string;
  capture?: 'user';
  file: File | null;
  onChange: (file: File | null) => void;
  error?: string;
};

/**
 * Zone d'envoi d'un fichier (ou prise de photo sur téléphone) : icône, titre, consigne, puis aperçu du fichier choisi
 * et « Remplacer ». Toute la zone est cliquable ; taille contrôlée avant l'envoi.
 */
function FilePicker({ label, hint, icon, accept, capture, file, onChange, error }: FilePickerProps) {
  const id = useId();
  const preview = usePreviewUrl(file);
  const tooBig = file !== null && file.size > MAX_FILE_BYTES;
  const message = tooBig ? 'Ce fichier dépasse 5 Mo.' : error;

  return (
    <div className={['field', 'kyc-file', message && 'field-invalid'].filter(Boolean).join(' ')}>
      <div className={`kyc-drop${file ? ' has-file' : ''}`}>
        {preview ? <img src={preview} alt="" className="kyc-thumb" /> : (
          <span className="kyc-thumb kyc-thumb-empty" aria-hidden="true"><Icon name={file ? 'list' : icon} size={26} /></span>
        )}
        <span className="kyc-drop-text">
          <label htmlFor={id} className="kyc-drop-title">{label}</label>
          {file
            ? <span className="kyc-file-name"><strong>{file.name}</strong><span>{Math.max(1, Math.round(file.size / 1024))} Ko</span></span>
            : <span className="kyc-drop-hint">{hint}</span>}
        </span>
        <span className="kyc-drop-cta" aria-hidden="true">
          {file ? <><Icon name="check" size={14} />Remplacer</> : <><Icon name="upload" size={14} />Ajouter</>}
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
