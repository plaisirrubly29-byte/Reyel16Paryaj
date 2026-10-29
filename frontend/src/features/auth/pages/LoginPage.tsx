import { useState, type ComponentProps, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { TextField } from '../../../shared/components/TextField';
import { safeRedirect, useLogin, type LoginInput } from '../api/auth';
import { AuthShell } from '../components/AuthShell';
import { digitsOnly } from '../phone';

/** Un « @ » désigne un email ; sinon un numéro haïtien, normalisé comme à l'inscription (+509 et séparateurs retirés). */
function loginInput(login: string, password: string): LoginInput {
  const value = login.trim();
  return value.includes('@') ? { email: value, password } : { phone: digitsOnly(value), password };
}

const ADMIN_VISUAL = {
  heading: 'Espace administrateur',
  highlights: [
    { icon: 'shield', text: 'Double authentification obligatoire' },
    { icon: 'lock', text: 'Accès selon votre rôle' },
    { icon: 'eye', text: 'Actions sensibles journalisées' },
  ],
} satisfies NonNullable<ComponentProps<typeof AuthShell>['visual']>;

/** Connexion joueur (/login) ou administrateur (/admin/login) : même formulaire, habillage et retour différents. */
export function LoginPage({ admin = false }: { admin?: boolean }) {
  const login = useLogin();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [form, setForm] = useState({ login: '', password: '' });

  const error = login.error instanceof ApiError ? login.error : null;
  const fieldErrors = error?.fieldErrors ?? {};

  function submit(e: FormEvent) {
    e.preventDefault();
    login.mutate(loginInput(form.login, form.password), {
      onSuccess: ({ data }) => {
        // Un administrateur passe par la double authentification (P6-05) avant l'admin.
        const fallback = admin || data.is_admin ? '/admin' : '/';
        navigate(safeRedirect(params.get('redirect'), fallback), { replace: true });
      },
    });
  }

  return (
    <AuthShell
      title={admin ? 'Connexion administrateur' : 'Connexion'}
      subtitle={admin ? 'Réservé aux comptes de l’équipe REYEL 16 PARYAJ.' : 'Accédez à votre compte pour suivre vos tickets.'}
      icon={admin ? 'shield' : 'lock'}
      visual={admin ? ADMIN_VISUAL : undefined}
    >
      {/* Identifiants invalides : message général (le serveur ne dit pas si le compte existe). */}
      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <form className="stack" onSubmit={submit} noValidate>
        <TextField
          label="Téléphone ou email"
          labelHidden
          type="text"
          name="login"
          autoComplete="username"
          autoCapitalize="none"
          spellCheck={false}
          maxLength={255}
          placeholder="Téléphone (34 12 34 56) ou email"
          required
          value={form.login}
          onChange={(e) => setForm({ ...form, login: e.target.value })}
          error={fieldErrors.phone ?? fieldErrors.email}
        />
        <TextField
          label="Mot de passe"
          labelHidden
          type="password"
          name="password"
          autoComplete="current-password"
          icon="lock"
          placeholder="Votre mot de passe"
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
        {admin ? <Link to="/">Retour au site</Link> : <Link to="/register">Créer un compte</Link>}
      </p>
    </AuthShell>
  );
}
