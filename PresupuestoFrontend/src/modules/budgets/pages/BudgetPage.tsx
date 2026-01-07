import React, { useEffect, useState } from 'react';
import {
    getActiveBudget,
    createBudget
} from '../services/budgetService';

export default function BudgetPage() {
    const [data, setData] = useState<any>(null);
    const [loading, setLoading] = useState(false);

    const [form, setForm] = useState({
        name: '',
        target_amount: '',
        min_pct_to_qualify: 80,
        start_date: '',
        end_date: ''
    });

    useEffect(() => {
        load();
    }, []);

    const load = async () => {
        setLoading(true);
        const res = await getActiveBudget();
        setData(res);
        setLoading(false);
    };

    const submit = async () => {
        await createBudget({
            ...form,
            target_amount: Number(form.target_amount)
        });
        setForm({
            name: '',
            target_amount: '',
            min_pct_to_qualify: 80,
            start_date: '',
            end_date: ''
        });
        load();
    };

    return (
        <div className="p-6 max-w-3xl mx-auto">
            <h1 className="text-2xl font-bold mb-4">Presupuesto</h1>

            {loading ? (
                <div>Cargando...</div>
            ) : data?.active ? (
                <div className="bg-white p-4 rounded shadow mb-6">
                    <div className="font-semibold">{data.budget.name}</div>
                    <div>Meta: ${data.budget.target_amount}</div>
                    <div>Ventas: ${data.sales_total}</div>
                    <div>
                        Cumplimiento:
                        <span className="font-bold ml-1">
                            {data.compliance_pct} %
                        </span>
                    </div>

                    <div className={`mt-2 font-semibold ${data.qualifies ? 'text-green-600' : 'text-red-600'
                        }`}>
                        {data.qualifies
                            ? '✔ Comisiones habilitadas'
                            : '✖ No se alcanzó el mínimo'}
                    </div>
                </div>
            ) : (
                <div className="mb-6 text-gray-600">
                    No hay presupuesto activo
                </div>
            )}

            {/* FORMULARIO */}
            <div className="bg-white p-4 rounded shadow">
                <h2 className="font-semibold mb-3">Crear Presupuesto</h2>

                <div className="grid grid-cols-2 gap-3">
                    <input
                        placeholder="Nombre"
                        value={form.name}
                        onChange={e => setForm({ ...form, name: e.target.value })}
                        className="border p-2 rounded"
                    />
                    <input
                        placeholder="Meta"
                        type="number"
                        value={form.target_amount}
                        onChange={e => setForm({ ...form, target_amount: e.target.value })}
                        className="border p-2 rounded"
                    />
                    <input
                        type="number"
                        value={form.min_pct_to_qualify}
                        onChange={e => setForm({ ...form, min_pct_to_qualify: Number(e.target.value) })}
                        className="border p-2 rounded"
                    />
                    <input
                        type="date"
                        value={form.start_date}
                        onChange={e => setForm({ ...form, start_date: e.target.value })}
                        className="border p-2 rounded"
                    />
                    <input
                        type="date"
                        value={form.end_date}
                        onChange={e => setForm({ ...form, end_date: e.target.value })}
                        className="border p-2 rounded col-span-2"
                    />
                </div>

                <button
                    onClick={submit}
                    className="mt-4 bg-indigo-600 text-white px-4 py-2 rounded"
                >
                    Guardar Presupuesto
                </button>
            </div>
        </div>
    );
}
