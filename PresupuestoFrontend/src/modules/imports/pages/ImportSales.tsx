// src/pages/imports/ImportsPage.tsx
import React, { useEffect, useState } from 'react';
import {
  importSalesFile,
  getImports,
  getImport,
  deleteImport,
  deleteImports,
} from '../../../services/imports.service';
import type { ImportBatch } from '../../../services/imports.service';

export default function ImportsPage() {
  const [batches, setBatches] = useState<ImportBatch[]>([]);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [selectedBatch, setSelectedBatch] = useState<ImportBatch | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [msg, setMsg] = useState<string>('');

  useEffect(() => { load(); }, []);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const res = await getImports();
      // si backend devuelve objeto paginado, manejamos ambos casos
      const data = Array.isArray(res) ? res : (res.data ?? res);
      setBatches(data);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || 'Error cargando imports');
    } finally {
      setLoading(false);
    }
  }

  async function handleUpload() {
    if (!file) {
      setMsg('Selecciona un archivo');
      return;
    }
    setUploading(true);
    setMsg('');
    setError(null);
    try {
      const res = await importSalesFile(file);
      // backend puede devolver 'rows' o 'processed'
      const rows = res.data.rows ?? res.data.processed ?? null;
      const batchId = res.data.batch_id ?? null;
      setMsg(`Import OK${rows !== null ? `: ${rows} filas` : ''}${batchId ? ` (batch ${batchId})` : ''}`);
      setFile(null);
      // refrescar lista
      await load();
    } catch (err: any) {
      setMsg('');
      setError(err?.response?.data?.message || err?.message || 'Error importando');
    } finally {
      setUploading(false);
    }
  }

  function toggleSelect(id: number) {
    setSelectedIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
  }

  function toggleSelectAll() {
    const allIds = batches.map(b => b.id);
    const allSelected = allIds.every(id => selectedIds.includes(id)) && allIds.length > 0;
    setSelectedIds(allSelected ? [] : allIds);
  }

  async function handleDelete(id: number) {
    if (!confirm('¿Eliminar este archivo y todas las ventas asociadas? Esta acción no se puede deshacer.')) return;
    setDeletingId(id);
    setError(null);
    try {
      await deleteImport(id);
      setBatches(prev => prev.filter(b => b.id !== id));
      setSelectedIds(prev => prev.filter(x => x !== id));
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || 'Error borrando batch');
    } finally {
      setDeletingId(null);
    }
  }

  async function handleBulkDelete() {
    if (selectedIds.length === 0) return;
    if (!confirm(`¿Eliminar ${selectedIds.length} archivos y todas sus ventas asociadas? Esto no se puede deshacer.`)) return;
    setBulkDeleting(true);
    setError(null);
    try {
      await deleteImports(selectedIds);
      setBatches(prev => prev.filter(b => !selectedIds.includes(b.id)));
      setSelectedIds([]);
    } catch (e: any) {
      setError(e?.response?.data?.message || e?.message || 'Error eliminando batches');
    } finally {
      setBulkDeleting(false);
    }
  }



  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <h1 className="text-2xl font-bold">Importaciones</h1>

      {error && <div className="text-red-600">{error}</div>}
      {msg && <div className="p-2 bg-green-100 rounded text-sm">{msg}</div>}

      {/* Uploader */}
      <div className="bg-white p-4 rounded shadow flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div className="flex items-center gap-3">
          <input
            type="file"
            accept=".csv,.xlsx,.xls"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            className="text-sm"
          />
          <div className="text-sm text-gray-600">{file ? file.name : 'No hay archivo seleccionado'}</div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={handleUpload}
            disabled={uploading}
            className="px-4 py-2 bg-[#840028] text-white rounded disabled:opacity-50"
          >
            {uploading ? 'Importando...' : 'Subir y procesar'}
          </button>
          <button
            onClick={() => { setFile(null); setMsg(''); setError(null); }}
            className="px-3 py-2 border rounded text-sm"
          >
            Limpiar
          </button>
        </div>
      </div>

      {/* Tabla con selección */}
      <div className="bg-white rounded shadow overflow-x-auto">
        <div className="p-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                onChange={toggleSelectAll}
                checked={batches.length > 0 && batches.every(d => selectedIds.includes(d.id))}
              />
              <span className="text-sm">Seleccionar todo</span>
            </label>

            <button
              onClick={handleBulkDelete}
              disabled={selectedIds.length === 0 || bulkDeleting}
              className="px-3 py-1 bg-red-600 text-white rounded text-sm disabled:opacity-50"
            >
              {bulkDeleting ? 'Eliminando...' : `Eliminar seleccionados (${selectedIds.length})`}
            </button>
          </div>

          <div className="text-sm text-gray-600">{batches.length} registros</div>
        </div>

        {loading ? (
          <div className="p-6 text-center">Cargando...</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 text-left">
              <tr>
                <th className="p-3 w-12"></th>
                <th className="p-3">Archivo</th>
                <th className="p-3">Filas</th>
                <th className="p-3">Estado</th>
                <th className="p-3">Fecha</th>
                <th className="p-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {batches.map(b => (
                <tr key={b.id} className="border-t">
                  <td className="p-3">
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(b.id)}
                      onChange={() => toggleSelect(b.id)}
                    />
                  </td>
                  <td className="p-3">{b.filename}</td>
                  <td className="p-3">{b.rows ?? '-'}</td>
                  <td className="p-3">{b.status ?? '-'}</td>
                  <td className="p-3">{b.created_at ? new Date(b.created_at).toLocaleString() : '-'}</td>
                  <td className="p-3 text-right">
                    <button
                      onClick={() => handleDelete(b.id)}
                      className="px-3 py-1 bg-red-600 text-white rounded text-sm disabled:opacity-50"
                      disabled={deletingId === b.id}
                    >
                      {deletingId === b.id ? 'Borrando...' : 'Eliminar'}
                    </button>
                  </td>
                </tr>
              ))}

              {batches.length === 0 && (
                <tr>
                  <td colSpan={6} className="p-6 text-center text-gray-500">No hay archivos importados</td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* Modal de detalles */}
      {selectedBatch && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-lg max-w-3xl w-full p-6">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-semibold">Detalles - {selectedBatch.filename}</h3>
              <button className="text-gray-500" onClick={() => setSelectedBatch(null)}>Cerrar</button>
            </div>
            <div className="space-y-2 text-sm text-gray-700">
              <div><strong>Estado:</strong> {selectedBatch.status}</div>
              <div><strong>Filas:</strong> {selectedBatch.rows ?? '-'}</div>
              <div><strong>Subido:</strong> {selectedBatch.created_at ? new Date(selectedBatch.created_at).toLocaleString() : '-'}</div>
              <div><strong>Note:</strong> {selectedBatch.note ?? '-'}</div>

              <pre className="whitespace-pre-wrap bg-gray-50 p-3 rounded text-xs">{JSON.stringify(selectedBatch, null, 2)}</pre>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
