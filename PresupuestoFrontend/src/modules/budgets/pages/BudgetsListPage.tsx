// src/modules/budgets/pages/BudgetsListPage.tsx
import { useEffect, useState } from 'react';
import BudgetList from '../components/BudgetList';
import BudgetForm from '../components/BudgetForm';
import { getBudgets, createBudget, updateBudget, deleteBudget } from '../../../api/budgets';
import type { Budget } from '../../../api/budgets';

export default function BudgetsListPage() {
    const [budgets, setBudgets] = useState<Budget[]>([]);
    const [loading, setLoading] = useState(false);
    const [editing, setEditing] = useState<Budget | null>(null);
    const [showForm, setShowForm] = useState(false);

    const load = async () => {
        setLoading(true);
        try {
            const res = await getBudgets();
            setBudgets(res.data ?? res);
        } catch (err) {
            console.error(err);
            alert('Error cargando budgets');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, []);

    const handleCreate = async () => {
        setEditing(null);
        setShowForm(true);
    };

    const handleSubmit = async (payload: Partial<Budget>) => {
        try {
            if (editing && editing.id) {
                await updateBudget(editing.id, payload);
                setEditing(null);
            } else {
                await createBudget(payload);
            }
            setShowForm(false);
            await load();
        } catch (err) {
            console.error(err);
            alert('Error guardando presupuesto');
        }
    };

    const handleEdit = (b: Budget) => {
        setEditing(b);
        setShowForm(true);
    };

    const handleDelete = async (id?: number) => {
        if (!id) return;
        if (!confirm('¿Está seguro de eliminar este presupuesto?')) return;
        try {
            await deleteBudget(id);
            await load();
        } catch (err) {
            console.error(err);
            alert('Error eliminando');
        }
    };

    return (
        <div className="min-h-screen bg-gray-100">
            {/* HEADER */}
            <header className="bg-primary text-white px-8 py-6 shadow-lg">
                <h1 className="text-4xl font-extrabold tracking-tight">
                    Gestión de Presupuestos
                </h1>
                <p className="text-white/80 mt-1">
                    Administra y controla los presupuestos de tu empresa
                </p>
            </header>

            {/* CONTENEDOR */}
            <div className="max-w-7xl mx-auto p-8">

                {/* BOTÓN NUEVO */}
                <button
                    onClick={handleCreate}
                    className="bg-primary text-white px-5 py-2.5 rounded-xl shadow-md 
                           hover:bg-primary/90 transition-all font-medium"
                >
                    + Nuevo Presupuesto
                </button>

                {/* CARD PRINCIPAL */}
                <div className="mt-8 bg-white rounded-2xl shadow-xl p-8">

                    <h2 className="text-2xl font-bold text-gray-800 mb-6">
                        Lista de Presupuestos
                    </h2>

                    <table className="w-full border-collapse">
                        <thead>
                            <tr className="bg-gray-100 text-gray-700 text-sm uppercase tracking-wide">
                                <th className="p-3">ID</th>
                                <th className="p-3">Mes</th>
                                <th className="p-3">Monto</th>
                                <th className="p-3">Turnos</th>
                                <th className="p-3">TRM</th>
                                <th className="p-3 text-center">Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            {budgets.map((b) => (
                                <tr
                                    key={b.id}
                                    className="border-b hover:bg-gray-50 transition"
                                >
                                    <td className="p-3">{b.id}</td>
                                    <td className="p-3">{b.month}</td>
                                    <td className="p-3 font-semibold text-gray-900">
                                        ${b.amount}
                                    </td>
                                    <td className="p-3">{b.shifts}</td>
                                    <td className="p-3">${b.trm ?? "-"}</td>

                                    <td className="p-3 flex items-center justify-center gap-3">

                                        {/* BOTÓN EDITAR */}
                                        <button
                                            onClick={() => handleEdit(b)}
                                            className="flex items-center gap-2 bg-primary text-white px-4 py-2 
                                                   rounded-lg hover:bg-primary/90 transition shadow"
                                        >
                                            <span>✏️</span>
                                            Editar
                                        </button>

                                        {/* BOTÓN ELIMINAR */}
                                        <button
                                            onClick={() => handleDelete(b.id)}
                                            className="flex items-center gap-2 bg-gray-800 text-white px-4 py-2 
                                                   rounded-lg hover:bg-black transition shadow"
                                        >
                                            <span>🗑️</span>
                                            Eliminar
                                        </button>

                                    </td>
                                </tr>
                            ))}
                        </tbody>

                    </table>
                </div>

                {/* FORMULARIO */}
                {showForm && (
                    <BudgetForm
                        budget={editing}
                        onSubmit={handleSubmit}
                        onCancel={() => setShowForm(false)}
                    />
                )}

            </div>
        </div>
    );

}