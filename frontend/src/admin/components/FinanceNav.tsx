import { NavLink } from 'react-router-dom';

const LINKS = [
  { to: '/admin/finance', label: 'Grand livre', end: true },
  { to: '/admin/finance/reconciliation', label: 'Rapprochement', end: false },
  { to: '/admin/finance/corrections', label: 'Corrections', end: false },
];

/** Sous-navigation de la section Finance. */
export function FinanceNav() {
  return (
    <nav className="subnav" aria-label="Finance">
      {LINKS.map((l) => <NavLink key={l.to} to={l.to} end={l.end}>{l.label}</NavLink>)}
    </nav>
  );
}
