import React, { useEffect, useState } from 'react';
import { getCommissionsSummary } from '../services/commissionsService';
import type { CategoryRow, CommissionTotals } from '../types';
import CommissionCard from '../components/CommissionCard';
import CommissionTable from '../components/CommissionTable';

export default function CommissionsPage() {
    const [totals, setTotals] = useState<CommissionTotals | null>(null);
    const [categories, setCategories] = useState<CategoryRow[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => { load(); }, []);

    const load = async () => {
        try {
            setLoading(true);
            const res = await getCommissionsSummary();
            setTotals(res.totals);
            setCategories(res.categories || []);
            setLoading(false);
        } catch (err) {
            console.error(err);
            setLoading(false);
        }
    };

    return (
        <div className="p-6 max-w-7xl mx-auto">
            <h1 className="text-2xl font-bold mb-4">Comisiones — Resumen</h1>

            {loading && <div> Cargando... </div>}

            {totals && (
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <CommissionCard title="Ventas (USD)" value={totals.total_sales_usd.toLocaleString()} />
                    <CommissionCard title="Comisiones (USD)" value={totals.total_commissions.toLocaleString()} />
                    <CommissionCard title="% Comisión / Ventas" value={`${totals.commission_pct_of_sales}%`} />
                    <CommissionCard title="Ventas Totales" value={totals.total_sales.toLocaleString()} />
                </div>
            )}

            <div className="bg-white p-4 rounded shadow">
                <h2 className="font-semibold mb-4">Resumen por Categoría</h2>
                <CommissionTable rows={categories} />
            </div>
        </div>
    );
}
