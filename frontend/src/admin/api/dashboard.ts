import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';

export type DailyPoint = { date: string; value: number };

export type PeriodPreset = 'today' | 'yesterday' | '7d' | '30d' | 'month' | 'last_month' | 'custom';
export type PeriodQuery = { period: PeriodPreset; from?: string; to?: string };

/** GET /admin/dashboard : chaque section n'est présente que si le rôle peut la consulter. */
export type DashboardSummary = {
  generated_at: string;
  currency: string;
  period: { preset: PeriodPreset; from: string; to: string; days: number; timezone: string };
  users?: {
    total: number;
    active: number;
    suspended: number;
    excluded: number;
    new_today: number;
    new_in_period: number;
    registrations: DailyPoint[];
  };
  betting?: {
    tickets_placed: number;
    stakes_minor: number;
    tickets_settled: number;
    payouts_minor: number;
    refunds_minor: number;
    pending_count: number;
    pending_exposure_minor: number;
    status_breakdown: { status: 'pending' | 'won' | 'lost' | 'void'; count: number }[];
    stakes_by_sport: { sport: string; name: string; tickets: number; stakes_minor: number }[];
    tickets: DailyPoint[];
    stakes_daily_minor: DailyPoint[];
  };
  finance?: {
    settled_stakes_minor: number;
    payouts_minor: number;
    gross_gaming_revenue_minor: number;
    pending_corrections: number;
    payments_available: boolean;
  };
  catalogue?: { events_live: number; events_scheduled: number; events_next_24h: number; markets_open: number; markets_suspended: number };
};

export type AdminAlert = {
  key: string;
  severity: 'critical' | 'warning' | 'info';
  category: string;
  title: string;
  detail: string;
  link: string | null;
};

export type AuditEntry = {
  id: string;
  action: string;
  actor: { id: string; email: string | null } | null;
  subject: { type: string; id: string } | null;
  reason: string | null;
  created_at: string;
};

export function useDashboard(period: PeriodQuery) {
  return useQuery<DashboardSummary>({
    queryKey: ['admin', 'dashboard', period],
    queryFn: ({ signal }) => api<{ data: DashboardSummary }>('/admin/dashboard', { signal, query: period }).then((r) => r.data),
    placeholderData: keepPreviousData,
    // Indicateurs « vivants » : rafraîchis toutes les minutes tant que la page est ouverte.
    refetchInterval: 60_000,
  });
}

/** Points d'attention : partagés par la barre du haut (cloche) et la vue d'ensemble. */
export function useAlerts() {
  return useQuery<AdminAlert[]>({
    queryKey: ['admin', 'alerts'],
    queryFn: ({ signal }) => api<{ data: AdminAlert[] }>('/admin/alerts', { signal }).then((r) => r.data),
    refetchInterval: 60_000,
  });
}
