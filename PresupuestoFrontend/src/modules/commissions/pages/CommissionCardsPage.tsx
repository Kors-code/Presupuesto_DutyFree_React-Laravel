// src/pages/commissions/CommissionCardsPage.tsx
import React, { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

type TicketMetrics = {
  tickets_count: number;
  avg_ticket_usd?: number | null;
  avg_ticket_cop?: number | null;
  avg_units_per_ticket?: number | null;
  max_ticket_usd?: number | null;
  min_ticket_usd?: number | null;
};

type SellerRow = {
  user_id: number;
  seller: string;
  assignedTurns: number;
  total_commission_cop: number;
  total_commission_usd: number | null;
  total_sales_cop: number;
  total_sales_usd?: number | null;
  avg_trm?: number | null;
  tickets?: TicketMetrics;
};

type CategoryRow = {
  classification: string;
  participation_pct?: number;
  category_budget_usd?: number;
  sales_usd?: number;
  sales_cop?: number;
  pct_of_category?: number | null;
  qualifies?: boolean;
  applied_commission_pct?: number;
  projected_commission_usd?: number | null;
  commission_cop?: number;
  commission_usd?: number | null;
};

function StatBox({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="min-w-[12rem] flex-shrink-0 bg-gray-900 text-white rounded-lg p-3 shadow-md">
      <div className="text-xxs text-gray-300">{label}</div>
      <div className="text-xl font-semibold">{value}</div>
      {sub && <div className="text-xs text-gray-400 mt-1">{sub}</div>}
    </div>
  );
}

/**
 * CommissionCardsPage
 *
 * - Sidebar left selectable (multi) budgets
 * - Main content: cards / table / tickets
 * - Modal integrated: responsive, slide-in on mobile, centered on desktop
 */
export default function CommissionCardsPage() {
  const [budgetProgress, setBudgetProgress] = useState<any>(null);
  const [categoriesSummaryGlobal, setCategoriesSummaryGlobal] = useState<CategoryRow[]>([]);
  const [rows, setRows] = useState<SellerRow[]>([]);
  const [loading, setLoading] = useState(true);

  // selected seller id for modal
  const [selectedSellerId, setSelectedSellerId] = useState<number | null>(null);

  // modal state & internal data
  const [modalOpen, setModalOpen] = useState(false);
  const [sellerDetail, setSellerDetail] = useState<any>(null);
  const [sellerLoading, setSellerLoading] = useState(false);
  const [sellerError, setSellerError] = useState<string | null>(null);
  const [detailTab, setDetailTab] = useState<'overview' | 'tickets' | 'categories' | 'sales'>('overview');

  // viewMode admite 'cards' | 'table' | 'tickets'
  const [viewMode, setViewMode] = useState<'cards' | 'table' | 'tickets'>('cards');
  const [sortBy, setSortBy] = useState<'sales_cop' | 'sales_usd' | 'commission_cop' | 'name'>('sales_cop');
  const [query, setQuery] = useState<string>('');
  const [showCategories, setShowCategories] = useState<boolean>(false); // oculto por defecto

  // budgets & selection (supports multiple)
  const [budgetIds, setBudgetIds] = useState<number[]>([]);
  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetFilter, setBudgetFilter] = useState<string>('');

  // Tickets summary global
  const [ticketsSummary, setTicketsSummary] = useState<any>(null);
  const avgUnitsPerTicket = ticketsSummary?.avg_units_per_ticket;
  const [turnsSummary, setTurnsSummary] = useState<any>(null);

  // helper: build query params for budget_ids[]
  const buildBudgetParams = (ids: number[]) => {
    const params = new URLSearchParams();
    ids.forEach(id => params.append('budget_ids[]', String(id)));
    return params.toString();
  };

  // initial budgets load
  useEffect(() => {
    let mounted = true;
    api.get('/budgets')
      .then(res => {
        if (!mounted) return;
        const data = res.data || [];
        const arr = Array.isArray(data) ? data : [];
        setBudgets(arr);
        if (arr.length > 0 && budgetIds.length === 0) {
          setBudgetIds([arr[0].id]); // select first by default
        }
      })
      .catch(err => {
        console.error('Error loading budgets', err);
      });
    return () => { mounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // auto-load when budgetIds changes
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [budgetIds]);

  const load = async () => {
    setLoading(true);
    try {
      if (!budgetIds || budgetIds.length === 0) {
        // clear
        setRows([]);
        setBudgetProgress(null);
        setCategoriesSummaryGlobal([]);
        setTicketsSummary(null);
        setTurnsSummary(null);
        setLoading(false);
        return;
      }

      const q = buildBudgetParams(budgetIds);
      const url = `/commissions/by-seller?${q}`;
      const res = await api.get(url);

      if (res.data?.active) {
        const sellers = res.data.sellers ?? [];
        setRows(Array.isArray(sellers) ? sellers : []);
        setBudgetProgress(res.data.progress ?? {});
        setCategoriesSummaryGlobal(res.data.categories_summary ?? []);
        setTicketsSummary(res.data.tickets_summary ?? null);
        setTurnsSummary(res.data.turns ?? null);
      } else {
        setRows([]);
        setBudgetProgress(null);
        setCategoriesSummaryGlobal([]);
        setTicketsSummary(null);
      }
    } catch (e) {
      console.error('load commissions error', e);
      setRows([]);
      setBudgetProgress(null);
      setCategoriesSummaryGlobal([]);
      setTicketsSummary(null);
      setTurnsSummary(null);
    } finally {
      setLoading(false);
    }
  };

  const onGenerate = async () => {
    if (!budgetIds || budgetIds.length === 0) {
      alert('Selecciona al menos un presupuesto antes de generar comisiones.');
      return;
    }
    if (!confirm(`¿Deseas generar/actualizar las comisiones para ${budgetIds.length} presupuesto(s) seleccionado(s)?`)) return;

    try {
      // For each selected budget call the generate endpoint (backend expects single budget_id)
      const promises = budgetIds.map(id => api.post(`commissions/generate?budget_id=${id}`));
      const results = await Promise.allSettled(promises);

      // summarize results
      let created = 0, updated = 0, errors = 0;
      results.forEach(r => {
        if (r.status === 'fulfilled') {
          const d = r.value.data;
          if (d?.created) created += Number(d.created) || 0;
          if (d?.updated) updated += Number(d.updated) || 0;
        } else {
          errors++;
        }
      });

      alert(`Generación completada. creadas ${created} — actualizadas ${updated}${errors ? ` — errores en ${errors} partidas` : ''}`);
      await load();
    } catch (err) {
      console.error(err);
      alert('Error al generar comisiones');
    }
  };

  const moneyUSD = (v:number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(v || 0);
  const moneyCOP = (v:number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(v || 0);

  const avgTicketUsd = ticketsSummary?.avg_ticket_usd;
  const avgTicketCop = ticketsSummary?.avg_ticket_cop;

  const totalUsd = (budgetProgress?.total_usd ?? categoriesSummaryGlobal.reduce((s:any,c:any)=> s + Number(c.sales_usd || 0), 0));
  const pptoUsd = budgetProgress?.required_usd ?? (budgetProgress?.budget?.target_amount ?? 0);
  const commissionUsd = budgetProgress?.total_commission_usd;

  const totalCommissionCop = (budgetProgress?.total_commission_cop ?? rows.reduce((s,r)=> s + Number(r.total_commission_cop || 0), 0));
  const trm = (() => {
    if (budgetProgress?.trm) return budgetProgress.trm;
    const totalCop = categoriesSummaryGlobal.reduce((s:any,c:any) => s + Number(c.sales_cop || 0), 0);
    const totalUsdCats = categoriesSummaryGlobal.reduce((s:any,c:any) => s + Number(c.sales_usd || 0), 0);
    if (totalUsdCats > 0) return (totalCop / totalUsdCats).toFixed(2);
    const avgTrm = rows.reduce((acc,r)=> acc + (Number(r.avg_trm || 0)), 0);
    if (rows.length > 0) return (avgTrm / rows.length).toFixed(2);
    return '—';
  })();

  // filtered & sorted rows
  const displayedRows = useMemo(() => {
    const q = query.trim().toLowerCase();
    let list = rows.slice();
    if (q) list = list.filter(r => (r.seller || '').toLowerCase().includes(q));
    switch (sortBy) {
      case 'sales_usd': return list.sort((a,b) => (b.total_sales_usd||0) - (a.total_sales_usd||0));
      case 'commission_cop': return list.sort((a,b) => (b.total_commission_cop||0) - (a.total_commission_cop||0));
      case 'name': return list.sort((a,b) => (a.seller||'').localeCompare(b.seller||''));
      default: return list.sort((a,b) => (b.total_sales_cop||0) - (a.total_sales_cop||0));
    }
  }, [rows, sortBy, query]);

  // Excel export (supports multiple budgets)
  const downloadExcel = async () => {
    if (!budgetIds || budgetIds.length === 0) {
      alert('Selecciona al menos un presupuesto antes de exportar.');
      return;
    }
    try {
      const q = buildBudgetParams(budgetIds);
      const res = await api.get(`/commissions/export?${q}`, { responseType: 'blob' });
      const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `commissions_${budgetIds.join('_')}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      console.error('Error downloading Excel', err);
      alert('Error al descargar Excel');
    }
  };

  // budget sidebar helpers
  const toggleBudget = (id: number) => {
    setBudgetIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
  };
  const selectAll = () => setBudgetIds(budgets.map(b => b.id));
  const clearAll = () => setBudgetIds([]);
  const filteredBudgets = budgets.filter(b => {
    if (!budgetFilter) return true;
    const f = budgetFilter.toLowerCase();
    return String(b.name).toLowerCase().includes(f) ||
           String(b.start_date).includes(f) ||
           String(b.end_date).includes(f);
  });

  // ----- Seller detail modal logic -----
  const openSellerModal = (userId: number) => {
    setSelectedSellerId(userId);
    setModalOpen(true);
    setDetailTab('overview');
  };

  useEffect(() => {
    let mounted = true;
    const fetchDetail = async () => {
      if (!modalOpen || !selectedSellerId) return;
      setSellerLoading(true);
      setSellerError(null);
      setSellerDetail(null);
      try {
        // backend: bySellerDetail(Request $request, $userId) -> route e.g. /commissions/by-seller-detail/{userId}?budget_id=X
        const budgetIdForDetail = budgetIds && budgetIds.length > 0 ? budgetIds[0] : undefined;
        const q = budgetIdForDetail ? `?budget_id=${budgetIdForDetail}` : '';
        const res = await api.get(`/commissions/by-seller/${selectedSellerId}${q}`);
        if (!mounted) return;
        setSellerDetail(res.data);
      } catch (err: any) {
        console.error('Error loading seller detail', err);
        setSellerError(err?.response?.data?.message || 'Error cargando detalle');
      } finally {
        if (mounted) setSellerLoading(false);
      }
    };
    fetchDetail();
    return () => { mounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [modalOpen, selectedSellerId, budgetIds]);

  const closeModal = () => {
    setModalOpen(false);
    setSelectedSellerId(null);
    setSellerDetail(null);
    setSellerError(null);
  };

  const exportSellerDetail = async () => {
    if (!selectedSellerId) return;
    try {
      const budgetIdForDetail = budgetIds && budgetIds.length > 0 ? budgetIds[0] : undefined;
      const q = budgetIdForDetail ? `?budget_id=${budgetIdForDetail}` : '';
      const res = await api.get(
      `/commissions/by-seller/${selectedSellerId}/export${q}`,
      { responseType: 'blob' }
    );

      const blob = new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `commission_detail_user_${selectedSellerId}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      console.error('Error exporting seller detail', err);
      alert('Error al exportar detalle');
    }
  };

  // ----- UI -----
  return (
    <div className="p-4 sm:p-6 max-w-7xl mx-auto">
      <div className="flex gap-6">
        {/* LEFT SIDEBAR: budgets */}
        <aside className="w-72 hidden lg:block">
          <div className="bg-white rounded-lg shadow p-4 sticky top-6">
            <div className="flex items-center justify-between mb-3">
              <h4 className="text-sm font-semibold">Presupuestos</h4>
              <div className="text-xs text-gray-400">{budgetIds.length} seleccionados</div>
            </div>

            <div className="mb-3">
              <input
                placeholder="Filtrar presupuestos (mes/año/nombre)"
                value={budgetFilter}
                onChange={e => setBudgetFilter(e.target.value)}
                className="w-full border rounded px-3 py-2 text-sm"
              />
            </div>

            <div className="flex gap-2 mb-3">
              <button onClick={selectAll} className="flex-1 text-xs px-2 py-1 bg-indigo-600 text-white rounded">Todos</button>
              <button onClick={clearAll} className="flex-1 text-xs px-2 py-1 bg-gray-100 rounded">Ninguno</button>
            </div>

            <div className="max-h-[48vh] overflow-auto -mx-2 px-2">
              {filteredBudgets.length === 0 ? (
                <div className="text-xs text-gray-500 p-2">No hay presupuestos</div>
              ) : (
                filteredBudgets.map(b => (
                  <label key={b.id} className="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={budgetIds.includes(b.id)}
                      onChange={() => toggleBudget(b.id)}
                      className="w-4 h-4"
                    />
                    <div className="text-sm">
                      <div className="font-medium">{b.name}</div>
                      <div className="text-xs text-gray-500">{b.start_date} → {b.end_date}</div>
                    </div>
                  </label>
                ))
              )}
            </div>

            <div className="mt-3 space-y-2">
              <button onClick={load} className="w-full text-sm px-3 py-2 bg-indigo-600 text-white rounded">Cargar selección</button>
              <button onClick={onGenerate} className="w-full text-sm px-3 py-2 bg-green-600 text-white rounded">Generar comisiones</button>
              <button onClick={downloadExcel} className="w-full text-sm px-3 py-2 bg-blue-600 text-white rounded">Exportar Excel</button>
            </div>
          </div>
        </aside>

        {/* MAIN */}
        <main className="flex-1">
          {/* Mobile: multi-select budgets */}
          <div className="lg:hidden mb-4">
            <select
              multiple
              value={budgetIds.map(String)}
              onChange={(e) => {
                const selected = Array.from(e.target.selectedOptions).map(o => Number(o.value));
                setBudgetIds(selected);
              }}
              className="w-full border rounded px-3 py-2 text-sm h-36"
            >
              {budgets.map(b => (
                <option key={b.id} value={b.id}>{b.name} — {b.start_date} → {b.end_date}</option>
              ))}
            </select>
            <div className="flex gap-2 mt-2">
              <button onClick={load} className="flex-1 px-3 py-2 bg-indigo-600 text-white rounded">Cargar</button>
              <button onClick={onGenerate} className="flex-1 px-3 py-2 bg-green-600 text-white rounded">Generar</button>
              <button onClick={downloadExcel} className="flex-1 px-3 py-2 bg-blue-600 text-white rounded">Exportar</button>
            </div>
          </div>

          {/* STATS */}
          <div className="mb-4">
            <div className="flex gap-3 overflow-x-auto pb-2">
              {turnsSummary && (
                <StatBox
                  label="Turnos"
                  value={`${turnsSummary.assigned_total} / ${turnsSummary.total}`}
                  sub={`Disponibles: ${turnsSummary.remaining}`}
                />
              )}
              <StatBox label="Ticket promedio (USD)" value={avgTicketUsd ? moneyUSD(avgTicketUsd) : '—'} sub="Promedio por factura" />
              <StatBox label="Ítems por ticket" value={typeof avgUnitsPerTicket === 'number' ? avgUnitsPerTicket.toFixed(2) : '—'} sub="Unidades avg" />
              <StatBox label="Ventas USD" value={moneyUSD(Number(totalUsd || 0))} sub={`PPTO: ${moneyUSD(Number(pptoUsd || 0))}`} />
              <StatBox label="Comisiones USD" value={ typeof commissionUsd === 'number'? moneyUSD(commissionUsd): '—'} />
              
              <div className="min-w-[12rem] flex-shrink-0 bg-white rounded-lg p-3 shadow-md flex flex-col justify-between">
                <div>
                  <div className="text-xxs text-gray-500">Comisiones COP</div>
                  <div className="text-lg font-semibold text-green-600">{moneyCOP(Number(totalCommissionCop || 0))}</div>
                  <div className="text-xs text-gray-400 mt-1">TRM {trm ?? '—'}</div>
                </div>
              </div>
            </div>
          </div>

          {/* TOOLBAR */}
          <div className="bg-white rounded-lg p-3 shadow mb-4">
            <div className="flex flex-col sm:flex-row sm:items-center gap-3">
              <div className="flex items-center gap-2 w-full sm:w-1/2">
                <input
                  placeholder="Buscar vendedor..."
                  value={query}
                  onChange={e=>setQuery(e.target.value)}
                  className="w-full border rounded px-3 py-2 text-sm"
                />
              </div>

              <div className="flex items-center gap-2 sm:ml-auto">
                <label className="text-xs text-gray-500 hidden sm:block">Ordenar</label>
                <select value={sortBy} onChange={e => setSortBy(e.target.value as any)} className="border rounded px-3 py-2 text-sm">
                  <option value="sales_cop">Ventas (COP)</option>
                  <option value="sales_usd">Ventas (USD)</option>
                  <option value="commission_cop">Comisión (COP)</option>
                  <option value="name">Nombre</option>
                </select>

                <div className="flex items-center gap-2 ml-2">
                  <button onClick={() => setViewMode('cards')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='cards' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Cards</button>
                  <button onClick={() => setViewMode('table')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='table' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Tabla</button>
                  <button onClick={() => setViewMode('tickets')} className={`px-3 py-2 rounded-md text-sm ${viewMode==='tickets' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>KPI´s</button>
                </div>

                <button onClick={() => setShowCategories(s => !s)} className="ml-2 px-3 py-2 bg-gray-50 rounded-md text-sm border">
                  {showCategories ? 'Ocultar categorías' : 'Mostrar categorías'}
                </button>
              </div>
            </div>
          </div>

          {/* CONTENT (kept as your original layout) */}
          {loading ? (
            <div className="p-6 text-center text-gray-600">Cargando…</div>
          ) : displayedRows.length === 0 ? (
            <div className="p-6 text-center text-gray-600">No hay datos</div>
          ) : viewMode === 'cards' ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {displayedRows.map(r => (
                <button key={r.user_id} onClick={() => openSellerModal(r.user_id)}
                  className="text-left bg-white shadow-md rounded-lg p-4 hover:shadow-xl transform hover:-translate-y-1 transition flex flex-col">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-semibold">
                        {r.seller ? r.seller.charAt(0).toUpperCase() : '?'}
                      </div>
                      <div>
                        <div className="text-xs text-gray-500">Vendedor</div>
                        <div className="text-lg font-semibold">{r.seller}</div>
                      </div>
                    </div>

                    <div className="text-right">
                      <div className="text-xs text-gray-500">Comisión (COP)</div>
                      <div className="text-xl font-bold text-green-600">{moneyCOP(r.total_commission_cop ?? 0)}</div>
                    </div>
                  </div>

                  <div className="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                    <div className="bg-gray-50 rounded p-2">
                      <div className="text-xxs text-gray-400">Turnos</div>
                      <div className="font-medium">{r.assignedTurns ?? 0}</div>
                    </div>
                    <div className="bg-gray-50 rounded p-2">
                      <div className="text-xxs text-gray-400">Ventas (COP)</div>
                      <div className="font-medium">{moneyCOP(Math.trunc(Number(r.total_sales_cop || 0)))}</div>
                    </div>
                    <div className="bg-gray-50 rounded p-2">
                      <div className="text-xxs text-gray-400">Ventas (USD)</div>
                      <div className="font-medium">{Number(r.total_sales_usd || 0).toFixed(2)} USD</div>
                    </div>
                  </div>

                  <div className="mt-3 text-sm text-gray-500 flex items-center justify-between">
                    <div>TRM avg: {Number(r.avg_trm || 0).toFixed(2)}</div>
                    <div className="text-xs text-gray-400">Toca para detalle</div>
                  </div>
                </button>
              ))}
            </div>
          ) : viewMode === 'tickets' ? (
            // Tickets view (kept similar)
            <div className="space-y-3">
              <div className="hidden sm:block bg-white rounded shadow overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-800 text-white">
                    <tr>
                      <th className="p-2 text-left">Vendedor</th>
                      <th className="p-2 text-right">Tickets</th>
                      <th className="p-2 text-right">Unidad avg</th>
                      <th className="p-2 text-right">Avg Ticket (USD)</th>
                      <th className="p-2 text-right">Avg Ticket (COP)</th>
                      <th className="p-2 text-right">Max (USD)</th>
                      <th className="p-2 text-right">Min (USD)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayedRows.map(r => (
                      <tr key={r.user_id} className="border-t hover:bg-gray-50 cursor-pointer" onClick={() => openSellerModal(r.user_id)}>
                        <td className="p-2">{r.seller}</td>
                        <td className="p-2 text-right">{r.tickets?.tickets_count ?? 0}</td>
                        <td className="p-2 text-right font-medium">{typeof r.tickets?.avg_units_per_ticket === 'number' ? r.tickets!.avg_units_per_ticket!.toFixed(2) : '—'}</td>
                        <td className="p-2 text-right">{typeof r.tickets?.avg_ticket_usd === 'number' ? moneyUSD(r.tickets!.avg_ticket_usd || 0) : '—'}</td>
                        <td className="p-2 text-right">{typeof r.tickets?.avg_ticket_cop === 'number' ? moneyCOP(r.tickets!.avg_ticket_cop || 0) : '—'}</td>
                        <td className="p-2 text-right">{typeof r.tickets?.max_ticket_usd === 'number' ? moneyUSD(r.tickets!.max_ticket_usd || 0) : '—'}</td>
                        <td className="p-2 text-right">{typeof r.tickets?.min_ticket_usd === 'number' ? moneyUSD(r.tickets!.min_ticket_usd || 0) : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : (
            // Table view
            <div className="space-y-3">
              <div className="hidden sm:block bg-white rounded shadow overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-800 text-white">
                    <tr>
                      <th className="p-2 text-left">Vendedor</th>
                      <th className="p-2 text-right">Turnos</th>
                      <th className="p-2 text-right">Ventas (COP)</th>
                      <th className="p-2 text-right">Ventas (USD)</th>
                      <th className="p-2 text-right">Comisión (USD)</th>
                      <th className="p-2 text-right">Comisión (COP)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayedRows.map(r => (
                      <tr key={r.user_id} className="border-t hover:bg-gray-50 cursor-pointer" onClick={() => openSellerModal(r.user_id)}>
                        <td className="p-2">{r.seller}</td>
                        <td className="p-2 text-right">{r.assignedTurns}</td>
                        <td className="p-2 text-right">{moneyCOP(r.total_sales_cop)}</td>
                        <td className="p-2 text-right">{Number(r.total_sales_usd || 0).toFixed(2)}</td>
                        <td className="p-2 text-right font-semibold text-green-600">{moneyUSD(r.total_commission_usd || 0)}</td>
                        <td className="p-2 text-right font-semibold text-green-600">{moneyCOP(r.total_commission_cop)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Modal: responsive, pretty */}
          {modalOpen && (
            <div
              role="dialog"
              aria-modal="true"
              className="fixed inset-0 z-50 flex items-end lg:items-center justify-center"
            >
              {/* overlay */}
              <div
                className="absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity"
                onClick={closeModal}
              />

              {/* modal container */}
              <div
                className={`
                  relative z-50 w-full lg:max-w-5xl
                  transform transition-all
                  ${/* mobile: full screen slide from bottom */ ''}
                  ${window.innerWidth < 1024 ? 'translate-y-0 rounded-t-xl' : 'rounded-lg'}
                  bg-white shadow-2xl mx-4 my-6
                  max-h-[90vh] overflow-auto
                `}
                style={{
                  // mobile full-height feel
                  height: window.innerWidth < 1024 ? '92vh' : undefined,
                }}
              >
                {/* HEADER */}
                <div className="flex items-center justify-between p-4 border-b sticky top-0 bg-white z-10">
                  <div>
                    <div className="text-sm text-gray-500">Detalle vendedor</div>
                    <div className="text-lg font-semibold">
                      {sellerLoading ? 'Cargando...' : (sellerDetail?.user?.name ?? (`Vendedor #${selectedSellerId}`))}
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <button
                      onClick={exportSellerDetail}
                      className="hidden sm:inline-flex items-center gap-2 px-3 py-2 bg-blue-600 text-white rounded-md text-sm shadow"
                    >
                      Exportar detalle
                    </button>

                    <button
                      onClick={closeModal}
                      aria-label="Cerrar"
                      className="p-2 rounded hover:bg-gray-100"
                    >
                      <svg className="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>
                </div>

                {/* BODY */}
                <div className="p-4 space-y-4">
                  {sellerLoading ? (
                    <div className="p-6 text-center text-gray-500">Cargando detalle…</div>
                  ) : sellerError ? (
                    <div className="p-4 bg-red-50 text-red-700 rounded">{sellerError}</div>
                  ) : sellerDetail ? (
                    <>
                      {/* KPI row */}
                      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div className="bg-gray-50 rounded p-3">
                          <div className="text-xs text-gray-500">Ventas (USD)</div>
                          <div className="text-xl font-semibold">{moneyUSD(sellerDetail.totals?.total_sales_usd ?? 0)}</div>
                          <div className="text-xs text-gray-400 mt-1">Tickets: {sellerDetail.tickets_summary?.tickets_count ?? 0}</div>
                        </div>
                        <div className="bg-gray-50 rounded p-3">
                          <div className="text-xs text-gray-500">Comisión (COP)</div>
                          <div className="text-xl font-semibold text-green-600">{moneyCOP(sellerDetail.totals?.total_commission_cop ?? 0)}</div>
                          <div className="text-xs text-gray-400 mt-1">TRM avg: {sellerDetail.totals?.avg_trm ?? '—'}</div>
                        </div>
                        <div className="bg-gray-50 rounded p-3">
                          <div className="text-xs text-gray-500">Presupuesto usuario (USD)</div>
                          <div className="text-xl font-semibold">{moneyUSD(sellerDetail.user_budget_usd ?? 0)}</div>
                          <div className="text-xs text-gray-400 mt-1">Turnos asignados: {sellerDetail.assigned_turns_for_user ?? 0}</div>
                        </div>
                      </div>

                      {/* TABS */}
                      <div>
                        <div className="flex gap-2 border-b">
                          <button
                            className={`px-3 py-2 -mb-px ${detailTab === 'overview' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-600'}`}
                            onClick={() => setDetailTab('overview')}
                          >
                            Resumen
                          </button>
                          <button
                            className={`px-3 py-2 -mb-px ${detailTab === 'tickets' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-600'}`}
                            onClick={() => setDetailTab('tickets')}
                          >
                            Tickets
                          </button>
                          <button
                            className={`px-3 py-2 -mb-px ${detailTab === 'categories' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-600'}`}
                            onClick={() => setDetailTab('categories')}
                          >
                            Categorías
                          </button>
                          <button
                            className={`px-3 py-2 -mb-px ${detailTab === 'sales' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-600'}`}
                            onClick={() => setDetailTab('sales')}
                          >
                            Ventas
                          </button>
                        </div>

                        <div className="mt-4">
                          {detailTab === 'overview' && (
                            <div className="space-y-3">
                              <div className="text-sm text-gray-600">Resumen rápido y métricas del vendedor.</div>
                              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div className="bg-white rounded shadow p-3">
                                  <div className="text-xs text-gray-500">Tickets</div>
                                  <div className="text-lg font-semibold">{sellerDetail.tickets_summary?.tickets_count ?? 0}</div>
                                  <div className="text-xs text-gray-400">Avg USD: {sellerDetail.tickets_summary?.avg_ticket_usd ? moneyUSD(sellerDetail.tickets_summary.avg_ticket_usd) : '—'}</div>
                                </div>
                                <div className="bg-white rounded shadow p-3">
                                  <div className="text-xs text-gray-500">Categorías con comisión</div>
                                  <div className="text-lg font-semibold">{(sellerDetail.categories || []).length}</div>
                                  <div className="text-xs text-gray-400">Revisa la pestaña Categorías para más detalle</div>
                                </div>
                              </div>
                            </div>
                          )}

                          {detailTab === 'tickets' && (
                            <div>
                              <div className="text-sm text-gray-600 mb-2">Lista de tickets (ordenado por valor)</div>
                              <div className="max-h-72 overflow-auto bg-white rounded shadow">
                                <table className="w-full text-sm">
                                  <thead className="bg-gray-100 sticky top-0">
                                    <tr>
                                      <th className="p-2 text-left">Folio</th>
                                      <th className="p-2 text-right">Fecha</th>
                                      <th className="p-2 text-right">USD</th>
                                      <th className="p-2 text-right">COP</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {sellerDetail.tickets && sellerDetail.tickets.length > 0 ? sellerDetail.tickets.map((t:any, i:number) => (
                                      <tr key={i} className="border-t">
                                        <td className="p-2">{t.folio}</td>
                                        <td className="p-2 text-right">{t.sale_date}</td>
                                        <td className="p-2 text-right">{moneyUSD(t.ticket_usd)}</td>
                                        <td className="p-2 text-right">{moneyCOP(t.ticket_cop)}</td>
                                      </tr>
                                    )) : (
                                      <tr><td colSpan={4} className="p-4 text-center text-gray-500">No hay tickets</td></tr>
                                    )}
                                  </tbody>
                                </table>
                              </div>
                            </div>
                          )}

                          {detailTab === 'categories' && (
                            <div>
                              <div className="text-sm text-gray-600 mb-2">Resumen por categoría</div>
                              <div className="space-y-2">
                                {(sellerDetail.categories || []).length === 0 ? (
                                  <div className="p-4 text-gray-500">No hay datos de categorías</div>
                                ) : (
                                  (sellerDetail.categories || []).map((c:any, idx:number) => (
                                    <div key={idx} className="bg-white rounded shadow p-3 flex items-center justify-between">
                                      <div>
                                        <div className="font-medium">{c.category}</div>
                                        <div className="text-xs text-gray-400">Ventas: {moneyUSD(c.sales_sum_usd ?? 0)} — {moneyCOP(c.sales_sum_cop ?? 0)}</div>
                                      </div>
                                      <div className="text-right">
                                        <div className="text-sm font-semibold">{c.qualifies ? 'Califica' : 'No califica'}</div>
                                        <div className="text-xs text-gray-400">{(c.pct_user_of_category_budget ?? '—') + (c.pct_user_of_category_budget ? '%' : '')}</div>
                                      </div>
                                    </div>
                                  ))
                                )}
                              </div>
                            </div>
                          )}

                          {detailTab === 'sales' && (
                            <div>
                              <div className="text-sm text-gray-600 mb-2">Ventas detalle</div>
                              <div className="max-h-72 overflow-auto bg-white rounded shadow">
                                <table className="w-full text-sm">
                                  <thead className="bg-gray-100 sticky top-0">
                                    <tr>
                                      <th className="p-2 text-left">Fecha</th>
                                      <th className="p-2 text-left">Folio</th>
                                      <th className="p-2 text-left">Producto</th>
                                      <th className="p-2 text-right">USD</th>
                                      <th className="p-2 text-right">COP</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {sellerDetail.sales && sellerDetail.sales.length > 0 ? sellerDetail.sales.map((s:any, i:number) => (
                                      <tr key={i} className="border-t">
                                        <td className="p-2">{s.sale_date}</td>
                                        <td className="p-2">{s.folio}</td>
                                        <td className="p-2">{s.product}</td>
                                        <td className="p-2 text-right">{moneyUSD(s.value_usd)}</td>
                                        <td className="p-2 text-right">{moneyCOP(s.amount_cop)}</td>
                                      </tr>
                                    )) : (
                                      <tr><td colSpan={5} className="p-4 text-center text-gray-500">No hay ventas</td></tr>
                                    )}
                                  </tbody>
                                </table>
                              </div>
                            </div>
                          )}
                        </div>
                      </div>
                    </>
                  ) : (
                    <div className="p-4 text-center text-gray-500">Selecciona un vendedor para ver detalle</div>
                  )}
                </div>

                {/* FOOTER (mobile actions) */}
                <div className="p-4 border-t flex items-center justify-between gap-2 sticky bottom-0 bg-white z-10">
                  <div className="text-xs text-gray-500">Presupuestos: {budgetIds.join(', ') || '—'}</div>
                  <div className="flex items-center gap-2">
                    <button onClick={exportSellerDetail} className="px-3 py-2 bg-blue-600 text-white rounded text-sm">Exportar</button>
                    <button onClick={closeModal} className="px-3 py-2 border rounded text-sm">Cerrar</button>
                  </div>
                </div>
              </div>
            </div>
          )}
        </main>
      </div>
    </div>
  );
}
