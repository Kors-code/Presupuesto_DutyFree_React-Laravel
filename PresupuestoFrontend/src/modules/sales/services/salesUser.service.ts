import api from '../../../api/axios';
import type { UserSummary, SaleRow } from '../types';

class SalesUserService {
    async getUsers(): Promise<UserSummary[]> {
        const { data } = await api.get<UserSummary[]>('/sales/users');
        return data;
    }

    async getSalesByUser(params: {
        type: 'seller' | 'cashier';
        key: string | number;
        date_from?: string;
        date_to?: string;
    }): Promise<{ sales: SaleRow[] }> {
        const { data } = await api.get<{ sales: SaleRow[] }>('/sales/by-user', { params });
        return data;
    }
}

export default new SalesUserService();
