// src/modules/budgets/components/BudgetForm.tsx
import React, { useEffect, useState } from 'react';
import type { Budget } from '../../../api/budgets';

type Props = {
    initial?: Partial<Budget>;
    onSubmit: (payload: Partial<Budget>) => Promise<void>;
    onCancel?: () => void;
};

export default function BudgetForm({ initial, onSubmit, onCancel }: Props) {
    const [month, setMonth] = useState(initial?.month ?? '');
    const [amount, setAmount] = useState(initial?.amount?.toString() ?? '');
    const [totalTurns, setTotalTurns] = useState(initial?.total_turns?.toString() ?? '');
    const [trm, setTrm] = useState(initial?.trm?.toString() ?? '');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setMonth(initial?.month ?? '');
        setAmount(initial?.amount?.toString() ?? '');
        setTotalTurns(initial?.total_turns?.toString() ?? '');
        setTrm(initial?.trm?.toString() ?? '');
    }, [initial]);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setError(null);
        setLoading(true);
        try {
            const payload: Partial<Budget> = {
                month,
                amount: Number(amount),
                total_turns: totalTurns ? Number(totalTurns) : undefined,
                trm: trm ? Number(trm) : undefined,
            };
            await onSubmit(payload);
        } catch (err: any) {
            setError(err?.response?.data?.message || err?.message || 'Error');
        } finally {
            setLoading(false);
        }
    };

    return (
        <form onSubmit={submit} style={{ border: '1px solid #eee', padding: 12, marginBottom: 12 }}>
            {error && <div style={{ color: 'red' }}>{error}</div>}
            <div>
                <label>Mes (YYYY-MM)</label><br />
                <input type="month" value={month} onChange={e => setMonth(e.target.value)} required />
            </div>
            <div>
                <label>Monto</label><br />
                <input type="number" value={amount} onChange={e => setAmount(e.target.value)} required />
            </div>
            <div>
                <label>Turnos totales</label><br />
                <input type="number" value={totalTurns} onChange={e => setTotalTurns(e.target.value)} />
            </div>
            <div>
                <label>TRM</label><br />
                <input type="number" value={trm} onChange={e => setTrm(e.target.value)} />
            </div>
            <div style={{ marginTop: 8 }}>
                <button type="submit" disabled={loading}>{loading ? 'Guardando...' : 'Guardar'}</button>
                {onCancel && <button type="button" onClick={onCancel} style={{ marginLeft: 8 }}>Cancelar</button>}
            </div>
        </form>
    );
}
