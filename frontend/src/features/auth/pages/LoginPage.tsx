import { useState, type ComponentProps, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { TextField } from '../../../shared/components/TextField';
import { safeRedirect, useLogin, type LoginInput } from '../api/auth';
import { AuthShell } from '../components/AuthShell';
import { digitsOnly } from '../phone';

/** Équipe : un « @ » désigne un email ; sinon un numéro haïtien, normalisé comme à l'inscription. */
function adminLoginInput(login: string, password: string): LoginInput {
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

/**
 * Connexion joueur (/login) : numéro de téléphone (+509) et mot de passe.
 * Connexion administrateur (/admin/login) : téléphone ou email (les comptes de l'équipe utilisent souvent leur email).
 */
export function LoginPage({ admin = false }: { admin?: boolean }) {
  const login = useLogin();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [form, setForm] = useState({ login: '', password: '' });

  const error = login.error instanceof ApiError ? login.error : null;
  const fieldErrors = error?.fieldErrors ?? {};

  function submit(e: FormEvent) {
    e.preventDefault();
    const input = admin ? adminLoginInput(form.login, form.password) : { phone: digitsOnly(form.login), password: form.password };
    login.mutate(input, {
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
      subtitle={admin ? 'Réservé aux comptes de l’équipe REYEL 16 PARYAJ.' : 'Connectez-vous avec votre numéro de téléphone.'}
      icon={admin ? 'shield' : 'lock'}
      visual={admin ? ADMIN_VISUAL : undefined}
    >
      {/* Identifiants invalides : message général (le serveur ne dit pas si le compte existe). */}
      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <form className="stack" onSubmit={submit} noValidate>
        {admin ? (
          <TextField
            label="Téléphone ou email"
            type="text"
            name="login"
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            maxLength={255}
            icon="user"
            placeholder="34 12 34 56 ou email"
            required
            value={form.login}
            onChange={(e) => setForm({ ...form, login: e.target.value })}
            error={fieldErrors.phone ?? fieldErrors.email}
          />
        ) : (
          <TextField
            label="Numéro de téléphone"
            type="tel"
            inputMode="numeric"
            name="phone"
            autoComplete="username"
            prefix="+509"
            placeholder="34 12 34 56"
            required
            value={form.login}
            // Un numéro collé avec l'indicatif (+509 34 12 34 56) est ramené aux 8 chiffres locaux.
            onChange={(e) => setForm({ ...form, login: digitsOnly(e.target.value) })}
            error={fieldErrors.phone}
          />
        )}
        <TextField
          label="Mot de passe"
          type="password"
          name="password"
          autoComplete="current-password"
          icon="lock"
          revealable
          placeholder="Votre mot de passe"
          required
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          error={fieldErrors.password}
        />
        <Link className="auth-forgot" to="/forgot-password">Mot de passe oublié ?</Link>
        <Button type="submit" loading={login.isPending} block>Se connecter</Button>
      </form>

      {admin ? (
        <p className="auth-foot"><Link to="/">Retour au site</Link></p>
      ) : (
        <div className="auth-switch">
          <span className="auth-switch-line" aria-hidden="true" />
          <p className="auth-foot">Pas encore de compte ?</p>
          <Link className="btn btn-secondary auth-switch-btn" to="/register">Créer un compte</Link>
        </div>
      )}
    </AuthShell>
  );
}
