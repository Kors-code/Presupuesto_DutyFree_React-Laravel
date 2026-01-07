import api from '../../../api/axios';

export const getRoles = async () => {
    // devuelve array de roles: [{id, name}, ...]
    const { data } = await api.get('/roles');
    // data puede ser el array o un objeto con data.roles -> normalize
    if (Array.isArray(data)) return data;
    if (Array.isArray(data?.roles)) return data.roles;
    if (Array.isArray(data?.data)) return data.data;
    return []; // fallback seguro
};

export const getCategoriesWithCommission = async (roleId: number) => {
    // backend devuelve { categories: [...] }
    const { data } = await api.get('/commissions/categories', { params: { role_id: roleId } });
    // normalize a { categories: [] }
    if (data?.categories && Array.isArray(data.categories)) {
        return { categories: data.categories };
    }
    // si backend devolviera array directo
    if (Array.isArray(data)) return { categories: data };
    return { categories: [] };
};

export const upsertCategoryCommission = async (payload: {
    category_id: number;
    role_id: number;
    commission_percentage: number;
    min_pct_to_qualify?: number;
}) => {
    const { data } = await api.post('/commissions/categories', payload);
    return data;
};

export const deleteCategoryCommission = async (id: number) => {
    const { data } = await api.delete(`commissions/categories/${id}`);
    return data;
};

export const bulkSaveCategoryCommissions = async (roleId: number, items: Array<{ category_id: number, commission_percentage: number, min_pct_to_qualify?: number }>) => {
    const { data } = await api.post('/commissions/categories/bulk', { role_id: roleId, items });
    return data;
};
