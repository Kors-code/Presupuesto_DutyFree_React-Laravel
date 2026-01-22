import React, { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES ================= */
type Category = {
  classification_code: string;
  category: string;
  sales_sum_usd: number;
  category_budget_usd_for_user: number;
  pct_user_of_category_budget: number | null;
  applied_commission_pct: number;
  commission_sum_usd: number | null;
};

export default function MyCommissionsPage() {
  const [loading, setLoading] = useState(true);
  const [categories, setCategories] = useState<Category[]>([]);
  const [totals, setTotals] = useState<any>(null);
  const [budget, setBudget] = useState<any>(null);
  const [userName, setUserName] = useState('Mis comisiones');
  const [userBudgetUsd, setUserBudgetUsd] = useState<number>(0);

  const [view, setView] = useState<'cards' | 'table'>('cards');

  // 🔧 MANUAL por ahora
  const USER_ID = 18;

  // Presupuestos
  const [budgets, setBudgets] = useState<any[]>([]);
  const [budgetId, setBudgetId] = useState<number | null>(null);

  /* ================= EFFECTS ================= */
  useEffect(() => {
    api.get('/budgets')
      .then(res => {
        setBudgets(res.data || []);
        if (res.data?.length) setBudgetId(res.data[0].id);
      });
  }, []);

  useEffect(() => {
    if (budgetId) load();
  }, [budgetId]);

  const load = async () => {
    setLoading(true);
    try {
      const res = await api.get(
        `/commissions/by-seller/${USER_ID}?budget_id=${budgetId}`
      );
      const d = res.data || {};

      setCategories(d.categories || []);
      setTotals(d.totals || null);
      setBudget(d.budget || null);
      setUserBudgetUsd(Number(d.user_budget_usd || 0));
      setUserName(d.user?.name ?? d.seller_name ?? 'Mis comisiones');
    } finally {
      setLoading(false);
    }
  };

  /* ================= HELPERS ================= */
  const moneyUSD = (v:number) =>
    new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v||0);

  const moneyCOP = (v:number) =>
    new Intl.NumberFormat('es-CO',{
      style:'currency',
      currency:'COP',
      maximumFractionDigits:0
    }).format(v||0);

  const totalSalesUsd = categories.reduce(
    (s,c)=>s+Number(c.sales_sum_usd||0),0
  );

  const totalCommissionUsd =
    Number(totals?.total_commission_cop||0) /
    Number(totals?.avg_trm||1);

  const categoryCards = useMemo(() => {
    return categories.map(c => {
      const sales = Number(c.sales_sum_usd||0);
      const ppto  = Number(c.category_budget_usd_for_user||0);
      return {
        code: c.classification_code,
        name: c.category,
        sales,
        ppto,
        diff: sales - ppto,
        pct: Number(c.pct_user_of_category_budget||0),
        commissionUsd: Number(c.commission_sum_usd||0),
        appliedPct: Number(c.applied_commission_pct||0)
      };
    });
  }, [categories]);

  /* ================= RENDER ================= */
  return (
    <div className="min-h-screen bg-slate-50 p-4 sm:p-6">
      <div className="max-w-3xl mx-auto">

        {/* PRESUPUESTO */}
        <div className="mb-6">
          <label className="text-xs text-gray-500">Presupuesto</label>
          <select
            value={budgetId ?? ''}
            onChange={e=>setBudgetId(Number(e.target.value))}
            className="w-full sm:w-72 border rounded px-3 py-2 text-sm bg-white"
          >
            {budgets.map(b=>(
              <option key={b.id} value={b.id}>
                {b.name} — {b.start_date} → {b.end_date}
              </option>
            ))}
          </select>
        </div>

        {/* HEADER */}
        <div className="mb-6">
            <div className="text-xs text-gray-500">Comisiones de</div>
            <h1 className="text-2xl font-bold">{userName}</h1>

          {budget && (
            <div className="text-xs text-gray-400">
              Periodo {budget.start_date} → {budget.end_date}
            </div>
          )}
        </div>

        {loading ? (
          <div className="p-10 text-center text-gray-400">
            Cargando información…
          </div>
        ) : (
          <>
            {/* KPIS */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
              <Kpi label="Ventas USD" value={moneyUSD(totalSalesUsd)} icon="💰"/>
              <Kpi label="PPTO USD" value={moneyUSD(userBudgetUsd)} icon="🎯"/>
              <Kpi label="Comisión USD" value={moneyUSD(totalCommissionUsd)} icon="🏆"/>
              <Kpi
                label="Comisión COP"
                value={moneyCOP(totals?.total_commission_cop)}
                sub={`TRM ${Number(totals?.avg_trm||0).toFixed(2)}`}
                icon="🇨🇴"
              />
            </div>

            {/* TOGGLE */}
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-semibold">Desempeño por categoría</h3>
              <div className="flex gap-2">
                <button
                  onClick={()=>setView('cards')}
                  className={`px-3 py-1 rounded text-sm ${
                    view==='cards'?'bg-indigo-600 text-white':'bg-gray-200'
                  }`}
                >
                  Cards
                </button>
                <button
                  onClick={()=>setView('table')}
                  className={`px-3 py-1 rounded text-sm ${
                    view==='table'?'bg-indigo-600 text-white':'bg-gray-200'
                  }`}
                >
                  Tabla
                </button>
              </div>
            </div>

            {/* CATEGORIES */}
            {view==='cards' ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {categoryCards.map(c=>(
                  <div key={c.code} className="bg-white rounded-xl shadow border p-4">
                    <div className="flex justify-between mb-3">
                      <div>
                        <div className="font-semibold">{c.name}</div>
                        <div className="text-xs text-gray-500">
                          % Comisión {c.appliedPct.toFixed(2)}%
                        </div>
                      </div>
                      <div className="font-bold text-green-600">
                        {moneyUSD(c.commissionUsd)}
                      </div>
                    </div>

                    <div className="mb-3">
                      <div className="flex justify-between text-xs mb-1">
                        <span>Cumplimiento</span>
                        <span className="font-semibold">{c.pct.toFixed(1)}%</span>
                      </div>
                      <div className="h-2 bg-gray-200 rounded">
                        <div
                          className="h-2 bg-indigo-500 rounded"
                          style={{width:`${Math.min(100,c.pct)}%`}}
                        />
                      </div>
                    </div>

                    <div className="grid grid-cols-2 text-sm gap-y-1">
                      <span className="text-gray-500">Ventas</span>
                      <span className="text-right">{moneyUSD(c.sales)}</span>
                      <span className="text-gray-500">PPTO</span>
                      <span className="text-right">{moneyUSD(c.ppto)}</span>
                      <span className="text-gray-500">Dif.</span>
                      <span className={`text-right ${c.diff>=0?'text-green-600':'text-red-600'}`}>
                        {c.diff>=0?'+':''}{moneyUSD(c.diff)}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="bg-white rounded-xl shadow overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-100">
                    <tr>
                      <th className="p-3 text-left">Categoría</th>
                      <th className="p-3 text-right">PPTO</th>
                      <th className="p-3 text-right">Ventas</th>
                      <th className="p-3 text-right">Dif.</th>
                      <th className="p-3 text-right">% Cumpl.</th>
                      <th className="p-3 text-right">Comisión USD</th>
                    </tr>
                  </thead>
                  <tbody>
                    {categoryCards.map(c=>(
                      <tr key={c.code} className="border-t">
                        <td className="p-3">{c.name}</td>
                        <td className="p-3 text-right">{moneyUSD(c.ppto)}</td>
                        <td className="p-3 text-right">{moneyUSD(c.sales)}</td>
                        <td className="p-3 text-right">{moneyUSD(c.diff)}</td>
                        <td className="p-3 text-right">{c.pct.toFixed(1)}%</td>
                        <td className="p-3 text-right font-semibold text-green-600">
                          {moneyUSD(c.commissionUsd)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

/* ================= KPI ================= */
function Kpi({label,value,sub,icon}:{label:string,value:string,sub?:string,icon:string}) {
  return (
    <div className="bg-white rounded-xl shadow border p-4">
      <div className="flex justify-between">
        <div className="text-xs text-gray-500">{label}</div>
        <div className="text-xl">{icon}</div>
      </div>
      <div className="text-xl font-bold">{value}</div>
      {sub && <div className="text-xs text-gray-400">{sub}</div>}
    </div>
  );
}
