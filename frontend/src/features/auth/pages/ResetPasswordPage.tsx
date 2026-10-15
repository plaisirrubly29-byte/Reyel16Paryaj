import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { TextField } from '../../../shared/components/TextField';
import { useResetPassword } from '../api/auth';

/** Page ouverte depuis le lien reçu par email : /reset-password?token=…&email=… */
export function ResetPasswordPage() {
  const [params] = useSearchParams();
  const reset = useResetPassword();
  const [form, setForm] = useState({ password: '', password_confirmation: '' });

  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  const error = reset.error instanceof ApiError ? reset.error : null;

  if (!token || !email) {
    return (
      <section className="auth-card stack">
        <h1>Lien invalide</h1>
        <Alert tone="error">Ce lien de réinitialisation est incomplet.</Alert>
        <p><Link to="/forgot-password">Demander un nouveau lien</Link></p>
      </section>
    );
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    reset.mutate({ token, email, ...form });
  }

  return (
    <section className="auth-card stack">
      <h1>Nouveau mot de passe</h1>

      {reset.isSuccess ? (
        <>
          <Alert tone="success">{reset.data.data.message}</Alert>
          <p><Link to="/login">Se connecter</Link></p>
        </>
      ) : (
        <>
          {error?.code === 'invalid_reset_token' ? (
            <Alert tone="error" title="Lien expiré ou déjà utilisé">
              <p>{error.message} <Link to="/forgot-password">Demander un nouveau lien</Link></p>
            </Alert>
          ) : (
            error && error.code !== 'validation_failed' && <ErrorAlert error={error} />
          )}
          <form className="stack" onSubmit={submit} noValidate>
            <p className="muted">Compte : {email}</p>
            <TextField
              label="Nouveau mot de passe"
              type="password"
              name="password"
              autoComplete="new-password"
              required
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              error={error?.fieldErrors.password}
              hint="Au moins 10 caractères, avec des lettres et des chiffres."
            />
            <TextField
              label="Confirmer le mot de passe"
              type="password"
              name="password_confirmation"
              autoComplete="new-password"
              required
              value={form.password_confirmation}
              onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
            />
            <Button type="submit" loading={reset.isPending} block>Modifier le mot de passe</Button>
          </form>
        </>
      )}
    </section>
  );
}
