import { useEffect, useState } from "react";
import { getCommissions } from "../../../services/commissionsService";

export default function CommissionsPage() {
    const [loading, setLoading] = useState(false);
    const [items, setItems] = useState<any[]>([]);
    const [filters, setFilters] = useState({
        from: "",
        to: "",
        pdv: ""
    });

    const load = async () => {
        setLoading(true);
        const data = await getCommissions(filters);
        setItems(data.data || data);
        setLoading(false);
    };

    useEffect(() => {
        load();
    }, []);

    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-4">Comisiones</h1>

            {/* FILTROS */}
            <div className="grid grid-cols-3 gap-4 mb-4">
                <input type="date" value={filters.from}
                    onChange={(e) => setFilters({ ...filters, from: e.target.value })} />

                <input type="date" value={filters.to}
                    onChange={(e) => setFilters({ ...filters, to: e.target.value })} />

                <input placeholder="PDV"
                    value={filters.pdv}
                    onChange={(e) => setFilters({ ...filters, pdv: e.target.value })}
                />

                <button
                    className="bg-blue-600 text-white px-3 py-2 rounded"
                    onClick={load}>
                    Filtrar
                </button>
            </div>

            {/* TABLA */}
            <table className="table-auto w-full bg-white shadow">
                <thead>
                    <tr className="bg-gray-100">
                        <th className="p-2">Vendedor</th>
                        <th className="p-2">Fecha</th>
                        <th className="p-2">Folio</th>
                        <th className="p-2">PDV</th>
                        <th className="p-2">Monto</th>
                        <th className="p-2">Comisión</th>
                    </tr>
                </thead>
                <tbody>
                    {items.map((c: any) => (
                        <tr key={c.id} className="border-b">
                            <td className="p-2">{c.user?.name}</td>
                            <td className="p-2">{c.sale?.sale_date}</td>
                            <td className="p-2">{c.sale?.folio}</td>
                            <td className="p-2">{c.sale?.pdv}</td>
                            <td className="p-2">{c.sale?.amount}</td>
                            <td className="p-2 font-bold text-green-600">
                                {c.commission_amount}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
