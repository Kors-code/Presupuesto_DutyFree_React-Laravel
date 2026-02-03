import { BrowserRouter, Routes, Route } from "react-router-dom";
import MainLayout from "../layout/MainLayout";
import HomePage from "../pages/HomePage";
import WelcomePage from "../pages/WelcomePage";

/* TUS MÓDULOS */
import BudgetPage from "../modules/budgets/pages/BudgetPage";
import ImportsManagerPage from "../modules/imports/pages/ImportsManagerPage";
import CategoryCommissionsPage from "../modules/commissions/pages/CategoryCommissionsPage";
import CommissionCardsPage from "../modules/commissions/pages/CommissionCardsPage";
import CommisionCashier from "../modules/commissions/pages/CommisionCashier";
import CommisionCashierUsers from "../modules/commissions/pages/CommisionCashierUsers";
import CommisionsUser from "../modules/commissions/pages/CommisionsUser";
import UsersPage from "../modules/users/pages/UsersPage";




export default function AppRouter() {
  return (
    <BrowserRouter basename="/panel">

            

      <Routes>
          <Route path="/WelcomePage" element={
            
                    <WelcomePage />

        } />
          <Route path="/CommisionsUser"  element={<CommisionsUser />} />
          <Route path="/CashierAwardsUsers" element={<CommisionCashierUsers />} />
        {/* Todas las rutas usan el layout (navbar visible en todas) */}
        <Route element={<MainLayout />}>
          <Route path="/" element={<HomePage />} />

          <Route path="/users" element={<UsersPage />} />
          <Route path="/ImportsManagerPage" element={<ImportsManagerPage />} />

          <Route path="/budget" element={<BudgetPage />} />

          <Route path="/CommissionCardsPage" element={<CommissionCardsPage />} />
          <Route path="/CashierAwards" element={<CommisionCashier />} />
          <Route path="/commissions/categories" element={<CategoryCommissionsPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
