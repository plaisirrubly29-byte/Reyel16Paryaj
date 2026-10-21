import { useNavigate } from 'react-router-dom';
import { Button } from '../../../shared/components/Button';
import { useLogout, useMe } from '../api/auth';

/** Profil du joueur connecté (page protégée par RequireAuth). */
export function AccountPage() {
  const { data: user } = useMe();
  const logout = useLogout();
  const navigate = useNavigate();

  if (!user) return null;

  const fullName = [user.profile?.first_name, user.profile?.last_name].filter(Boolean).join(' ');

  return (
    <section className="stack">
      <h1>Mon compte</h1>

      <dl className="details">
        <dt>Nom</dt>
        <dd>{fullName || user.name}</dd>
        <dt>Téléphone</dt>
        <dd>+509 {user.phone}</dd>
        <dt>Email</dt>
        <dd>{user.email ?? '—'}</dd>
        <dt>Date de naissance</dt>
        <dd>{user.profile?.birth_date ? new Date(`${user.profile.birth_date}T00:00:00`).toLocaleDateString('fr-FR') : '—'}</dd>
        <dt>Pays</dt>
        <dd>{user.profile?.country ?? '—'}</dd>
        <dt>Membre depuis</dt>
        <dd>{new Date(user.created_at).toLocaleDateString('fr-FR')}</dd>
      </dl>

      <div>
        <Button
          variant="secondary"
          loading={logout.isPending}
          // On quitte d'abord la page protégée : sinon elle redirigerait vers /login dès la fin de la session.
          onClick={() => { navigate('/', { replace: true }); logout.mutate(); }}
        >
          Se déconnecter
        </Button>
      </div>
    </section>
  );
}
