import React, { useState } from 'react';
import { importSalesFile } from '../../../services/importService';

export default function ImportSales() {
    const [file, setFile] = useState<File | null>(null);
    const [msg, setMsg] = useState<string>('');
    const [loading, setLoading] = useState(false);

    const handleUpload = async () => {
        if (!file) return setMsg('Selecciona un archivo');
        setLoading(true);
        setMsg('');
        try {
            const res = await importSalesFile(file);
            setMsg(`Import OK: ${res.data.rows} filas (batch ${res.data.batch_id})`);
        } catch (err: any) {
            setMsg(err?.response?.data?.message || err?.message || 'Error importando');
        } finally { setLoading(false); }
    };

    return (
        <div className="p-6 max-w-lg mx-auto">
            <h1 className="text-2xl font-bold mb-4">Importar ventas</h1>

            <input type="file" accept=".xlsx,.xls,.csv" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            <div className="mt-4">
                <button onClick={handleUpload} disabled={loading} className="px-4 py-2 bg-[#840028] text-white rounded">
                    {loading ? 'Importando...' : 'Subir y procesar'}
                </button>
            </div>

            {msg && <div className="mt-4 p-2 bg-gray-100 rounded">{msg}</div>}
        </div>
    );
}
