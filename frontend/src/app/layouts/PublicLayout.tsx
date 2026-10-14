import { NavLink, Outlet } from 'react-router-dom';

export function PublicLayout() {
  return (
    <div className="shell">
      <header className="topbar">
        <NavLink to="/" className="brand">REYEL 16 PAYAJ</NavLink>
        <nav>
          <NavLink to="/sports">Sports</NavLink>
          <NavLink to="/bets">Mes tickets</NavLink>
          <NavLink to="/wallet">Portefeuille</NavLink>
          <NavLink to="/login">Connexion</NavLink>
        </nav>
      </header>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
