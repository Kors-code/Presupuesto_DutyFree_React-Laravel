import React, { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES ================= */
type SaleRow = {
  commission_id: number;
  sale_date: string;
  folio: string;
  pdv: string;
  product: string;
  amount_cop: number;
  value_usd: number;
  exchange_rate: number | null;
  commission_amount: number;
  is_provisional: boolean;
  category_code?: string;
  category_desc?: string;
};

export default function CommissionDetailModal({
  userId,
  budgetId,
  onClose
}: {
  userId: number;
  budgetId: number;
  onClose: () => void;
}) {
  const [loading, setLoading] = useState(true);
  const [sales, setSales] = useState<SaleRow[]>([]);
  const [categories, setCategories] = useState<any[]>([]);
  const [totals, setTotals] = useState<any>(null);
  const [budget, setBudget] = useState<any>(null);
  const [userBudgetUsd, setUserBudgetUsd] = useState<number>(0);
  const [userName, setUserName] = useState<string>('Vendedor');

  const [filterCat, setFilterCat] = useState<string>('ALL');
  const [search, setSearch] = useState('');
  const [categoryView, setCategoryView] = useState<'cards' | 'table'>('cards');

useEffect(() => {
  if (userId && budgetId) load();
}, [userId, budgetId]);

  const load = async () => {
    setLoading(true);
    try {
      const res = await api.get(`commissions/by-seller/${userId}?budget_id=${budgetId}`);
      const d = res.data || {};
      console.log('API categories:', d.categories);
      setSales(d.sales || []);
      setCategories(d.categories || []);
      setTotals(d.totals || {});
      setBudget(d.budget || null);
      setUserBudgetUsd(d.user_budget_usd ?? 0);
      setUserName(d.user?.name ?? d.seller_name ?? 'Vendedor');
    } finally {
      setLoading(false);
    }
  };

  const moneyUSD = (v:number) =>
    new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v||0);
  const moneyCOP = (v:number) =>
    new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP'}).format(v||0);

  /* ================= KPIs ================= */
  const totalSalesUsd = categories.reduce((s,c)=>s+Number(c.sales_sum_usd||0),0);
  const totalCommissionUsd = categories.reduce((s,c)=>s+Number(c.commission_sum_usd||0),0);

  /* ================= CATEGORY DATA ================= */
  const categoryCards = categories.map((c:any) => {
    const sales = Number(c.sales_sum_usd || 0);
    const ppto  = Number(c.category_budget_usd_for_user || 0);
    const diff  = sales - ppto;
    const pct   = Number(c.pct_user_of_category_budget || 0);

    return {
      code: c.classification_code,
      name: c.category ?? c.classification_desc ?? 'Sin categoría',
      sales,
      ppto,
      diff,
      pct,
      commissionUsd: Number(c.commission_sum_usd || 0),
      appliedPct: Number(c.applied_commission_pct || 0)
    };
  });

  /* ================= SALES FILTER ================= */
  const filteredSales = useMemo(() => {
    return sales.filter(s => {
      if (filterCat !== 'ALL' && s.category_code !== filterCat) return false;
      if (!search) return true;
      return `${s.product} ${s.folio}`.toLowerCase().includes(search.toLowerCase());
    });
  }, [sales, filterCat, search]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />

      <div className="relative bg-gray-50 rounded-2xl shadow-2xl w-11/12 lg:w-5/6 p-6 overflow-auto max-h-[92vh]">

        {/* HEADER */}
        <div className="flex justify-between items-start mb-8">
          <div>
            <div className="text-xs text-gray-500">Detalle de comisiones</div>
            <h2 className="text-2xl font-bold">{userName}</h2>
            {budget && (
              <div className="text-xs text-gray-400 mt-1">
                Periodo {budget.start_date} → {budget.end_date}
              </div>
            )}
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-800 text-xl">✕</button>
        </div>

        {loading ? (
          <div className="p-16 text-center text-gray-500">Cargando información…</div>
        ) : (
          <>
            {/* KPI CARDS */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-5 mb-10">
              <KpiCard label="Ventas USD" value={moneyUSD(totalSalesUsd)} icon="💰" />
              <KpiCard label="PPTO USD" value={moneyUSD(userBudgetUsd)} icon="🎯" />
              <KpiCard label="Comisión USD" value={moneyUSD(totalCommissionUsd)} icon="🏆" />
              <KpiCard
                label="Comisión COP"
                value={moneyCOP(totals?.total_commission_cop)}
                sub={`TRM ${Number(totals?.avg_trm||0).toFixed(2)}`}
                icon="🇨🇴"
              />
            </div>

            {/* CATEGORY HEADER + TOGGLE */}
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-semibold">Desempeño por categoría</h3>
              <div className="flex gap-2">
                <button
                  onClick={()=>setCategoryView('cards')}
                  className={`px-3 py-1.5 rounded text-sm ${
                    categoryView==='cards'
                      ? 'bg-indigo-600 text-white'
                      : 'bg-gray-200 text-gray-700'
                  }`}
                >
                  Cards
                </button>
                <button
                  onClick={()=>setCategoryView('table')}
                  className={`px-3 py-1.5 rounded text-sm ${
                    categoryView==='table'
                      ? 'bg-indigo-600 text-white'
                      : 'bg-gray-200 text-gray-700'
                  }`}
                >
                  Tabla
                </button>
              </div>
            </div>

            {/* CATEGORY VIEW */}
            {categoryView === 'cards' ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-12">
                {categoryCards.map(c => {
                  const status =
                    c.pct >= 100 ? 'success' :
                    c.pct >= 90  ? 'warning' : 'danger';

                  return (
                    <div key={c.code} className="bg-white rounded-2xl shadow border p-4 relative">
                      <div className={`absolute top-0 left-0 h-1 w-full rounded-t-2xl ${
                        status === 'success' ? 'bg-green-500'
                        : status === 'warning' ? 'bg-yellow-500'
                        : 'bg-red-500'
                      }`} />

                      <div className="flex justify-between mb-3">
                        <div>
                          <div className="font-semibold">{c.name}</div>
                          <div className="text-xs text-gray-500">% Comisión {c.appliedPct.toFixed(2)}%</div>
                        </div>
                        <div className="text-right font-bold text-green-600">
                          {moneyUSD(c.commissionUsd)}
                        </div>
                      </div>

                      <div className="mb-3">
                        <div className="flex justify-between text-xs mb-1">
                          <span>Cumplimiento</span>
                          <span className="font-semibold">{c.pct.toFixed(1)}%</span>
                        </div>
                        <div className="w-full bg-gray-200 h-2 rounded">
                          <div
                            className={`h-2 rounded ${
                              status==='success'?'bg-green-500':
                              status==='warning'?'bg-yellow-500':'bg-red-500'
                            }`}
                            style={{ width:`${Math.min(100,c.pct)}%` }}
                          />
                        </div>
                      </div>

                      <div className="text-sm grid grid-cols-2 gap-y-1">
                        <span className="text-gray-500">Ventas</span>
                        <span className="text-right">{moneyUSD(c.sales)}</span>
                        <span className="text-gray-500">PPTO</span>
                        <span className="text-right">{moneyUSD(c.ppto)}</span>
                        <span className="text-gray-500">Dif.</span>
                        <span className={`text-right font-medium ${c.diff>=0?'text-green-600':'text-red-600'}`}>
                          {c.diff>=0?'+':''}{moneyUSD(c.diff)}
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="bg-white rounded-2xl shadow overflow-x-auto mb-12">
                <table className="w-full text-sm">
                  <thead className="bg-gray-100">
                    <tr>
                      <th className="p-3 text-left">Categoría</th>
                      <th className="p-3 text-right">PPTO</th>
                      <th className="p-3 text-right">Ventas</th>
                      <th className="p-3 text-right">Dif.</th>
                      <th className="p-3 text-right">% Cumpl.</th>
                      <th className="p-3 text-right">% Comisión</th>
                      <th className="p-3 text-right">Comisión USD</th>
                    </tr>
                  </thead>
                  <tbody>
                    {categoryCards.map(c=>(
                      <tr key={c.code} className="border-t">
                        <td className="p-3">{c.name}</td>
                        <td className="p-3 text-right">{moneyUSD(c.ppto)}</td>
                        <td className="p-3 text-right">{moneyUSD(c.sales)}</td>
                        <td className={`p-3 text-right ${c.diff>=0?'text-green-600':'text-red-600'}`}>
                          {c.diff>=0?'+':''}{moneyUSD(c.diff)}
                        </td>
                        <td className="p-3 text-right">{c.pct.toFixed(1)}%</td>
                        <td className="p-3 text-right">{c.appliedPct.toFixed(2)}%</td>
                        <td className="p-3 text-right font-semibold text-green-600">
                          {moneyUSD(c.commissionUsd)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* SALES FILTER */}
            <h3 className="text-lg font-semibold mb-3">Detalle de ventas</h3>

            <div className="flex gap-2 mb-4">
              <select
                value={filterCat}
                onChange={e=>setFilterCat(e.target.value)}
                className="border rounded-lg px-3 py-2 text-sm bg-white"
              >
                <option value="ALL">Todas las categorías</option>
                {categoryCards.map(c=>(
                  <option key={c.code} value={c.code}>{c.name}</option>
                ))}
              </select>

              <input
                value={search}
                onChange={e=>setSearch(e.target.value)}
                placeholder="Buscar producto o folio…"
                className="border rounded-lg px-3 py-2 text-sm flex-1"
              />
            </div>

            {/* SALES TABLE */}
            <div className="bg-white rounded-2xl shadow overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-100">
                  <tr>
                    <th className="p-3 text-left">Fecha</th>
                    <th className="p-3 text-left">Producto</th>
                    <th className="p-3 text-right">USD</th>
                    <th className="p-3 text-right">COP</th>
                    <th className="p-3 text-right">Comisión</th>
                    <th className="p-3 text-center">Estado</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredSales.map(s=>(
                    <tr key={s.commission_id} className="border-t hover:bg-gray-50">
                      <td className="p-3">{s.sale_date}</td>
                      <td className="p-3">{s.product || s.folio}</td>
                      <td className="p-3 text-right">{moneyUSD(s.value_usd)}</td>
                      <td className="p-3 text-right">{moneyCOP(s.amount_cop)}</td>
                      <td className="p-3 text-right font-semibold">{moneyCOP(s.commission_amount)}</td>
                      <td className="p-3 text-center">
                        <span className={`px-3 py-1 rounded-full text-xs ${
                          s.is_provisional
                            ? 'bg-yellow-100 text-yellow-700'
                            : 'bg-green-100 text-green-700'
                        }`}>
                          {s.is_provisional ? 'Provisional' : 'Definitiva'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/* ================= KPI CARD ================= */
function KpiCard({ label, value, sub, icon }:{
  label:string; value:string; sub?:string; icon:string
}) {
  return (
    <div className="bg-white rounded-2xl shadow border p-4">
      <div className="flex justify-between mb-1">
        <div className="text-xs text-gray-500">{label}</div>
        <div className="text-xl">{icon}</div>
      </div>
      <div className="text-xl font-bold">{value}</div>
      {sub && <div className="text-xs text-gray-400 mt-1">{sub}</div>}
    </div>
  );
}
