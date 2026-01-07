import { BrowserRouter, Routes, Route } from "react-router-dom";
import MainLayout from "../layout/MainLayout";
import HomePage from "../pages/HomePage";

/* TUS MÓDULOS */
import BudgetPage from "../modules/budgets/pages/BudgetPage";
import BudgetDailyProgressPage from "../modules/budgets/pages/BudgetDailyProgressPage";
import CommissionRangesPage from "../modules/commissions/pages/CommissionRangesPage";
import ImportSalesPage from "../modules/imports/pages/ImportSales";
import ImportsListPage from "../modules/imports/pages/ImportsListPage";
import CommissionsPage from "../modules/commissions/pages/CommissionsPage";
import CategoryCommissionsPage from "../modules/commissions/pages/CategoryCommissionsPage";
import CommissionCardsPage from "../modules/commissions/pages/CommissionCardsPage";
import CommissionsByUserPage from "../modules/commissions/pages/CommissionsByUserPage";
import UsersPage from "../modules/users/pages/UsersPage";
import SalesByUserPage from "../modules/sales/pages/SalesByUserPage";

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        {/* Todas las rutas usan el layout (navbar visible en todas) */}
        <Route element={<MainLayout />}>
          <Route path="/" element={<HomePage />} />

          <Route path="/users" element={<UsersPage />} />
          <Route path="/import-sales" element={<ImportSalesPage />} />
          <Route path="/importList" element={<ImportsListPage />} />

          <Route path="/budget" element={<BudgetPage />} />
          <Route path="/BudgetDailyProgressPage" element={<BudgetDailyProgressPage />} />

          <Route path="/commissions/:id" element={<CommissionRangesPage />} />
          <Route path="/comissions" element={<CommissionsPage />} />
          <Route path="/CommissionCardsPage" element={<CommissionCardsPage />} />
          <Route path="/CommissionsByUserPage" element={<CommissionsByUserPage />} />
          <Route path="/sales-by-user" element={<SalesByUserPage />} />
          <Route path="/commissions/categories" element={<CategoryCommissionsPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
