import { useState, type FormEvent } from 'react';
import { ApiError } from '../../shared/api/client';
import { Button } from '../../shared/components/Button';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { TextField } from '../../shared/components/TextField';
import { useMfaVerify } from '../../features/auth/api/mfa';

/** Saisie du code MFA après chaque connexion d'un administrateur. */
export function MfaVerifyPage() {
  const verify = useMfaVerify();
  const [useRecovery, setUseRecovery] = useState(false);
  const [value, setValue] = useState('');

  const error = verify.error instanceof ApiError ? verify.error : null;
  const fieldError = error && error.code !== 'too_many_requests' ? error.fieldErrors.code ?? error.fieldErrors.recovery_code ?? error.message : undefined;

  function submit(e: FormEvent) {
    e.preventDefault();
    const trimmed = value.replace(/\s/g, '');
    verify.mutate(useRecovery ? { recovery_code: trimmed } : { code: trimmed });
  }

  return (
    <section className="auth-card stack">
      <h1>Vérification en deux étapes</h1>
      <p className="muted">
        {useRecovery
          ? 'Saisissez un de vos codes de secours (chaque code ne sert qu\'une fois).'
          : 'Saisissez le code à 6 chiffres de votre application d\'authentification.'}
      </p>

      {error?.code === 'too_many_requests' && <ErrorAlert error={error} />}

      <form className="stack" onSubmit={submit} noValidate>
        <TextField
          label={useRecovery ? 'Code de secours' : 'Code de vérification'}
          name={useRecovery ? 'recovery_code' : 'code'}
          inputMode={useRecovery ? 'text' : 'numeric'}
          autoComplete="one-time-code"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          error={fieldError}
        />
        <Button type="submit" loading={verify.isPending} block>Vérifier</Button>
      </form>

      <button type="button" className="link-button" onClick={() => { setUseRecovery(!useRecovery); setValue(''); verify.reset(); }}>
        {useRecovery ? 'Utiliser le code de l\'application' : 'Utiliser un code de secours'}
      </button>
    </section>
  );
}
