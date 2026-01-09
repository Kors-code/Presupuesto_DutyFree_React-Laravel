// src/pages/commissions/CommissionCardsPage.tsx
import React, { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';
import CommissionDetailModal from '../components/CommissionDetailModal';

type SellerRow = {
  user_id: number;
  seller: string;
  assignedTurns: number;
  total_commission_cop: number;
  total_sales_cop: number;
  total_sales_usd: number;
  avg_trm: number;
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
  commission_sum_usd?: number | null;
};

function StatBox({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="bg-gray-900 text-white rounded p-4 shadow-md">
      <div className="text-xs text-gray-300">{label}</div>
      <div className="text-2xl font-semibold">{value}</div>
      {sub && <div className="text-xs text-gray-400 mt-1">{sub}</div>}
    </div>
  );
}

export default function CommissionCardsPage() {
  const [budgetProgress, setBudgetProgress] = useState<any>(null);
  const [categoriesSummaryGlobal, setCategoriesSummaryGlobal] = useState<CategoryRow[]>([]);
  const [rows, setRows] = useState<SellerRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<number | null>(null);

  const [roleFilter, setRoleFilter] = useState<string>('');
  const [viewMode, setViewMode] = useState<'cards' | 'table'>('cards');
  const [sortBy, setSortBy] = useState<'sales_cop' | 'sales_usd' | 'commission_cop' | 'name'>('sales_cop');
  const [query, setQuery] = useState<string>('');
  const [showCategories, setShowCategories] = useState<boolean>(true);

  // budgets & selection
  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [budgets, setBudgets] = useState<any[]>([]);

  // month control (keeps backwards compatibility if you prefer month-based)
  const [month, setMonth] = useState<string>(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  });

  // initial budgets load
  useEffect(() => {
    let mounted = true;
    api.get('/budgets')
      .then(res => {
        if (!mounted) return;
        const data = res.data || [];
        setBudgets(Array.isArray(data) ? data : []);
        if (Array.isArray(data) && data.length > 0 && !budgetId) {
          setBudgetId(data[0].id);
        }
      })
      .catch(err => {
        console.error('Error loading budgets', err);
      });
    return () => { mounted = false; };
  }, []);

  // auto-load when budget/month/role change
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleFilter, budgetId, month]);

  const load = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (roleFilter) params.set('role_name', roleFilter);

      // prefer budget_id if selected
      if (budgetId) {
        params.set('budget_id', String(budgetId));
      } else if (month) {
        params.set('month', month);
      }

      const url = `commissions/by-seller?${params.toString()}`;
      const res = await api.get(url);

      if (res.data?.active) {
        // backend returns 'sellers' or 'rows' depending on implementation; adapt
        const sellers = res.data.sellers ?? res.data.rows ?? [];
        setRows(Array.isArray(sellers) ? sellers : []);
        setBudgetProgress(res.data.progress ?? res.data.budget_progress ?? {});
        const cats = res.data.categories_summary ?? res.data.categories_summary_global ?? [];
        setCategoriesSummaryGlobal(Array.isArray(cats) ? cats : []);
      } else {
        setRows([]);
        setBudgetProgress(null);
        setCategoriesSummaryGlobal([]);
      }
    } catch (e) {
      console.error('load commissions error', e);
      setRows([]);
      setBudgetProgress(null);
      setCategoriesSummaryGlobal([]);
    } finally {
      setLoading(false);
    }
  };

  const onGenerate = async () => {
    if (!confirm('¿Deseas generar/actualizar las comisiones del presupuesto/mes seleccionado?')) return;
    try {
      const params = new URLSearchParams();
      if (budgetId) params.set('budget_id', String(budgetId));
      else if (month) params.set('month', month);

      const res = await api.post(`commissions/generate?${params.toString()}`);
      if (res.data?.status === 'ok') {
        alert(`Comisiones procesadas: creadas ${res.data.created} — actualizadas ${res.data.updated}`);
      } else {
        alert('Resultado: ' + JSON.stringify(res.data));
      }
      await load();
    } catch (err) {
      console.error(err);
      alert('Error al generar comisiones');
    }
  };

  const moneyUSD = (v:number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(v || 0);
  const moneyCOP = (v:number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP' }).format(v || 0);

  const totalUsd = (budgetProgress?.total_usd ?? categoriesSummaryGlobal.reduce((s:any,c:any)=> s + Number(c.sales_usd || 0), 0));
  const pptoUsd = budgetProgress?.required_usd ?? (budgetProgress?.budget?.target_amount ?? 0);
  const commissionUsd = (() => {
    if (typeof budgetProgress?.total_commission_usd === 'number') return budgetProgress.total_commission_usd;
    if (categoriesSummaryGlobal && categoriesSummaryGlobal.length) {
      return categoriesSummaryGlobal.reduce((s:number, c:any) => s + (Number(c.projected_commission_usd ?? c.commission_usd ?? c.commission_sum_usd ?? 0)), 0);
    }
    return 0;
  })();
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

  return (
    <div className="p-6 max-w-7xl mx-auto">
      {/* selector row */}
      <div className="flex flex-wrap gap-3 items-center mb-4">
        <div className="flex items-center gap-2">
          <label className="text-xs text-gray-500">Presupuesto:</label>
          <select
            value={budgetId ?? ''}
            onChange={e => setBudgetId(e.target.value ? Number(e.target.value) : null)}
            className="border rounded px-2 py-1 text-sm"
          >
            <option value="">(Usar mes)</option>
            {budgets.map(b => (
              <option key={b.id} value={b.id}>{b.name} — {b.start_date} → {b.end_date}</option>
            ))}
          </select>
        </div>

        <div className="flex items-center gap-2">
          <label className="text-xs text-gray-500">Mes (YYYY-MM):</label>
          <input
            type="month"
            value={month}
            onChange={e => setMonth(e.target.value)}
            className="border rounded px-2 py-1 text-sm"
          />
        </div>

        <div className="flex items-center gap-2 ml-auto">
          <button onClick={load} className="px-3 py-1 bg-indigo-600 text-white rounded text-sm">Cargar</button>
          <button onClick={onGenerate} className="px-3 py-1 bg-green-600 text-white rounded text-sm">Generar/Confirmar</button>
        </div>
      </div>

      {/* top strip */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 items-start">
        <div className="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-4">
          <StatBox label="Ventas USD" value={moneyUSD(Number(totalUsd || 0))} sub={`PPTO USD: ${moneyUSD(Number(pptoUsd || 0))}`} />
          <StatBox label="Comisiones USD" value={commissionUsd ? moneyUSD(Number(commissionUsd || 0)) : '—'} />
          <div className="bg-white rounded p-3 shadow flex flex-col justify-between">
            <div>
              <div className="text-xs text-gray-500">Comisiones COP</div>
              <div className="text-lg font-semibold text-green-600">{moneyCOP(Number(totalCommissionCop || 0))}</div>
              <div className="text-xs text-gray-400 mt-1">TRM {trm ?? '—'}</div>
            </div>
            <div className="mt-3 flex gap-2">
              <button onClick={load} className="px-3 py-2 bg-indigo-600 text-white rounded">Actualizar</button>
              <button onClick={onGenerate} className="px-3 py-2 bg-green-600 text-white rounded">Confirmar comisiones</button>
            </div>
          </div>
        </div>

        <div className="bg-white rounded p-3 shadow">
          <div className="text-xs text-gray-500">Turnos (Total / Asignados / Restan)</div>
          <div className="text-xl font-semibold mt-2">
            {budgetProgress?.turns?.total ?? 0} / {budgetProgress?.turns?.assigned_total ?? 0} / {budgetProgress?.turns?.remaining ?? 0}
          </div>
        </div>
      </div>

      {/* controls */}
      <div className="flex gap-2 items-center mb-4">
        <div className="flex gap-2">
          <button onClick={() => setViewMode('cards')} className={`px-3 py-1 rounded ${viewMode==='cards' ? 'bg-indigo-600 text-white' : 'bg-gray-200'}`}>Cards</button>
          <button onClick={() => setViewMode('table')} className={`px-3 py-1 rounded ${viewMode==='table' ? 'bg-indigo-600 text-white' : 'bg-gray-200'}`}>Tabla</button>
        </div>

        <div className="ml-4 flex gap-2 items-center">
          <label className="text-xs text-gray-500">Ordenar:</label>
          <select value={sortBy} onChange={e => setSortBy(e.target.value as any)} className="border rounded px-2 py-1 text-xs">
            <option value="sales_cop">Ventas (COP)</option>
            <option value="sales_usd">Ventas (USD)</option>
            <option value="commission_cop">Comisión (COP)</option>
            <option value="name">Nombre</option>
          </select>
        </div>

        <div className="ml-auto flex gap-2 items-center">
          <input placeholder="Buscar vendedor..." value={query} onChange={e=>setQuery(e.target.value)} className="border rounded px-2 py-1 text-sm" />
          <select value={roleFilter} onChange={e => setRoleFilter(e.target.value)} className="border rounded px-2 py-1 text-sm">
            <option value="">Todos los roles</option>
            <option value="Ventas">Ventas</option>
            <option value="Cajero">Cajero</option>
          </select>
          <button onClick={() => setShowCategories(s => !s)} className="px-3 py-1 bg-gray-200 rounded text-sm">
            {showCategories ? 'Ocultar' : 'Mostrar'} totales por categoría
          </button>
        </div>
      </div>

      {/* CATEGORÍAS */}
      {showCategories && (
        <div className="mb-6 bg-white rounded shadow overflow-x-auto">
          <div className="p-3 border-b">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm font-semibold">Totales por categoría</div>
                <div className="text-xs text-gray-500">Resumen de ventas y comisiones por categoría</div>
              </div>
            </div>
          </div>

          <table className="w-full text-sm">
            <thead className="bg-gray-100 text-gray-700">
              <tr>
                <th className="p-2 text-left">Categoría</th>
                <th className="p-2 text-right">Participación %</th>
                <th className="p-2 text-right">Presupuesto (USD)</th>
                <th className="p-2 text-right">Ventas (USD)</th>
                <th className="p-2 text-right">Ventas (COP)</th>
                <th className="p-2 text-right">% de categoría</th>
                <th className="p-2 text-right">Califica</th>
                <th className="p-2 text-right">Pct. comisión</th>
                <th className="p-2 text-right">Comisión (USD)</th>
                <th className="p-2 text-right">Comisión (COP)</th>
              </tr>
            </thead>
            <tbody>
              {categoriesSummaryGlobal.length === 0 ? (
                <tr>
                  <td colSpan={10} className="p-4 text-center text-gray-500">No hay datos de categorías</td>
                </tr>
              ) : categoriesSummaryGlobal.map((c, i) => (
                <tr key={c.classification + i} className="border-t hover:bg-gray-50">
                  <td className="p-2">{(c.classification === 'fragancias' || String(c.classification ?? '').toLowerCase().includes('frag')) ? 'FRAGANCIAS' : (c.classification || 'Sin categoría')}</td>
                  <td className="p-2 text-right">{(c.participation_pct ?? 0).toFixed(2)}%</td>
                  <td className="p-2 text-right">{moneyUSD(Number(c.category_budget_usd ?? 0))}</td>
                  <td className="p-2 text-right">{Number(c.sales_usd ?? 0).toFixed(2)}</td>
                  <td className="p-2 text-right">{moneyCOP(Number(c.sales_cop ?? 0))}</td>
                  <td className="p-2 text-right">{c.pct_of_category === null || typeof c.pct_of_category === 'undefined' ? '—' : `${Number(c.pct_of_category).toFixed(2)}%`}</td>
                  <td className="p-2 text-right">{c.qualifies ? 'Sí' : 'No'}</td>
                  <td className="p-2 text-right">{(typeof c.applied_commission_pct !== 'undefined' && c.applied_commission_pct !== null) ? `${Number(c.applied_commission_pct).toFixed(2)}%` : '—'}</td>
                  <td className="p-2 text-right">{c.projected_commission_usd ? moneyUSD(Number(c.projected_commission_usd)) : (c.commission_usd ? moneyUSD(Number(c.commission_usd)) : '—')}</td>
                  <td className="p-2 text-right">{moneyCOP(Number(c.commission_cop ?? 0))}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* SELLERS */}
      {loading ? <div className="p-6">Cargando…</div> : displayedRows.length === 0 ? <div className="p-6 text-gray-600">No hay datos</div> : (
        viewMode === 'cards' ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {displayedRows.map(r => (
              <button key={r.user_id} onClick={() => setSelected(r.user_id)}
                className="text-left bg-white shadow-md rounded-lg p-4 hover:shadow-xl transform hover:-translate-y-1 transition">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-xs text-gray-500">Vendedor</div>
                    <div className="text-lg font-semibold">{r.seller}</div>
                  </div>
                  <div className="text-right">
                    <div className="text-xs text-gray-500">Comisión (COP)</div>
                    <div className="text-xl font-bold text-green-600">{moneyCOP(r.total_commission_cop ?? 0)}</div>
                  </div>
                </div>

                <div className="mt-3 grid grid-cols-3 gap-2 text-center">
                  <div>
                    <div className="text-xs text-gray-500">Turnos</div>
                    <div className="font-medium">{r.assignedTurns ?? 0}</div>
                  </div>
                  <div>
                    <div className="text-xs text-gray-500">Ventas (COP)</div>
                    <div className="font-medium">{moneyCOP(Number(r.total_sales_cop || 0))}</div>
                  </div>
                  <div>
                    <div className="text-xs text-gray-500">Ventas (USD)</div>
                    <div className="font-medium">{Number(r.total_sales_usd || 0).toFixed(2)} USD</div>
                  </div>
                </div>

                <div className="mt-3 text-sm text-gray-500 flex items-center justify-between">
                  <div>TRM avg: {Number(r.avg_trm || 0).toFixed(2)}</div>
                  <div className="text-xs text-gray-400">Click para detalle</div>
                </div>
              </button>
            ))}
          </div>
        ) : (
          <div className="bg-white rounded shadow overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-2 text-left">Vendedor</th>
                  <th className="p-2 text-right">Turnos</th>
                  <th className="p-2 text-right">Ventas (COP)</th>
                  <th className="p-2 text-right">Ventas (USD)</th>
                  <th className="p-2 text-right">Comisión (COP)</th>
                </tr>
              </thead>
              <tbody>
                {displayedRows.map(r => (
                  <tr key={r.user_id} className="border-t hover:bg-gray-50 cursor-pointer" onClick={() => setSelected(r.user_id)}>
                    <td className="p-2">{r.seller}</td>
                    <td className="p-2 text-right">{r.assignedTurns}</td>
                    <td className="p-2 text-right">{moneyCOP(r.total_sales_cop)}</td>
                    <td className="p-2 text-right">{Number(r.total_sales_usd || 0).toFixed(2)}</td>
                    <td className="p-2 text-right font-semibold text-green-600">{moneyCOP(r.total_commission_cop)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {selected && <CommissionDetailModal userId={selected} budgetId={budgetId} onClose={() => { setSelected(null); load(); }} />}
    </div>
  );
}
