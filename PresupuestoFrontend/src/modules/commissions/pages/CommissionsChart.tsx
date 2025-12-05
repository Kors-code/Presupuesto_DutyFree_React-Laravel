import { Line } from "react-chartjs-2";

export default function CommissionsChart({ items }: any) {
    const data = {
        labels: items.map((i: any) => i.sale.sale_date),
        datasets: [{
            label: "Comisión diaria",
            data: items.map((i: any) => i.commission_amount)
        }]
    };

    return <Line data={data} />;
}
