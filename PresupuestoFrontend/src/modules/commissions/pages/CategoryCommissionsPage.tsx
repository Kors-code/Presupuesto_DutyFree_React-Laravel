import React, { useEffect, useState } from 'react';
import {
    getCategoriesWithCommission,
    upsertCategoryCommission,
    bulkSaveCategoryCommissions,
    deleteCategoryCommission,
    getRoles
} from '../services/categoryCommissionService';
import type { CategoryWithCommission, Role } from '../types/comissionscategory';

export default function CategoryCommissionsPage() {
    const [roleId, setRoleId] = useState<number | null>(null);
    const [roles, setRoles] = useState<Role[]>([]);
    const [items, setItems] = useState<CategoryWithCommission[]>([]);
    const [loading, setLoading] = useState(false);
    const [savingIds, setSavingIds] = useState<number[]>([]);
    const [message, setMessage] = useState<string | null>(null);

    useEffect(() => {
        loadRoles();
    }, []);

    useEffect(() => {
        if (roleId) loadCategories(roleId);
        console.log(roleId)
    }, [roleId]);

    // dentro de CategoryCommissionsPage.tsx

    const loadRoles = async () => {
        try {
            // getRoles() ya normaliza y devuelve array
            const rolesData = await getRoles();
            console.log('ROLES LOADED:', rolesData);
            setRoles(rolesData || []);
            if (rolesData && rolesData.length > 0) setRoleId(rolesData[0].id);
            else setRoleId(null);
        } catch (e) {
            console.error('Error cargando roles:', e);
            setRoles([]);
            setRoleId(null);
        }
    };

    const loadCategories = async (rId: number) => {
        try {
            setLoading(true);
            const res = await getCategoriesWithCommission(rId);
            // res = { categories: [...] }
            const cats = res?.categories ?? [];
            setItems(cats);
        } catch (e) {
            console.error('Error cargando categorias:', e);
            setItems([]);
        } finally {
            setLoading(false);
        }
    };

    const onChangePct = (idx: number, val: string) => {
        const clone = [...items];
        clone[idx].commission_percentage = val === '' ? null : Number(val);
        setItems(clone);
    };

    const saveOne = async (it: CategoryWithCommission, idx: number) => {
        if (!roleId) return;
        setSavingIds(s => [...s, it.category_id]);
        try {
            await upsertCategoryCommission({
                category_id: it.category_id,
                role_id: roleId,
                commission_percentage: it.commission_percentage ?? 0,
                commission_percentage100: it.commission_percentage100 ?? 0,
                commission_percentage120: it.commission_percentage120 ?? 0,
                min_pct_to_qualify: it.min_pct_to_qualify ?? 80
            });

            setMessage('Guardado');
            // reload to get id
            await loadCategories(roleId);
        } catch (e: any) {
            setMessage('Error: ' + (e?.message || ''));
            console.error(e);
        } finally {
            setSavingIds(s => s.filter(id => id !== it.category_id));
            setTimeout(() => setMessage(null), 2000);
        }
    };

    const saveAll = async () => {
        if (!roleId) return;
        try {
            await bulkSaveCategoryCommissions(roleId, items.map(i => ({
                category_id: i.category_id,
                commission_percentage: i.commission_percentage ?? 0,
                commission_percentage100: i.commission_percentage100 ?? 0,
                commission_percentage120: i.commission_percentage120 ?? 0,
                min_pct_to_qualify: i.min_pct_to_qualify ?? 80
            })));
            setMessage('Guardado masivo exitoso');
            await loadCategories(roleId);
        } catch (e) {
            console.error(e);
            setMessage('Error al guardar');
        }
        setTimeout(() => setMessage(null), 2000);
    };

    const removeRow = async (row: CategoryWithCommission) => {
        if (!row.commission_id) return; // nothing to delete
        try {
            await deleteCategoryCommission(row.commission_id);
            setMessage('Eliminado');
            if (roleId) await loadCategories(roleId);
        } catch (e) {
            console.error(e);
            setMessage('Error eliminando');
        }
        setTimeout(() => setMessage(null), 2000);
    };

    return (
        <div className="p-6 max-w-7xl mx-auto">
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold">Configuración de Comisiones por Categoría</h1>
                <div className="flex gap-2">
                    <select
                        value={roleId ?? ''}
                        onChange={e => setRoleId(Number(e.target.value))}
                        className="border px-3 py-2 rounded"
                    >
                        {roles.length === 0 && (
                            <option value="">No hay roles creados</option>
                        )}

                        {roles.map(r => (
                            <option key={r.id} value={r.id}>{r.name}</option>
                        ))}
                    </select>

                    <button onClick={saveAll} className="bg-indigo-600 text-white px-4 py-2 rounded">Guardar todo</button>
                </div>
            </div>

            {message && <div className="mb-4 text-sm text-green-700">{message}</div>}

            <div className="bg-white shadow rounded overflow-x-auto">
                <table className="w-full">
                    <thead className="bg-gray-100">
                        <tr>
                            <th className="p-2 text-left">Categoría</th>
                            <th className="p-2 text-left">Código</th>
                            <th className="p-2 text-left">Comisión %</th>
                            <th className="p-2 text-left">Comisión 100%</th>
                            <th className="p-2 text-left">Comisión 120%</th>
                            <th className="p-2 text-left">Min %</th>
                            <th className="p-2 text-left">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr><td colSpan={5} className="p-4">Cargando...</td></tr>
                        ) : items.map((it, idx) => (
                            <tr key={it.category_id} className="border-t hover:bg-gray-50">
                                <td className="p-2">{it.name}</td>
                                <td className="p-2 text-sm text-gray-500">{it.code}</td>
                                <td className="p-2">
                                <input
                                    type="number"
                                    step="0.01"
                                    value={it.commission_percentage ?? ''}
                                    onChange={e => {
                                        const clone = [...items];
                                        clone[idx].commission_percentage = Number(e.target.value);
                                        setItems(clone);
                                    }}
                                    className="border px-2 py-1 rounded w-24"
                                />
                            </td>

                            <td className="p-2">
                                <input
                                    type="number"
                                    step="0.01"
                                    value={it.commission_percentage100 ?? ''}
                                    onChange={e => {
                                        const clone = [...items];
                                        clone[idx].commission_percentage100 = Number(e.target.value);
                                        setItems(clone);
                                    }}
                                    className="border px-2 py-1 rounded w-24"
                                />
                            </td>

                            <td className="p-2">
                                <input
                                    type="number"
                                    step="0.01"
                                    value={it.commission_percentage120 ?? ''}
                                    onChange={e => {
                                        const clone = [...items];
                                        clone[idx].commission_percentage120 = Number(e.target.value);
                                        setItems(clone);
                                    }}
                                    className="border px-2 py-1 rounded w-24"
                                />
                            </td>

                                <td className="p-2">
                                    <input
                                        type="number"
                                        min={0}
                                        max={100}
                                        step="0.1"
                                        value={it.min_pct_to_qualify ?? 80}
                                        onChange={(e) => {
                                            const clone = [...items];
                                            clone[idx].min_pct_to_qualify = Number(e.target.value);
                                            setItems(clone);
                                        }}
                                        className="border px-2 py-1 rounded w-28"
                                    />
                                </td>
                                <td className="p-2">
                                    <div className="flex gap-2">
                                        <button
                                            onClick={() => saveOne(it, idx)}
                                            disabled={savingIds.includes(it.category_id)}
                                            className="px-3 py-1 border rounded bg-white"
                                        >
                                            {savingIds.includes(it.category_id) ? 'Guardando...' : 'Guardar'}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
