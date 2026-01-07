import React from 'react';
import type { UserSummary } from '../types';

type Props = {
    user: UserSummary;
    active?: boolean;
    onClick: (u: UserSummary) => void;
};

export default function UserCard({ user, active, onClick }: Props) {
    return (
        <button
            onClick={() => onClick(user)}
            className={`text-left p-4 rounded-xl border transition-shadow hover:shadow-lg
        ${active ? 'ring-2 ring-indigo-400 bg-indigo-50' : 'bg-white'}`}
        >
            <div className="flex items-center justify-between">
                <div>
                    <div className="text-sm text-gray-500">{user.type === 'seller' ? 'Vendedor' : 'Cajero'}</div>
                    <div className="mt-1 font-semibold text-lg">{user.label}</div>
                </div>
                <div className="text-indigo-600 font-bold text-xl">{user.sales_count}</div>
            </div>
            <div className="text-xs text-gray-400 mt-2">Clic para ver ventas</div>
        </button>
    );
}
