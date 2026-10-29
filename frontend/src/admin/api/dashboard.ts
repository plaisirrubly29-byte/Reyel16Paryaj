import { useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';

export type DailyPoint = { date: string; value: number };

/** GET /admin/dashboard : chaque section n'est présente que si le rôle peut la consulter. */
export type DashboardSummary = {
  generated_at: string;
  currency: string;
  users?: { total: number; active: number; suspended: number; excluded: number; new_7d: number; registrations: DailyPoint[] };
  betting?: {
    tickets_today: number;
    stake_today_minor: number;
    pending_count: number;
    pending_exposure_minor: number;
    status_breakdown: { status: 'pending' | 'won' | 'lost' | 'void'; count: number }[];
    tickets: DailyPoint[];
    stakes_minor: DailyPoint[];
  };
  finance?: {
    period_days: number;
    settled_stakes_minor: number;
    payouts_minor: number;
    gross_gaming_revenue_minor: number;
    pending_corrections: number;
  };
  catalogue?: { events_live: number; events_next_24h: number; markets_open: number; markets_suspended: number };
};

export type AuditEntry = {
  id: string;
  action: string;
  actor: { id: string; email: string | null } | null;
  subject: { type: string; id: string } | null;
  reason: string | null;
  created_at: string;
};

export function useDashboard() {
  return useQuery({
    queryKey: ['admin', 'dashboard'],
    queryFn: ({ signal }) => api<{ data: DashboardSummary }>('/admin/dashboard', { signal }).then((r) => r.data),
    // Indicateurs « vivants » : rafraîchis toutes les minutes tant que la page est ouverte.
    refetchInterval: 60_000,
  });
}
