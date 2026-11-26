import { useId, useRef, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError } from '../../../shared/api/client';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { TextField } from '../../../shared/components/TextField';
import { useRegister, type RegisterInput } from '../api/auth';
import { LEGAL_VERSION } from '../../legal/legal';
import { AuthShell } from '../components/AuthShell';
import { BIRTH_DATE_FORMAT, checkBirthDate, maskBirthDate } from '../birthDate';
import { DEFAULT_COUNTRY } from '../countries';
import { digitsOnly } from '../phone';

type Form = Omit<RegisterInput, 'terms_accepted' | 'terms_version' | 'referral_code'>;

/** Code parrain saisi ou reçu par lien : lettres et chiffres en majuscules, 16 caractères au plus. */
const cleanCode = (value: string) => value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 16);

/** Politique du serveur (au moins 10 caractères, des lettres et des chiffres), affichée en direct pendant la saisie. */
const PASSWORD_RULES = [
  { label: '10 caractères', test: (v: string) => v.length >= 10 },
  { label: 'Des lettres', test: (v: string) => /\p{L}/u.test(v) },
  { label: 'Des chiffres', test: (v: string) => /\d/.test(v) },
];

const empty: Form = {
  name: '',
  phone: '',
  email: '',
  password: '',
  password_confirmation: '',
  birth_date: '',
  country: DEFAULT_COUNTRY, // site conçu pour Haïti : pas de choix du pays à l'inscription
};

export function RegisterPage() {
  const register = useRegister();
  const navigate = useNavigate();
  const [form, setForm] = useState<Form>(empty);
  // Lien de parrainage : …/register?parrain=CODE préremplit le code (le joueur peut le corriger).
  const [params] = useSearchParams();
  const [referralCode, setReferralCode] = useState(() => cleanCode(params.get('parrain') ?? ''));
  // Le champ du code parrain reste replié tant que le joueur n'en a pas (lien ou clic sur « J'ai un code parrain »).
  const [showReferral, setShowReferral] = useState(() => referralCode !== '');
  // Bêta fermée (V2-F07) : code d'invitation reçu par lien (?invitation=CODE) ; le champ s'affiche aussi dès que le
  // serveur répond qu'un code est exigé.
  const [inviteCode, setInviteCode] = useState(() => cleanCode(params.get('invitation') ?? ''));
  const [accepted, setAccepted] = useState(false);
  const [mustAccept, setMustAccept] = useState(false);
  // Date tapée en JJ/MM/AAAA : vérifiée en quittant le champ et à l'envoi, l'erreur s'efface dès qu'on la corrige.
  const [birthError, setBirthError] = useState<string>();
  const birthRef = useRef<HTMLInputElement>(null);
  const termsId = useId();

  const error = register.error instanceof ApiError ? register.error : null;
  const fieldErrors = error?.fieldErrors ?? {};
  const set = (field: keyof Form) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value });
  const showInvite = params.has('invitation') || inviteCode !== '' || fieldErrors.invite_code !== undefined;
  const confirmMismatch = form.password_confirmation !== '' && form.password_confirmation !== form.password;

  function submit(e: FormEvent) {
    e.preventDefault();
    // Date impossible (31/02), dans le futur ou âge insuffisant, ou case non cochée (le serveur la refuserait aussi) :
    // rien n'est envoyé et les deux messages s'affichent ensemble.
    const birth = checkBirthDate(form.birth_date);
    if (!birth.ok) setBirthError(birth.message);
    if (!accepted) setMustAccept(true);
    if (!birth.ok) {
      birthRef.current?.focus();
      return;
    }
    if (!accepted) return;
    // Un email vide n'est pas envoyé : il reste facultatif côté serveur. La date part au format AAAA-MM-JJ de l'API.
    const { email, ...rest } = { ...form, birth_date: birth.iso };
    const terms = { terms_accepted: true as const, terms_version: LEGAL_VERSION };
    // Le serveur revérifie l'âge minimum et applique la politique de mot de passe.
    const referral = referralCode ? { referral_code: referralCode } : {};
    const invite = inviteCode ? { invite_code: inviteCode } : {};
    register.mutate({ ...rest, ...(email ? { email } : {}), ...terms, ...referral, ...invite }, { onSuccess: () => navigate('/account', { replace: true }) });
  }

  return (
    <AuthShell title="Créer un compte" subtitle="Votre numéro de téléphone sera votre identifiant." icon="user">
      {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}

      <form className="stack" onSubmit={submit} noValidate>
        <fieldset className="auth-section">
          <legend>Vos informations</legend>
          <TextField
            label="Nom"
            name="name"
            autoComplete="name"
            icon="user"
            placeholder="Prénom et nom"
            required
            value={form.name}
            onChange={set('name')}
            error={fieldErrors.name}
          />
          <TextField
            label="Numéro de téléphone"
            type="tel"
            inputMode="numeric"
            name="phone"
            autoComplete="tel-national"
            prefix="+509"
            placeholder="34 12 34 56"
            required
            value={form.phone}
            onChange={(e) => setForm({ ...form, phone: digitsOnly(e.target.value) })}
            error={fieldErrors.phone}
            hint="Vous l'utiliserez pour vous connecter."
          />
          <TextField
            ref={birthRef}
            label="Date de naissance"
            type="text"
            inputMode="numeric"
            name="birth_date"
            autoComplete="bday"
            icon="calendar"
            placeholder={BIRTH_DATE_FORMAT}
            maxLength={10}
            required
            value={form.birth_date}
            onChange={(e) => {
              setForm({ ...form, birth_date: maskBirthDate(e.target.value, form.birth_date) });
              setBirthError(undefined);
            }}
            onBlur={() => {
              // Champ vide en le quittant : pas de reproche tant que le joueur n'a pas essayé d'envoyer.
              if (form.birth_date !== '') {
                const birth = checkBirthDate(form.birth_date);
                setBirthError(birth.ok ? undefined : birth.message);
              }
            }}
            error={birthError ?? fieldErrors.birth_date}
            hint={`Ex. 21/05/1998. Vous devez être majeur pour ouvrir un compte.`}
          />
          <TextField
            label="Email (optionnel)"
            type="email"
            name="email"
            autoComplete="email"
            icon="mail"
            placeholder="vous@exemple.com"
            value={form.email ?? ''}
            onChange={set('email')}
            error={fieldErrors.email}
          />
        </fieldset>

        <fieldset className="auth-section">
          <legend>Sécurité</legend>
          <TextField
            label="Mot de passe"
            type="password"
            name="password"
            autoComplete="new-password"
            icon="lock"
            revealable
            placeholder="Choisissez un mot de passe"
            required
            value={form.password}
            onChange={set('password')}
            error={fieldErrors.password}
            hint={
              <span className="password-rules">
                {PASSWORD_RULES.map((r) => {
                  const ok = r.test(form.password);
                  return (
                    <span key={r.label} className={ok ? 'password-rule password-rule-ok' : 'password-rule'}>
                      <Icon name={ok ? 'check' : 'close'} size={12} />
                      {r.label}
                      <span className="sr-only">{ok ? ' : respecté' : ' : manquant'}</span>
                    </span>
                  );
                })}
              </span>
            }
          />
          <TextField
            label="Confirmer le mot de passe"
            type="password"
            name="password_confirmation"
            autoComplete="new-password"
            icon="lock"
            revealable
            placeholder="Retapez le mot de passe"
            required
            value={form.password_confirmation}
            onChange={set('password_confirmation')}
            hint={confirmMismatch ? 'Les deux mots de passe ne sont pas identiques.' : undefined}
          />
        </fieldset>

        {showInvite && (
          <TextField
            label="Code d'invitation"
            name="invite_code"
            autoComplete="off"
            icon="lock"
            placeholder="Code reçu"
            value={inviteCode}
            onChange={(e) => setInviteCode(cleanCode(e.target.value))}
            error={fieldErrors.invite_code}
            hint="Pendant la bêta, les inscriptions se font sur invitation."
          />
        )}
        {showReferral || fieldErrors.referral_code ? (
          <TextField
            label="Code parrain (facultatif)"
            name="referral_code"
            autoComplete="off"
            icon="gift"
            placeholder="Ex. ANA2026"
            value={referralCode}
            onChange={(e) => setReferralCode(cleanCode(e.target.value))}
            error={fieldErrors.referral_code}
            hint={referralCode && params.get('parrain') ? 'Vous avez été invité par un joueur REYEL 16 PARYAJ.' : undefined}
          />
        ) : (
          <button type="button" className="auth-link-btn" onClick={() => setShowReferral(true)}>
            <Icon name="gift" size={16} /> J'ai un code parrain
          </button>
        )}

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
        J'accepte les <Link to="/conditions" {...newTab}>conditions d'utilisation</Link> et
        le <Link to="/reglement" {...newTab}>règlement des paris</Link>, et j'ai lu
        la <Link to="/confidentialite" {...newTab}>politique de confidentialité</Link>.
      </label>
      {error && <small id={`${id}-error`} className="field-error" role="alert">{error}</small>}
    </div>
  );
}
