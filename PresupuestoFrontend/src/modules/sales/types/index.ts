export type UserSummary = {
    type: 'seller' | 'cashier';
    key: string;        // seller_id (stringified) o nombre del cajero
    label: string;      // lo que se muestra
    sales_count: number;
};

export type SaleRow = {
    id: number;
    sale_date: string | null;
    folio?: string | null;
    pdv?: string | null;
    product?: {
        id?: number;
        description?: string | null;
    } | null;
    quantity?: number;
    amount?: number;
    value_pesos?: number | null;
    value_usd?: number | null;
    currency?: string | null;
    cashier?: string | null;
};
