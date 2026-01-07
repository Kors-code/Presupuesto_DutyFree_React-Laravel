import api from '../../../api/axios';

export const getActiveBudget = async () => {
    const res = await api.get('budgets/active');
    return res.data;
};

export const getBudgets = async () => {
    const res = await api.get('budgets');
    return res.data;
};

export const createBudget = async (payload: any) => {
    const res = await api.post('budgets', payload);
    return res.data;
};
