import { useEffect, useState } from "react";
import {
    getCategories,
    createCategory,
    updateCategory,
    deleteCategory
} from "../../../api/commissions";
import CategoryForm from "../components/CategoryForm";
import { useNavigate } from "react-router-dom";

export default function CommissionCategoriesPage() {
    const [categories, setCategories] = useState<any[]>([]);
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState<any | null>(null);

    const navigate = useNavigate();

    const load = async () => {
        const res = await getCategories();
        setCategories(res.data);
    };

    useEffect(() => { load(); }, []);

    const handleSubmit = async (data: any) => {
        if (editing) {
            await updateCategory(editing.id, data);
        } else {
            await createCategory(data);
        }
        setShowForm(false);
        setEditing(null);
        load();
    };

    return (
        <div className="p-6 max-w-4xl mx-auto">
            <h1 className="text-3xl font-bold mb-6 text-primary">Categorías de Comisión</h1>

            <button
                onClick={() => { setShowForm(true); setEditing(null); }}
                className="bg-primary text-white px-4 py-2 rounded mb-4"
            >
                + Nueva Categoría
            </button>

            {showForm && (
                <div className="bg-white p-4 rounded shadow mb-4">
                    <CategoryForm onSubmit={handleSubmit} editing={editing} />
                </div>
            )}

            <table className="w-full border-collapse mt-4">
                <thead className="bg-gray-100">
                    <tr>
                        <th className="p-3">ID</th>
                        <th className="p-3">Nombre</th>
                        <th className="p-3 text-center">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    {categories.map(cat => (
                        <tr key={cat.id} className="border-b hover:bg-gray-50">
                            <td className="p-3">{cat.id}</td>
                            <td className="p-3">{cat.name}</td>
                            <td className="p-3 flex gap-2 justify-center">
                                <button
                                    className="text-blue-600"
                                    onClick={() => navigate(`/commissions/${cat.id}`)}
                                >
                                    Ver Rangos
                                </button>
                                <button
                                    className="text-green-600"
                                    onClick={() => { setEditing(cat); setShowForm(true); }}
                                >
                                    Editar
                                </button>
                                <button
                                    className="text-red-600"
                                    onClick={() => { deleteCategory(cat.id); load(); }}
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
