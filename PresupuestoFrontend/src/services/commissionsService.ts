import api from "../api/axios";

export const getCommissions = async (filters?: any) => {
    const params = new URLSearchParams(filters || {});
    const { data } = await api.get(`/v1/commissions?${params.toString()}`);
    return data.data;
};

export const getCommissionsByUser = async (userId: number) => {
    const { data } = await api.get(`/v1/commissions/by-user/${userId}`);
    return data;
};
