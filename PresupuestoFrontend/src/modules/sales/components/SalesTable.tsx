import React from 'react';
import type { SaleRow } from '../types';

type Props = {
    sales: SaleRow[];
};

export default function SalesTable({ sales }: Props) {
    if (!sales?.length) {
        return <div className="p-4 text-center text-gray-500">No hay ventas para mostrar</div>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full table-auto border-collapse">
                <thead>
                    <tr className="bg-gray-100 text-left">
                        <th className="p-2 border">Fecha</th>
                        <th className="p-2 border">Folio</th>
                        <th className="p-2 border">PDV</th>
                        <th className="p-2 border">Producto</th>
                        <th className="p-2 border">Cantidad</th>
                        <th className="p-2 border">Monto</th>
                        <th className="p-2 border">Moneda</th>
                        <th className="p-2 border">Cajero</th>
                    </tr>
                </thead>
                <tbody>
                    {sales.map(s => (
                        <tr key={s.id} className="hover:bg-gray-50">
                            <td className="p-2 border">{s.sale_date ?? '-'}</td>
                            <td className="p-2 border">{s.folio ?? '-'}</td>
                            <td className="p-2 border">{s.pdv ?? '-'}</td>
                            <td className="p-2 border">{s.product?.description ?? '-'}</td>
                            <td className="p-2 border">{s.quantity ?? 0}</td>
                            <td className="p-2 border">{s.value_usd ?? s.amount ?? '-'}</td>
                            <td className="p-2 border">{s.currency ?? '-'}</td>
                            <td className="p-2 border">{s.cashier ?? '-'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
