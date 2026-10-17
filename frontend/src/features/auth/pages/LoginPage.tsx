import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { TextField } from '../../../shared/components/TextField';
import { safeRedirect, useLogin } from '../api/auth';
import { AuthShell } from '../components/AuthShell';

export function LoginPage() {
  const login = useLogin();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [form, setForm] = useState({ email: '', password: '' });

  const error = login.error instanceof ApiError ? login.error : null;
  const fieldErrors = error?.fieldErrors ?? {};

  function submit(e: FormEvent) {
    e.preventDefault();
    login.mutate(form, {
      onSuccess: ({ data }) => {
        // Un administrateur passe par la double authentification (P6-05) avant l'admin.
        const fallback = data.is_admin ? '/admin' : '/';
        navigate(safeRedirect(params.get('redirect'), fallback), { replace: true });
      },
    });
  }

  return (
    <AuthShell title="Connexion" subtitle="Accédez à votre compte pour suivre vos tickets." icon="lock">
      {/* Identifiants invalides : message général (le serveur ne dit pas si le compte existe). */}
      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <form className="stack" onSubmit={submit} noValidate>
        <TextField
          label="Email"
          type="email"
          name="email"
          autoComplete="email"
          icon="mail"
          required
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
          error={fieldErrors.email}
        />
        <TextField
          label="Mot de passe"
          type="password"
          name="password"
          autoComplete="current-password"
          icon="lock"
          required
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          error={fieldErrors.password}
        />
        <Button type="submit" loading={login.isPending} block>Se connecter</Button>
      </form>

      <p className="auth-foot">
        <Link to="/forgot-password">Mot de passe oublié ?</Link>
        <span className="auth-foot-sep" aria-hidden="true">·</span>
        <Link to="/register">Créer un compte</Link>
      </p>
    </AuthShell>
  );
}
