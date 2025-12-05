import { BrowserRouter, Routes, Route } from "react-router-dom";
import BudgetsListPage from "../modules/budgets/pages/BudgetsListPage";
import BudgetCreatePage from "../modules/budgets/pages/BudgetCreatePage";
import BudgetEditPage from "../modules/budgets/pages/BudgetEditPage";
import CommissionCategoriesPage from "../modules/commissions/pages/CommissionCategoriesPage";
import CommissionRangesPage from "../modules/commissions/pages/CommissionRangesPage";
import ImportSalesPage from "../modules/imports/pages/ImportSales";
import CommissionsPage from "../modules/commissions/pages/CommissionsPage";
import ImportSales from "../modules/imports/pages/ImportSales";




export default function AppRouter() {
    return (
        <BrowserRouter>
            <Routes>
                <Route path="/import-sales" element={<ImportSalesPage />} />

                <Route path="/budgets" element={<BudgetsListPage />} />
                <Route path="/budgets/create" element={<BudgetCreatePage />} />
                <Route path="/budgets/:id/edit" element={<BudgetEditPage />} />

                <Route path="/commissions/:id" element={<CommissionRangesPage />} />
                <Route path="/imports" element={<ImportSales />} />
                <Route path="/commissions" element={<CommissionsPage />} />
            </Routes>
        </BrowserRouter>
    );
}