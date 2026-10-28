import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ApiError } from '../api/client';
import { Button } from './Button';
import { DataTable } from './DataTable';
import { ErrorAlert } from './ErrorAlert';
import { TextField } from './TextField';

describe('Button', () => {
  it('est désactivé pendant le chargement (pas de double soumission)', async () => {
    const onClick = vi.fn();
    render(<Button loading onClick={onClick}>Valider</Button>);

    await userEvent.click(screen.getByRole('button', { name: /valider/i }));

    expect(screen.getByRole('button', { name: /valider/i })).toBeDisabled();
    expect(onClick).not.toHaveBeenCalled();
  });
});

describe('TextField', () => {
  it("relie le message d'erreur au champ", () => {
    render(<TextField label="Email" name="email" error="Email obligatoire." />);

    const input = screen.getByLabelText('Email');
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription('Email obligatoire.');
  });
});

describe('ErrorAlert', () => {
  it("affiche le message de l'API sans proposer de réessayer une erreur 4xx", () => {
    const error = new ApiError(403, { error: { code: 'forbidden', message: 'Action non autorisée.' } });
    render(<ErrorAlert error={error} onRetry={() => {}} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Accès refusé');
    expect(screen.getByRole('alert')).toHaveTextContent('Action non autorisée.');
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('propose de réessayer après une panne réseau', async () => {
    const onRetry = vi.fn();
    render(<ErrorAlert error={new ApiError(0, null)} onRetry={onRetry} />);

    await userEvent.click(screen.getByRole('button', { name: 'Réessayer' }));

    expect(onRetry).toHaveBeenCalledOnce();
  });

  it("n'affiche rien sans erreur", () => {
    const { container } = render(<ErrorAlert error={null} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('DataTable', () => {
  const columns = [
    { key: 'name', header: 'Nom', render: (r: { id: string; name: string }) => r.name },
  ];

  it('affiche les lignes avec un libellé par cellule (vue mobile)', () => {
    render(<DataTable columns={columns} rows={[{ id: '1', name: 'Ana' }]} rowKey={(r) => r.id} />);

    expect(screen.getByRole('cell', { name: 'Ana' })).toHaveAttribute('data-label', 'Nom');
  });

  it('affiche un état vide', () => {
    render(<DataTable columns={columns} rows={[]} rowKey={(r) => r.id} empty="Aucun ticket." />);

    expect(screen.getByText('Aucun ticket.')).toBeInTheDocument();
  });
});
