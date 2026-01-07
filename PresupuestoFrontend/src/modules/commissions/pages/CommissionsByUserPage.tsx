import React, { useEffect, useState } from 'react';
import api from '../../../api/axios';

type SellerRow = {
    user_id: number;
    seller: string;
    sales_count: number;
    total_commission: number;
};

export default function CommissionBySellerPage() {
    const [rows, setRows] = useState<SellerRow[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        load();
    }, []);

    const load = async () => {
        try {
            const res = await api.get('commissions/by-seller');
            if (res.data.active) {
                setRows(res.data.sellers);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    };

    const money = (v: number) =>
        new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP'
        }).format(v || 0);

    if (loading) return <div className="p-6">Cargando…</div>;

    return (
        <div className="p-6 max-w-5xl mx-auto">
            <h1 className="text-2xl font-bold mb-4">
                Comisiones por vendedor (COP)
            </h1>

            <div className="bg-white shadow rounded overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-gray-100">
                        <tr>
                            <th className="p-2 text-left">Vendedor</th>
                            <th className="p-2 text-right">Ventas</th>
                            <th className="p-2 text-right">Comisión total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(r => (
                            <tr key={r.user_id} className="border-t hover:bg-gray-50">
                                <td className="p-2">{r.seller}</td>
                                <td className="p-2 text-right">{r.sales_count}</td>
                                <td className="p-2 text-right font-semibold">
                                    {money(r.total_commission)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
