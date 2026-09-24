import { lazy, Suspense, useEffect } from "react";
import {
  BrowserRouter,
  Routes,
  Route,
  Navigate,
  useNavigate,
  useLocation,
} from "react-router-dom";
import LandingPage from "./pages/LandingPage";
import Login from "./pages/Login";
import Register from "./pages/Register";
import VerifyEmail from "./pages/VerifyEmail";
import ForgotPassword from "./pages/ForgotPassword";
import ResetPassword from "./pages/ResetPassword";
import ChangePassword from "./pages/ChangePassword";
import AppLayout from "./components/AppLayout";
import { useAuthStore } from "./store/authStore";
import { useSettingsStore } from "./store/settingsStore";
import { logout } from "./api/login";
import { initEcho, disconnectEcho } from "./lib/echo";
import { getNotifications } from "./api/notifications";
import { useNotificationStore } from "./store/notificationStore";
import PreferencesProvider from "./components/PreferencesProvider";
import PageState from "./components/PageState";
import { useTranslation } from "./hooks/useTranslation";
import "./App.css";

const Dashboard = lazy(() => import("./pages/Dashboard"));
const Products = lazy(() => import("./pages/Products"));
const Stock = lazy(() => import("./pages/Stock"));
const Categories = lazy(() => import("./pages/Categories"));
const Suppliers = lazy(() => import("./pages/Suppliers"));
const Reports = lazy(() => import("./pages/Reports"));
const ActivityLogs = lazy(() => import("./pages/ActivityLogs"));
const Users = lazy(() => import("./pages/Users"));
const Cms = lazy(() => import("./pages/Cms"));
const Warehouse3DMap = lazy(() => import("./pages/Warehouse3DMap"));
const WarehouseLayout = lazy(() => import("./pages/WarehouseLayout"));
const WarehouseOperations = lazy(() => import("./pages/WarehouseOperations"));
const ShipmentsGlobalMap = lazy(() => import("./pages/shipment/GlobalMap"));
const MyShipments = lazy(() => import("./pages/shipment/MyShipments"));
const ShipmentAlerts = lazy(() => import("./pages/shipment/ShipmentAlerts"));
const SupplyChainControlTower = lazy(() => import("./pages/SupplyChainControlTower"));
const DailySales = lazy(() => import("./pages/DailySales"));
const Superadmin = lazy(() => import("./pages/Superadmin"));
const PurchaseOrders = lazy(() => import("./pages/PurchaseOrders"));
const Procurement = lazy(() => import("./pages/Procurement"));
const QualityManagement = lazy(() => import("./pages/QualityManagement"));
const CustomerDebts = lazy(() => import("./pages/CustomerDebts"));
const Invoices = lazy(() => import("./pages/Invoices"));
const FinanceCenter = lazy(() => import("./pages/FinanceCenter"));
const MobileWarehouse = lazy(() => import("./pages/MobileWarehouse"));
const OperationsCenter = lazy(() => import("./pages/OperationsCenter"));
const MoneyAccounts = lazy(() => import("./pages/MoneyAccounts"));
const AccountingCore = lazy(() => import("./pages/AccountingCore"));
const SystemIntegrity = lazy(() => import("./pages/SystemIntegrity"));
const Fulfillment = lazy(() => import("./pages/Fulfillment"));
const OrderHub = lazy(() => import("./pages/OrderHub"));
const OrderTracking = lazy(() => import("./pages/OrderTracking"));
const OrderPortal = lazy(() => import("./pages/OrderPortal"));
const Documents = lazy(() => import("./pages/Documents"));

function PageLoader() {
  const { t } = useTranslation();
  return <p className="page-message" role="status">{t("common.loading")}</p>;
}

function WarehouseFeatureRoute({ children }) {
  const enable3dMap = useSettingsStore((state) => state.enable_3d_map);

  if (!enable3dMap) {
    return <Navigate to="/dashboard" replace />;
  }

  return children;
}

function PermissionRoute({ permission, children }) {
  const permissions = useAuthStore((state) => state.permissions);

  if (!permissions.includes(permission)) {
    return <PageState forbidden />;
  }

  return children;
}

function ProtectedRoute({ children, adminOnly = false, allowedRoles = null }) {
  const { isAuthenticated, mustChangePassword, role, authReady } =
    useAuthStore();

  if (!authReady) {
    return <PageLoader />;
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (mustChangePassword) {
    return <Navigate to="/change-password" replace />;
  }

  if (adminOnly && !["admin", "superadmin"].includes(role)) {
    return <Navigate to="/dashboard" replace />;
  }

  if (allowedRoles && !allowedRoles.includes(role)) {
    return <Navigate to="/dashboard" replace />;
  }

  return children;
}

function RoleAppGate({ children }) {
  const role = useAuthStore((state) => state.role);
  const location = useLocation();

  if (role === "superadmin" && location.pathname !== "/superadmin") {
    return <Navigate to="/superadmin" replace />;
  }

  return children;
}

function RealtimeProvider({ children }) {
  const { token, user, isAuthenticated, mustChangePassword } = useAuthStore();
  const setNotifications = useNotificationStore(
    (state) => state.setNotifications,
  );
  const addNotification = useNotificationStore(
    (state) => state.addNotification,
  );

  useEffect(() => {
    if (!isAuthenticated || mustChangePassword || !token || !user?.company_id) {
      disconnectEcho();
      return undefined;
    }

    getNotifications()
      .then((data) => setNotifications(data.notifications || []))
      .catch(() => setNotifications([]));

    // Electron already owns the local backend and intentionally uses file
    // cache + database polling. Do not create a Reverb socket that cannot
    // exist in the standalone/offline runtime.
    const echo = window.__AIMS_API_BASE__
      ? null
      : initEcho(token, user.company_id);
    let refreshInFlight = false;
    const refreshQuietly = async () => {
      if (document.visibilityState !== "visible" || refreshInFlight) return;

      refreshInFlight = true;
      try {
        const data = await getNotifications();
        setNotifications(data.notifications || []);
      } catch {
        // A temporary network/database failure must not clear the current UI.
      } finally {
        refreshInFlight = false;
      }

      window.dispatchEvent(
        new CustomEvent("database-refresh", {
          detail: { source: "background-poll", silent: true },
        }),
      );
    };
    const handleVisibilityChange = () => {
      if (document.visibilityState === "visible") void refreshQuietly();
    };
    const poll = window.setInterval(refreshQuietly, 15000);
    document.addEventListener("visibilitychange", handleVisibilityChange);
    if (!echo) {
      return () => {
        document.removeEventListener("visibilitychange", handleVisibilityChange);
        window.clearInterval(poll);
      };
    }

    const channel = echo.private(`company.${user.company_id}`);

    channel.error((error) => {
      console.error("[Echo] Channel subscription failed", error);
    });

    channel.listen(".notification.created", (event) => {
      if (event.notification) {
        addNotification(event.notification);
      }
    });

    channel.listen(".dashboard.updated", (event) => {
      window.dispatchEvent(new CustomEvent("dashboard-refresh", { detail: event }));
    });

    channel.listen(".stock.updated", (event) => {
      window.dispatchEvent(new CustomEvent("stock-refresh", { detail: event }));
    });

    return () => {
      channel.stopListening(".notification.created");
      channel.stopListening(".dashboard.updated");
      channel.stopListening(".stock.updated");
      disconnectEcho();
      document.removeEventListener("visibilitychange", handleVisibilityChange);
      window.clearInterval(poll);
    };
  }, [
    token,
    user?.company_id,
    isAuthenticated,
    mustChangePassword,
    setNotifications,
    addNotification,
  ]);

  return children;
}

function AuthEvents() {
  const navigate = useNavigate();
  const clearAuth = useAuthStore((state) => state.clearAuth);
  const setTokens = useAuthStore((state) => state.setTokens);
  const updateUser = useAuthStore((state) => state.updateUser);

  useEffect(() => {
    const handleUnauthorized = () => {
      disconnectEcho();
      clearAuth();
      navigate("/");
    };

    const handlePasswordChangeRequired = () => {
      navigate("/change-password");
    };

    const handleTokenRefreshed = (event) => {
      setTokens(event.detail.accessToken, event.detail.refreshToken);
      if (event.detail.user) {
        updateUser(event.detail.user);
      }
    };

    window.addEventListener("auth-unauthorized", handleUnauthorized);
    window.addEventListener(
      "password-change-required",
      handlePasswordChangeRequired,
    );
    window.addEventListener("auth-token-refreshed", handleTokenRefreshed);
    return () => {
      window.removeEventListener("auth-unauthorized", handleUnauthorized);
      window.removeEventListener(
        "password-change-required",
        handlePasswordChangeRequired,
      );
      window.removeEventListener("auth-token-refreshed", handleTokenRefreshed);
    };
  }, [navigate, clearAuth, setTokens, updateUser]);

  return null;
}

function AppRoutes() {
  const navigate = useNavigate();
  const {
    hydrate,
    syncSession,
    isAuthenticated,
    mustChangePassword,
    authReady,
    hasValidSession,
    setAuth,
    updateUser,
    clearAuth,
  } = useAuthStore();
  const isDesktop = Boolean(window.__AIMS_API_BASE__);

  useEffect(() => {
    hydrate();
  }, [hydrate]);

  useEffect(() => {
    if (!authReady || !isAuthenticated || mustChangePassword) return;
    syncSession();
  }, [authReady, isAuthenticated, mustChangePassword, syncSession]);

  const handleAuthSuccess = (userData) => {
    const accessToken = userData.access_token || userData.token;
    setAuth(accessToken, userData.user, userData.refresh_token);
    if (userData.user?.preferences) {
      useSettingsStore.getState().loadFromUser(userData.user.preferences);
    }
    navigate(
      userData.user.must_change_password
        ? "/change-password"
        : userData.user.role === "superadmin"
          ? "/superadmin"
          : "/dashboard",
      { replace: true },
    );
  };

  const handleRegisterSuccess = (data) => {
    if (data.verification_required) {
      navigate(`/verify-email?email=${encodeURIComponent(data.email)}`, {
        replace: true,
      });
      return;
    }
    handleAuthSuccess(data);
  };

  const handlePasswordChanged = (data) => {
    const token = useAuthStore.getState().token;
    updateUser(data.user);
    if (token) {
      setAuth(token, data.user);
    }
    navigate(data.user?.role === "superadmin" ? "/superadmin" : "/dashboard", {
      replace: true,
    });
  };

  const handleLogout = async () => {
    try {
      await logout();
    } catch {
    } finally {
      disconnectEcho();
      clearAuth();
    }
  };

  return (
    <>
      <AuthEvents />
      <Routes>
        <Route
          path="/"
          element={
            isDesktop ? (
              <Navigate to="/login" replace />
            ) : (
              <LandingPage
                isAuthenticated={isAuthenticated}
                onLogin={() => window.location.assign("/login")}
                onRegister={() => window.location.assign("/register")}
                onOpenDashboard={() =>
                  window.location.assign(
                    isAuthenticated && !mustChangePassword
                      ? "/dashboard"
                      : "/login",
                  )
                }
              />
            )
          }
        />
        <Route
          path="/login"
          element={
            !authReady ? (
              <PageLoader />
            ) : hasValidSession() ? (
              <Navigate
                to={mustChangePassword ? "/change-password" : "/dashboard"}
                replace
              />
            ) : (
              <Login
                onLoginSuccess={handleAuthSuccess}
                onBackHome={() => navigate("/")}
                onRegister={() => navigate("/register")}
              />
            )
          }
        />
        <Route
          path="/register"
          element={
            isDesktop ? (
              <Navigate to="/login" replace />
            ) : !authReady ? (
              <PageLoader />
            ) : isAuthenticated && !mustChangePassword ? (
              <Navigate to="/dashboard" replace />
            ) : (
              <Register
                onRegisterSuccess={handleRegisterSuccess}
                onBackHome={() => window.location.assign("/")}
                onLogin={() => window.location.assign("/login")}
              />
            )
          }
        />
        <Route path="/verify-email" element={<VerifyEmail />} />
        <Route
          path="/forgot-password"
          element={
            isDesktop ? (
              <Navigate to="/login" replace />
            ) : (
              <ForgotPassword onBackLogin={() => navigate("/login")} />
            )
          }
        />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route
          path="/change-password"
          element={
            !isAuthenticated ? (
              <Navigate to="/login" replace />
            ) : (
              <ChangePassword
                requiresChange={mustChangePassword}
                onPasswordChanged={handlePasswordChanged}
                onLogout={handleLogout}
              />
            )
          }
        />
        <Route
          element={
            <ProtectedRoute>
              <RoleAppGate>
                <PreferencesProvider>
                  <RealtimeProvider>
                    <AppLayout />
                  </RealtimeProvider>
                </PreferencesProvider>
              </RoleAppGate>
            </ProtectedRoute>
          }
        >
          <Route
            path="/dashboard"
            element={
              <Suspense fallback={<PageLoader />}>
                <Dashboard />
              </Suspense>
            }
          />
          <Route path="/order-hub" element={<Suspense fallback={<PageLoader />}><OrderHub /></Suspense>} />
          <Route
            path="/products"
            element={
              <Suspense fallback={<PageLoader />}>
                <Products />
              </Suspense>
            }
          />
          <Route
            path="/stock"
            element={
              <Suspense fallback={<PageLoader />}>
                <Stock />
              </Suspense>
            }
          />
          <Route
            path="/categories"
            element={
              <Suspense fallback={<PageLoader />}>
                <Categories />
              </Suspense>
            }
          />
          <Route
            path="/suppliers"
            element={
              <Suspense fallback={<PageLoader />}>
                <Suppliers />
              </Suspense>
            }
          />
          <Route
            path="/reports"
            element={
              <Suspense fallback={<PageLoader />}>
                <Reports />
              </Suspense>
            }
          />
          <Route
            path="/finance"
            element={
              <PermissionRoute permission="finance.view">
                <Suspense fallback={<PageLoader />}>
                  <FinanceCenter />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route
            path="/money-accounts"
            element={
              <PermissionRoute permission="financial_accounts.view">
                <Suspense fallback={<PageLoader />}>
                  <MoneyAccounts />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route path="/accounting" element={<PermissionRoute permission="accounting.reports.view"><Suspense fallback={<PageLoader />}><AccountingCore /></Suspense></PermissionRoute>} />
          <Route path="/system-integrity" element={<PermissionRoute permission="system_integrity.view"><Suspense fallback={<PageLoader />}><SystemIntegrity /></Suspense></PermissionRoute>} />
          <Route
            path="/invoices"
            element={
              <PermissionRoute permission="invoices.manage">
                <Suspense fallback={<PageLoader />}>
                  <Invoices />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route
            path="/purchase-orders"
            element={
              <Suspense fallback={<PageLoader />}>
                <PurchaseOrders />
              </Suspense>
            }
          />
          <Route path="/fulfillment" element={<PermissionRoute permission="fulfillment.view"><Suspense fallback={<PageLoader />}><Fulfillment /></Suspense></PermissionRoute>} />
          <Route path="/documents" element={<PermissionRoute permission="documents.view"><Suspense fallback={<PageLoader />}><Documents /></Suspense></PermissionRoute>} />
          <Route path="/procurement" element={<PermissionRoute permission="procurement.view"><Suspense fallback={<PageLoader />}><Procurement /></Suspense></PermissionRoute>} />
          <Route path="/quality" element={<PermissionRoute permission="quality.view"><Suspense fallback={<PageLoader />}><QualityManagement /></Suspense></PermissionRoute>} />
          <Route
            path="/customer-debts"
            element={
              <Suspense fallback={<PageLoader />}>
                <CustomerDebts />
              </Suspense>
            }
          />
          <Route
            path="/daily-sales"
            element={
              <Suspense fallback={<PageLoader />}>
                <DailySales />
              </Suspense>
            }
          />
          <Route
            path="/shipments"
            element={<Navigate to="/shipments/my-shipments" replace />}
          />
          <Route
            path="/shipments/global-map"
            element={
              <Suspense fallback={<PageLoader />}>
                <ShipmentsGlobalMap />
              </Suspense>
            }
          />
          <Route
            path="/control-tower"
            element={
              <PermissionRoute permission="control_tower.view">
                <Suspense fallback={<PageLoader />}>
                  <SupplyChainControlTower />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route
            path="/control-tower/:shipmentId"
            element={
              <PermissionRoute permission="control_tower.view">
                <Suspense fallback={<PageLoader />}>
                  <SupplyChainControlTower />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route
            path="/shipments/my-shipments"
            element={
              <Suspense fallback={<PageLoader />}>
                <MyShipments />
              </Suspense>
            }
          />
          <Route
            path="/shipments/alerts"
            element={
              <Suspense fallback={<PageLoader />}>
                <ShipmentAlerts />
              </Suspense>
            }
          />
          <Route
            path="/users"
            element={
              <ProtectedRoute adminOnly>
                <Suspense fallback={<PageLoader />}>
                  <Users />
                </Suspense>
              </ProtectedRoute>
            }
          />
          <Route
            path="/activity-logs"
            element={
              <ProtectedRoute adminOnly>
                <Suspense fallback={<PageLoader />}>
                  <ActivityLogs />
                </Suspense>
              </ProtectedRoute>
            }
          />
          <Route
            path="/cms"
            element={
              <ProtectedRoute adminOnly>
                <Suspense fallback={<PageLoader />}>
                  <Cms />
                </Suspense>
              </ProtectedRoute>
            }
          />
          <Route
            path="/superadmin"
            element={
              <ProtectedRoute allowedRoles={["superadmin"]}>
                <Suspense fallback={<PageLoader />}>
                  <Superadmin />
                </Suspense>
              </ProtectedRoute>
            }
          />
          <Route
            path="/warehouse-operations"
            element={
              <PermissionRoute permission="transfers.view">
                <Suspense fallback={<PageLoader />}>
                  <WarehouseOperations />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route
            path="/warehouse-mobile"
            element={
              <PermissionRoute permission="warehouse_mobile.use">
                <Suspense fallback={<PageLoader />}>
                  <MobileWarehouse />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route
            path="/operations-center"
            element={
              <PermissionRoute permission="inventory.view">
                <Suspense fallback={<PageLoader />}>
                  <OperationsCenter />
                </Suspense>
              </PermissionRoute>
            }
          />
          <Route
            path="/warehouse-layout"
            element={
              <WarehouseFeatureRoute>
                <Suspense fallback={<PageLoader />}>
                  <WarehouseLayout />
                </Suspense>
              </WarehouseFeatureRoute>
            }
          />
        </Route>
        <Route
          path="/warehouse-3d"
          element={
            <ProtectedRoute>
              <PreferencesProvider>
              <WarehouseFeatureRoute>
                <PermissionRoute permission="inventory.view"><Suspense fallback={<PageLoader />}>
                  <Warehouse3DMap />
                </Suspense></PermissionRoute>
              </WarehouseFeatureRoute>
              </PreferencesProvider>
            </ProtectedRoute>
          }
        />
        <Route path="/order-tracking/:token" element={<Suspense fallback={<PageLoader />}><OrderTracking /></Suspense>} />
        <Route path="/order-portal" element={<Suspense fallback={<PageLoader />}><OrderPortal /></Suspense>} />
        <Route
          path="/settings"
          element={<Navigate to="/dashboard" replace />}
        />
        <Route
          path="*"
          element={<PageState />}
        />
      </Routes>
    </>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AppRoutes />
    </BrowserRouter>
  );
}
