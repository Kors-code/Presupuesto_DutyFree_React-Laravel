import React, { useEffect, useState } from 'react';
import SalesUserService from '../services/salesUser.service.ts';
import type { UserSummary, SaleRow } from '../types';
import UserCard from '../components/UserCard';
import SalesTable from '../components/SalesTable';

export default function SalesByUserPage() {
    const [users, setUsers] = useState<UserSummary[]>([]);
    const [selected, setSelected] = useState<UserSummary | null>(null);
    const [sales, setSales] = useState<SaleRow[]>([]);
    const [loadingUsers, setLoadingUsers] = useState(false);
    const [loadingSales, setLoadingSales] = useState(false);
    const [dateFrom, setDateFrom] = useState<string>('');
    const [dateTo, setDateTo] = useState<string>('');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        loadUsers();
    }, []);

    const loadUsers = async () => {
        try {
            setLoadingUsers(true);
            const data = await SalesUserService.getUsers();
            setUsers(data);
            setLoadingUsers(false);
            console.log("USERS RESPONSE:", data);
        } catch (err: any) {
            setLoadingUsers(false);
            setError(err?.message || 'Error cargando usuarios');
        }
    };

    const loadSales = async (u: UserSummary) => {
        try {
            setSelected(u);
            setLoadingSales(true);
            setError(null);
            const payload = {
                type: u.type,
                key: u.key,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            };
            const res = await SalesUserService.getSalesByUser(payload);
            setSales(res.sales || res.sales === undefined ? res.sales : []);
            setLoadingSales(false);
        } catch (err: any) {
            setLoadingSales(false);
            setError(err?.message || 'Error cargando ventas');
        }
    };

    return (
        <div className="p-6 max-w-7xl mx-auto">
            <h1 className="text-3xl font-extrabold mb-4">Ventas por Usuario</h1>

            {error && <div className="mb-4 text-red-600">{error}</div>}

            <section className="mb-8">
                <h2 className="text-lg font-semibold mb-3">Usuarios</h2>
                {loadingUsers ? (
                    <div className="p-4 bg-white rounded shadow">Cargando usuarios...</div>
                ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        {users.map(u => (
                            <UserCard
                                key={`${u.type}-${u.key}`}
                                user={u}
                                active={selected?.key === u.key && selected?.type === u.type}
                                onClick={loadSales}
                            />
                        ))}
                    </div>
                )}
            </section>

            {selected && (
                <section className="bg-white p-4 rounded-xl shadow">
                    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h3 className="text-xl font-semibold">Ventas de {selected.label}</h3>
                            <div className="text-sm text-gray-500">{selected.type}</div>
                        </div>

                        <div className="flex items-center gap-3">
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={e => setDateFrom(e.target.value)}
                                className="border rounded px-3 py-2"
                            />
                            <input
                                type="date"
                                value={dateTo}
                                onChange={e => setDateTo(e.target.value)}
                                className="border rounded px-3 py-2"
                            />
                            <button
                                onClick={() => loadSales(selected)}
                                className="bg-indigo-600 text-white px-4 py-2 rounded"
                            >
                                Filtrar
                            </button>
                            <button
                                onClick={() => {
                                    setDateFrom('');
                                    setDateTo('');
                                    loadSales(selected);
                                }}
                                className="px-4 py-2 border rounded"
                            >
                                Limpiar
                            </button>
                        </div>
                    </div>

                    <div className="mt-4">
                        {loadingSales ? (
                            <div className="p-4">Cargando ventas...</div>
                        ) : (
                            <SalesTable sales={sales} />
                        )}
                    </div>
                </section>
            )}
        </div>
    );
}
