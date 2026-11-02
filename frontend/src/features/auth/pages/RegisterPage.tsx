import { useId, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { SelectField } from '../../../shared/components/SelectField';
import { TextField } from '../../../shared/components/TextField';
import { useRegister, type RegisterInput } from '../api/auth';
import { LEGAL_VERSION } from '../../legal/legal';
import { AuthShell } from '../components/AuthShell';
import { countryOptions, DEFAULT_COUNTRY } from '../countries';
import { digitsOnly } from '../phone';

const COUNTRIES = countryOptions().map((c) => ({ value: c.code, label: c.name }));

type Form = Omit<RegisterInput, 'terms_accepted' | 'terms_version'>;

const empty: Form = {
  name: '',
  phone: '',
  email: '',
  password: '',
  password_confirmation: '',
  birth_date: '',
  country: DEFAULT_COUNTRY,
};

export function RegisterPage() {
  const register = useRegister();
  const navigate = useNavigate();
  const [form, setForm] = useState<Form>(empty);
  const [accepted, setAccepted] = useState(false);
  const [mustAccept, setMustAccept] = useState(false);
  const termsId = useId();

  const error = register.error instanceof ApiError ? register.error : null;
  const fieldErrors = error?.fieldErrors ?? {};
  const set = (field: keyof Form) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value });

  function submit(e: FormEvent) {
    e.preventDefault();
    // Sans la case cochée, rien n'est envoyé : le serveur la refuserait aussi (terms_accepted).
    if (!accepted) {
      setMustAccept(true);
      return;
    }
    // Un email vide n'est pas envoyé : il reste facultatif côté serveur.
    const { email, ...rest } = form;
    const terms = { terms_accepted: true as const, terms_version: LEGAL_VERSION };
    // L'âge minimum et la politique de mot de passe sont vérifiés par le serveur.
    register.mutate({ ...(email ? form : rest), ...terms }, { onSuccess: () => navigate('/account', { replace: true }) });
  }

  return (
    <AuthShell title="Créer un compte" subtitle="Rejoignez REYEL 16 PARYAJ en quelques secondes." icon="user">
      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <form className="stack" onSubmit={submit} noValidate>
        <TextField
          label="Nom"
          labelHidden
          name="name"
          autoComplete="name"
          icon="user"
          placeholder="Nom complet"
          required
          value={form.name}
          onChange={set('name')}
          error={fieldErrors.name}
        />
        <TextField
          label="Numéro de téléphone"
          labelHidden
          type="tel"
          inputMode="numeric"
          name="phone"
          autoComplete="tel-national"
          prefix="+509"
          maxLength={8}
          placeholder="34 12 34 56"
          required
          value={form.phone}
          onChange={(e) => setForm({ ...form, phone: digitsOnly(e.target.value) })}
          error={fieldErrors.phone}
          hint="Sert à vous connecter : votre numéro local, sans l'indicatif."
        />
        <TextField
          label="Email (optionnel)"
          labelHidden
          type="email"
          name="email"
          autoComplete="email"
          icon="mail"
          placeholder="Email (optionnel)"
          value={form.email ?? ''}
          onChange={set('email')}
          error={fieldErrors.email}
        />
        <TextField
          label="Date de naissance"
          type="date"
          name="birth_date"
          autoComplete="bday"
          icon="calendar"
          required
          value={form.birth_date}
          onChange={set('birth_date')}
          error={fieldErrors.birth_date}
          hint="Vous devez être majeur pour ouvrir un compte."
        />
        <SelectField
          label="Pays"
          name="country"
          autoComplete="country"
          icon="flag"
          options={COUNTRIES}
          value={form.country ?? DEFAULT_COUNTRY}
          onChange={set('country')}
          error={fieldErrors.country}
        />
        <TextField
          label="Mot de passe"
          labelHidden
          type="password"
          name="password"
          autoComplete="new-password"
          icon="lock"
          placeholder="Mot de passe"
          required
          value={form.password}
          onChange={set('password')}
          error={fieldErrors.password}
          hint="Au moins 10 caractères, avec des lettres et des chiffres."
        />
        <TextField
          label="Confirmer le mot de passe"
          labelHidden
          type="password"
          name="password_confirmation"
          autoComplete="new-password"
          icon="lock"
          placeholder="Confirmer le mot de passe"
          required
          value={form.password_confirmation}
          onChange={set('password_confirmation')}
        />
        <TermsCheckbox
          id={termsId}
          checked={accepted}
          onChange={(checked) => { setAccepted(checked); if (checked) setMustAccept(false); }}
          error={mustAccept ? "Cochez la case pour accepter les conditions et créer votre compte." : fieldErrors.terms_accepted ?? fieldErrors.terms_version}
        />
        <Button type="submit" loading={register.isPending} block>Créer mon compte</Button>
      </form>

      <p className="auth-foot">Déjà inscrit ? <Link to="/login">Se connecter</Link></p>
    </AuthShell>
  );
}

type TermsProps = { id: string; checked: boolean; onChange: (checked: boolean) => void; error?: string };

/** Acceptation des textes d'information ; les liens s'ouvrent dans un nouvel onglet pour garder le formulaire rempli. */
function TermsCheckbox({ id, checked, onChange, error }: TermsProps) {
  const newTab = { target: '_blank', rel: 'noopener noreferrer' } as const;

  return (
    <div className={['terms-field', error && 'field-invalid'].filter(Boolean).join(' ')}>
      <input
        id={id}
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
      />
      <label htmlFor={id}>
        J'ai 18 ans ou plus. J'accepte les <Link to="/conditions" {...newTab}>conditions d'utilisation</Link> et
        le <Link to="/reglement" {...newTab}>règlement des paris</Link>, et j'ai lu
        la <Link to="/confidentialite" {...newTab}>politique de confidentialité</Link>.
      </label>
      {error && <small id={`${id}-error`} className="field-error" role="alert">{error}</small>}
    </div>
  );
}
