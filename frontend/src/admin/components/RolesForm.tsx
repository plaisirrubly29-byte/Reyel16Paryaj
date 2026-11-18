import { useState } from 'react';
import { Alert } from '../../shared/components/Alert';
import { useMe } from '../../features/auth/api/auth';
import { useSetUserRoles, type AdminUser } from '../api/users';
import { ADMIN_ROLES } from '../roles';
import { ReasonForm } from './ReasonForm';

const same = (a: string[], b: string[]) => a.length === b.length && a.every((r) => b.includes(r));

/**
 * Rôles administratifs d'un compte (permission roles.manage : Super Admin), motif obligatoire, audité. Jamais sur son
 * propre compte (D-07) : le bloc est caché, et le serveur refuse de toute façon (cannot_modify_own_roles).
 * Les rôles actuels ne sont connus qu'avec users.view_personal_data (le Super Admin l'a).
 */
export function RolesForm({ user }: { user: AdminUser }) {
  const { data: me } = useMe();
  const setRoles = useSetUserRoles(user.id);
  const current = user.roles ?? [];
  const [selected, setSelected] = useState<string[]>(current);

  if (!me?.permissions.includes('roles.manage') || user.roles === undefined || me.id === user.id) return null;

  const toggle = (slug: string) => setSelected(selected.includes(slug) ? selected.filter((r) => r !== slug) : [...selected, slug]);
  const becomesAdmin = current.length === 0 && selected.length > 0;
  const added = selected.filter((r) => !current.includes(r));

  return (
    <section className="admin-card stack">
      <ReasonForm
        id="roles"
        title="Rôles administratifs"
        description="Chaque rôle donne les permissions de la matrice (docs/permissions-matrix.md). Un rôle donné depuis moins de 24 h ne permet pas de valider une demande d'un autre administrateur."
        submitLabel="Enregistrer les rôles"
        incomplete={same(selected, current)}
        successMessage="Rôles enregistrés. Chaque attribution ou retrait est signalé à tous les Super Admins."
        onSubmit={(reason, idempotencyKey) => setRoles.mutateAsync({ roles: ADMIN_ROLES.map((r) => r.slug).filter((s) => selected.includes(s)), reason, idempotencyKey })}
      >
        <fieldset className="roles-list">
          <legend className="sr-only">Rôles</legend>
          {ADMIN_ROLES.map((role) => (
            <div key={role.slug} className="role-option">
              <input id={`role-${role.slug}`} type="checkbox" checked={selected.includes(role.slug)} onChange={() => toggle(role.slug)} aria-describedby={`role-${role.slug}-help`} />
              <label htmlFor={`role-${role.slug}`}>
                <strong>{role.label}</strong>
                <small id={`role-${role.slug}-help`} className="muted">{role.description}</small>
              </label>
            </div>
          ))}
        </fieldset>
        {becomesAdmin && (
          <Alert tone="warning">
            Ce compte devient administrateur : il devra activer la double authentification à sa prochaine connexion.
          </Alert>
        )}
        {added.includes('super_admin') && (
          <Alert tone="warning">Super Admin donne tous les droits, dont la gestion des rôles des autres administrateurs.</Alert>
        )}
      </ReasonForm>
    </section>
  );
}
