import React, { useEffect, useState } from 'react';
import api from '../../../api/axios';

type DayProgress = {
    date: string;
    daily_sales: number;
    accumulated_sales: number;
    compliance_pct: number;
};

export default function BudgetDailyProgressPage() {
    const [data, setData] = useState<DayProgress[]>([]);
    const [budget, setBudget] = useState<any>(null);
    const [dailyTarget, setDailyTarget] = useState<number>(0);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        load();
    }, []);

    const load = async () => {
        try {
            const res = await api.get('budgets/progress/daily');
            if (res.data.active) {
                setData(res.data.days);
                setBudget(res.data.budget);
                setDailyTarget(res.data.daily_target);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    };

    if (loading) return <div className="p-6">Cargando…</div>;
    if (!budget) return <div className="p-6">No hay presupuesto activo</div>;

    return (
        <div className="p-6 max-w-6xl mx-auto">
            <h1 className="text-2xl font-bold mb-4">
                Progreso diario – {budget.name}
            </h1>

            <div className="mb-6 text-sm text-gray-600">
                Meta total: <b>${budget.target_amount.toLocaleString()}</b> ·
                Meta diaria: <b>${dailyTarget.toLocaleString()}</b>
            </div>

            <div className="overflow-x-auto bg-white shadow rounded">
                <table className="w-full text-sm">
                    <thead className="bg-gray-100">
                        <tr>
                            <th className="p-2 text-left">Fecha</th>
                            <th className="p-2 text-right">Venta día</th>
                            <th className="p-2 text-right">Acumulado</th>
                            <th className="p-2 text-right">% Cumplimiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.map(d => (
                            <tr key={d.date} className="border-t hover:bg-gray-50">
                                <td className="p-2">{d.date}</td>
                                <td className="p-2 text-right">
                                    ${d.daily_sales.toLocaleString()}
                                </td>
                                <td className="p-2 text-right">
                                    ${d.accumulated_sales.toLocaleString()}
                                </td>
                                <td className="p-2 text-right">
                                    <span
                                        className={
                                            d.compliance_pct >= 80
                                                ? 'text-green-600 font-semibold'
                                                : 'text-gray-700'
                                        }
                                    >
                                        {d.compliance_pct} %
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
