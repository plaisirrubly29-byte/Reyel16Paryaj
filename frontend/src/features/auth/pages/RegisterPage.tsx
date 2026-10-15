import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { TextField } from '../../../shared/components/TextField';
import { useRegister, type RegisterInput } from '../api/auth';

const empty: RegisterInput = {
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  birth_date: '',
  country: 'HT',
};

export function RegisterPage() {
  const register = useRegister();
  const navigate = useNavigate();
  const [form, setForm] = useState<RegisterInput>(empty);

  const error = register.error instanceof ApiError ? register.error : null;
  const fieldErrors = error?.fieldErrors ?? {};
  const set = (field: keyof RegisterInput) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value });

  function submit(e: FormEvent) {
    e.preventDefault();
    // L'âge minimum et la politique de mot de passe sont vérifiés par le serveur.
    register.mutate(form, { onSuccess: () => navigate('/account', { replace: true }) });
  }

  return (
    <section className="auth-card stack">
      <h1>Créer un compte</h1>
      <p className="muted">Version de démonstration : les mises se font avec des fonds fictifs.</p>

      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <form className="stack" onSubmit={submit} noValidate>
        <TextField label="Nom" name="name" autoComplete="name" required value={form.name} onChange={set('name')} error={fieldErrors.name} />
        <TextField label="Email" type="email" name="email" autoComplete="email" required value={form.email} onChange={set('email')} error={fieldErrors.email} />
        <TextField
          label="Date de naissance"
          type="date"
          name="birth_date"
          autoComplete="bday"
          required
          value={form.birth_date}
          onChange={set('birth_date')}
          error={fieldErrors.birth_date}
          hint="Vous devez être majeur pour ouvrir un compte."
        />
        <TextField
          label="Pays (code à 2 lettres)"
          name="country"
          autoComplete="country"
          maxLength={2}
          value={form.country ?? ''}
          onChange={set('country')}
          error={fieldErrors.country}
        />
        <TextField
          label="Mot de passe"
          type="password"
          name="password"
          autoComplete="new-password"
          required
          value={form.password}
          onChange={set('password')}
          error={fieldErrors.password}
          hint="Au moins 10 caractères, avec des lettres et des chiffres."
        />
        <TextField
          label="Confirmer le mot de passe"
          type="password"
          name="password_confirmation"
          autoComplete="new-password"
          required
          value={form.password_confirmation}
          onChange={set('password_confirmation')}
        />
        <Button type="submit" loading={register.isPending} block>Créer mon compte</Button>
      </form>

      <p className="muted">Déjà inscrit ? <Link to="/login">Se connecter</Link></p>
    </section>
  );
}
