import React, { useEffect, useState } from 'react';
import { getImports, deleteImport, getImport } from '../../../services/imports.service.ts';

type ImportBatch = {
    id: number;
    filename: string;
    checksum?: string;
    status?: string;
    rows?: number;
    created_at?: string;
    note?: string;
};

export default function ImportsListPage() {
    const [batches, setBatches] = useState<ImportBatch[]>([]);
    const [loading, setLoading] = useState(false);
    const [deleting, setDeleting] = useState<number | null>(null);
    const [selected, setSelected] = useState<ImportBatch | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => { load(); }, []);

    async function load() {
        try {
            setLoading(true);
            const data = await getImports();
            setBatches(data);
            setLoading(false);
        } catch (e: any) {
            setLoading(false);
            setError(e?.message || 'Error cargando imports');
        }
    }

    async function onDelete(id: number) {
        if (!confirm('¿Eliminar este archivo y todas las ventas asociadas? Esta acción no se puede deshacer.')) return;
        try {
            setDeleting(id);
            await deleteImport(id);
            setBatches(prev => prev.filter(b => b.id !== id));
            setDeleting(null);
        } catch (e: any) {
            setDeleting(null);
            setError(e?.message || 'Error borrando batch');
        }
    }

    async function showDetails(id: number) {
        try {
            const batch = await getImport(id);
            setSelected(batch);
        } catch (e: any) {
            setError(e?.message || 'Error obteniendo detalles');
        }
    }

    return (
        <div className="p-6 max-w-6xl mx-auto">
            <h1 className="text-2xl font-bold mb-4">Archivos importados</h1>
            {error && <div className="mb-4 text-red-600">{error}</div>}
            {loading ? (
                <div className="p-4 bg-white rounded shadow">Cargando...</div>
            ) : (
                <div className="bg-white rounded shadow overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-gray-50 text-left">
                            <tr>
                                <th className="p-3">Archivo</th>
                                <th className="p-3">Filas importadas</th>
                                <th className="p-3">Estado</th>
                                <th className="p-3">Fecha</th>
                                <th className="p-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            {batches.map(b => (
                                <tr key={b.id} className="border-t">
                                    <td className="p-3">{b.filename}</td>
                                    <td className="p-3">{b.rows ?? '-'}</td>
                                    <td className="p-3">{b.status ?? '-'}</td>
                                    <td className="p-3">{b.created_at ? new Date(b.created_at).toLocaleString() : '-'}</td>
                                    <td className="p-3 text-right">
                                        <button onClick={() => showDetails(b.id)} className="mr-2 px-3 py-1 border rounded text-sm">Detalles</button>
                                        <button
                                            onClick={() => onDelete(b.id)}
                                            className="px-3 py-1 bg-red-600 text-white rounded text-sm disabled:opacity-50"
                                            disabled={deleting === b.id}
                                        >
                                            {deleting === b.id ? 'Borrando...' : 'Eliminar'}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {batches.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-gray-500">No hay archivos importados</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Detalles modal simple */}
            {selected && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="bg-white rounded-lg max-w-2xl w-full p-6">
                        <div className="flex justify-between items-center mb-4">
                            <h3 className="text-lg font-semibold">Detalles - {selected.filename}</h3>
                            <button className="text-gray-500" onClick={() => setSelected(null)}>Cerrar</button>
                        </div>
                        <div>
                            <pre className="whitespace-pre-wrap text-sm text-gray-700">{JSON.stringify(selected, null, 2)}</pre>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
