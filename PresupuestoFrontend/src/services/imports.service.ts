import api from '../api/axios';

const base = '/imports';

export const getImports = async () => {
    const { data } = await api.get(base);
    return data;
};

export const getImport = async (id: number) => {
    const { data } = await api.get(`${base}/${id}`);
    return data;
};

export const deleteImport = async (id: number) => {
    const { data } = await api.delete(`${base}/${id}`);
    return data;
};
