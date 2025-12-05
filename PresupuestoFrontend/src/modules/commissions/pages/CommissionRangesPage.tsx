import { useEffect, useState } from "react";
import {
    getRanges,
    createRange,
    updateRange,
    deleteRange
} from "../../../api/commissions";
import { useParams } from "react-router-dom";
import RangeForm from "../components/RangeForm";

export default function CommissionRangesPage() {
    const { id } = useParams();
    const categoryId = Number(id);

    const [ranges, setRanges] = useState<any[]>([]);
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState<any | null>(null);

    const load = async () => {
        const res = await getRanges(categoryId);
        setRanges(res.data);
    };

    useEffect(() => { load(); }, []);

    const handleSubmit = async (data: any) => {
        if (editing) {
            await updateRange(editing.id, data);
        } else {
            await createRange(categoryId, data);
        }
        setShowForm(false);
        setEditing(null);
        load();
    };

    return (
        <div className="p-6 max-w-4xl mx-auto">
            <h1 className="text-3xl font-bold mb-6 text-primary">
                Rangos de Comisión (Categoría {categoryId})
            </h1>

            <button
                onClick={() => { setShowForm(true); setEditing(null); }}
                className="bg-primary text-white px-4 py-2 rounded mb-4"
            >
                + Nuevo Rango
            </button>

            {showForm && (
                <div className="bg-white p-4 rounded shadow mb-4">
                    <RangeForm onSubmit={handleSubmit} editing={editing} />
                </div>
            )}

            <table className="w-full border-collapse mt-4">
                <thead className="bg-gray-100">
                    <tr>
                        <th className="p-3">Mín</th>
                        <th className="p-3">Máx</th>
                        <th className="p-3">% Comisión</th>
                        <th className="p-3 text-center">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    {ranges.map(r => (
                        <tr key={r.id} className="border-b hover:bg-gray-50">
                            <td className="p-3">{r.min}</td>
                            <td className="p-3">{r.max}</td>
                            <td className="p-3">{r.commission}%</td>

                            <td className="p-3 flex gap-2 justify-center">
                                <button
                                    className="text-green-600"
                                    onClick={() => { setEditing(r); setShowForm(true); }}
                                >
                                    Editar
                                </button>

                                <button
                                    className="text-red-600"
                                    onClick={() => { deleteRange(r.id); load(); }}
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
