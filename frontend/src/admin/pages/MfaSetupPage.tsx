import { useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ApiError } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { Button } from '../../shared/components/Button';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { TextField } from '../../shared/components/TextField';
import { ME_KEY } from '../../features/auth/api/auth';
import { useMfaConfirm, useMfaSetup } from '../../features/auth/api/mfa';
import { MfaQrCode } from '../components/MfaQrCode';

/** Activation obligatoire du MFA pour tout compte administratif (première connexion). */
export function MfaSetupPage() {
  const setup = useMfaSetup();
  const confirm = useMfaConfirm();
  const queryClient = useQueryClient();
  const [code, setCode] = useState('');

  const confirmError = confirm.error instanceof ApiError ? confirm.error : null;

  function submit(e: FormEvent) {
    e.preventDefault();
    confirm.mutate(code.replace(/\s/g, ''));
  }

  // Étape 3 : codes de secours, affichés une seule fois.
  if (confirm.data) {
    return (
      <section className="auth-card stack">
        <h1>Double authentification activée</h1>
        <Alert tone="warning" title="Conservez ces codes de secours">
          Chaque code permet une connexion si vous perdez votre téléphone. Ils ne seront plus jamais affichés.
        </Alert>
        <ul className="recovery-codes" aria-label="Codes de secours">
          {confirm.data.map((c) => <li key={c}><code>{c}</code></li>)}
        </ul>
        <Button onClick={() => queryClient.invalidateQueries({ queryKey: ME_KEY })}>J'ai conservé mes codes</Button>
      </section>
    );
  }

  return (
    <section className="auth-card stack">
      <h1>Activer la double authentification</h1>
      <p className="muted">
        L'accès à l'administration exige un code à usage unique généré par une application
        (Google Authenticator, Microsoft Authenticator, Authy…).
      </p>

      <ErrorAlert error={setup.error} />

      {!setup.data ? (
        <Button onClick={() => setup.mutate()} loading={setup.isPending}>Commencer</Button>
      ) : (
        <>
          <ol className="steps">
            <li>Scannez ce QR code avec votre application.</li>
          </ol>
          <MfaQrCode uri={setup.data.otpauth_uri} />
          <p className="muted small">
            Impossible de scanner ? Saisissez cette clé : <code className="secret">{setup.data.secret}</code>
          </p>
          <ol className="steps" start={2}>
            <li>Saisissez le code à 6 chiffres affiché par l'application.</li>
          </ol>
          <form className="stack" onSubmit={submit} noValidate>
            <TextField
              label="Code de vérification"
              name="code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={7}
              value={code}
              onChange={(e) => setCode(e.target.value)}
              error={confirmError ? confirmError.fieldErrors.code ?? confirmError.message : undefined}
            />
            <Button type="submit" loading={confirm.isPending} block>Activer</Button>
          </form>
        </>
      )}
    </section>
  );
}
