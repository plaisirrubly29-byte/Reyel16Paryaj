import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { TextField } from '../../../shared/components/TextField';
import { useForgotPassword } from '../api/auth';
import { AuthShell } from '../components/AuthShell';

export function ForgotPasswordPage() {
  const forgot = useForgotPassword();
  const [email, setEmail] = useState('');

  const error = forgot.error instanceof ApiError ? forgot.error : null;

  function submit(e: FormEvent) {
    e.preventDefault();
    forgot.mutate(email);
  }

  return (
    <AuthShell
      title="Mot de passe oublié"
      subtitle={forgot.isSuccess ? undefined : 'Indiquez votre email : vous recevrez un lien valable 30 minutes.'}
      icon="mail"
    >
      {forgot.isSuccess ? (
        // Message identique que le compte existe ou non (fourni par le serveur).
        <Alert tone="success">{forgot.data.data.message}</Alert>
      ) : (
        <>
          {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}
          <form className="stack" onSubmit={submit} noValidate>
            <TextField
              label="Email"
              type="email"
              name="email"
              autoComplete="email"
              icon="mail"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              error={error?.fieldErrors.email}
            />
            <Button type="submit" loading={forgot.isPending} block>Envoyer le lien</Button>
          </form>
        </>
      )}

      <p className="auth-foot"><Link to="/login">Retour à la connexion</Link></p>
    </AuthShell>
  );
}
