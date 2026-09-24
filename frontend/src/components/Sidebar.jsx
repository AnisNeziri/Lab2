import { useEffect, useMemo } from "react";
import {
  LayoutDashboard,
  Package,
  TrendingUp,
  FolderTree,
  Truck,
  FileText,
  Activity,
  Users,
  LogOut,
  FileEdit,
  Box,
  LayoutGrid,
  Ship,
  Globe,
  Bell,
  ChevronDown,
  ClipboardList,
  ShieldCheck,
  Landmark,
  Warehouse,
  ScanLine,
  Boxes,
  Radar,
  BookOpen,
  HeartPulse,
} from "lucide-react";
import { useAuthStore } from "../store/authStore";
import NotificationCenter from "./NotificationCenter";
import SettingsModal from "./SettingsModal";
import GlobalSearch from "./GlobalSearch";
import AimsLogo from "./AimsLogo";
import { useTranslation } from "../hooks/useTranslation";
import { useSettingsStore } from "../store/settingsStore";
import { pageToGroup, useSidebarNavStore } from "../store/sidebarNavStore";
import { canOpenPage } from "../config/pageAccess";

export default function Sidebar({
  currentPage,
  onPageChange,
  userRole,
  onLogout,
  onHome,
  isOpen = false,
}) {
  const { t } = useTranslation();
  const enable3dMap = useSettingsStore((state) => state.enable_3d_map);
  const permissions = useAuthStore((state) => state.permissions);
  const canManageDailySales = permissions.includes("daily_sales.manage");
  const canManageInvoices = permissions.includes("invoices.manage");
  const canViewDebts = permissions.includes("debts.view");
  const canViewFinance = permissions.includes("finance.view");
  const canViewMoneyAccounts = permissions.includes("financial_accounts.view");
  const canViewAccounting = permissions.includes("accounting.reports.view");
  const expandedGroups = useSidebarNavStore((state) => state.expandedGroups);
  const toggleGroup = useSidebarNavStore((state) => state.toggleGroup);
  const ensureGroupOpen = useSidebarNavStore((state) => state.ensureGroupOpen);

  const groups = useMemo(() => {
    if (userRole === "superadmin") {
      return [
        {
          id: "settings",
          label: t("nav.group.settings"),
          items: [
            {
              id: "superadmin",
              label: t("nav.platformAdmin"),
              icon: ShieldCheck,
            },
          ],
        },
      ];
    }

    const all = [
      {
        id: "overview",
        label: t("nav.group.overview"),
        items: [
          { id: "dashboard", label: t("nav.dashboard"), icon: LayoutDashboard },
        ],
      },
      ...(canViewFinance || canManageInvoices || canManageDailySales || canViewMoneyAccounts || canViewAccounting
        ? [
            {
              id: "finance",
              label: t("nav.group.finance"),
              items: [
                ...(canManageDailySales ? [{ id: "daily-sales", label: t("nav.dailySales"), icon: ClipboardList }] : []),
                ...(canManageInvoices ? [{ id: "invoices", label: t("nav.invoices"), icon: FileText }] : []),
                ...(canViewFinance ? [{ id: "finance", label: t("nav.financeCenter"), icon: Landmark }] : []),
                ...(canViewMoneyAccounts ? [{ id: "money-accounts", label: t("nav.moneyAccounts"), icon: Landmark }] : []),
                ...(canViewAccounting ? [{ id: "accounting", label: t("nav.accounting"), icon: BookOpen }] : []),
              ],
            },
          ]
        : []),
      {
        id: "inventory",
        label: t("nav.group.inventory"),
        items: [
          { id: "products", label: t("nav.products"), icon: Package },
          { id: "stock", label: t("nav.stock"), icon: TrendingUp },
          ...(permissions.includes("inventory.view") ? [{ id: "operations-center", label: t("nav.operationsCenter"), icon: Boxes }] : []),
          ...(permissions.includes("quality.view") ? [{ id: "quality", label: t("nav.quality"), icon: ShieldCheck }] : []),
          ...(permissions.includes("transfers.view") ? [{ id: "warehouse-operations", label: t("nav.warehouseOperations"), icon: Warehouse }] : []),
          ...(permissions.includes("warehouse_mobile.use") ? [{ id: "warehouse-mobile", label: t("nav.mobileWarehouse"), icon: ScanLine }] : []),
          { id: "suppliers", label: t("nav.suppliers"), icon: Truck },
          { id: "categories", label: t("nav.categories"), icon: FolderTree },
        ],
      },
      ...(enable3dMap ? [{
        id: "warehouse",
        label: t("nav.group.warehouse"),
        items: [
          { id: "warehouse-3d", label: t("nav.warehouse3d"), icon: Box },
          { id: "warehouse-layout", label: t("nav.warehouseLayout"), icon: LayoutGrid },
        ],
      }] : []),
      {
        id: "orders",
        label: t("nav.group.orders"),
          items: [
            ...(permissions.includes("fulfillment.view") ? [{ id: "order-hub", label: t("nav.orderHub"), icon: Package }] : []),
            { id: "purchase-orders", label: t("nav.purchaseOrders"), icon: Truck },
            ...(permissions.includes("fulfillment.view") ? [{ id: "fulfillment", label: t("nav.fulfillment"), icon: Package }] : []),
            ...(permissions.includes("documents.view") ? [{ id: "documents", label: t("nav.documents"), icon: FileText }] : []),
          ...(permissions.includes("procurement.view") ? [{ id: "procurement", label: t("nav.procurement"), icon: ClipboardList }] : []),
        ],
      },
      {
        id: "reports",
        label: t("nav.group.reports"),
        items: [
          ...(canViewDebts ? [{ id: "customer-debts", label: t("nav.customerDebts"), icon: FileText }] : []),
          { id: "reports", label: t("nav.inventoryReports"), icon: FileText },
        ],
      },
      {
        id: "shipments",
        label: t("nav.group.shipments"),
        items: [
          ...(permissions.includes("control_tower.view") ? [{ id: "control-tower", label: t("nav.controlTower"), icon: Radar }] : []),
          { id: "shipments/global-map", label: t("nav.globalMap"), icon: Globe },
          { id: "shipments/my-shipments", label: t("nav.myShipments"), icon: Ship },
          { id: "shipments/alerts", label: t("nav.shipmentAlerts"), icon: Bell },
        ],
      },
    ];

    if (userRole === "admin" || permissions.includes("system_integrity.view")) {
      all.push({
        id: "settings",
        label: t("nav.group.settings"),
        items: [
          ...(userRole === "admin" ? [{ id: "users", label: t("nav.users"), icon: Users }, { id: "activity-logs", label: t("nav.activityLogs"), icon: Activity }, { id: "cms", label: t("nav.systemSettings"), icon: FileEdit }] : []),
          ...(permissions.includes("system_integrity.view") ? [{ id: "system-integrity", label: t("nav.systemIntegrity"), icon: HeartPulse }] : []),
        ],
      });
    }

    return all.map((group) => ({ ...group, items: group.items.filter((item) => canOpenPage(item.id, permissions)) })).filter((group) => group.items.length);
  }, [enable3dMap, userRole, t, canManageDailySales, canManageInvoices, canViewDebts, canViewFinance, canViewMoneyAccounts, canViewAccounting, permissions]);

  useEffect(() => {
    const groupId = pageToGroup(currentPage);
    if (groupId) {
      ensureGroupOpen(groupId);
    }
  }, [currentPage, ensureGroupOpen]);

  return (
    <aside className={`sidebar ${isOpen ? "sidebar-open" : ""}`}>
      <div className="sidebar-header">
        <button
          type="button"
          className="sidebar-logo sidebar-logo-btn"
          onClick={onHome}
        >
          <AimsLogo showText={false} size="sm" />
          <p className="sidebar-subtitle">
            {userRole === "superadmin"
              ? t("nav.platformAdmin")
              : t("nav.enterprise")}
          </p>
        </button>
        {userRole !== "superadmin" ? (
          <>
            <div className="sidebar-header-actions">
              <SettingsModal />
              <NotificationCenter onNavigate={(path) => onPageChange(path.replace(/^\//, ""))} />
            </div>
            <GlobalSearch onNavigate={(type, item) => onPageChange(type, item)} />
          </>
        ) : null}
      </div>

      <nav className="sidebar-nav">
        {groups.map((group) => {
          const isExpanded = expandedGroups[group.id] ?? false;
          const hasActiveItem = group.items.some(
            (item) => item.id === currentPage,
          );

          return (
            <div
              key={group.id}
              className={`sidebar-group ${hasActiveItem ? "has-active" : ""}`}
            >
              <button
                type="button"
                className={`sidebar-group-toggle ${isExpanded ? "expanded" : ""}`}
                onClick={() => toggleGroup(group.id)}
                aria-expanded={isExpanded}
              >
                <span>{group.label}</span>
                <ChevronDown size={16} className="sidebar-group-chevron" />
              </button>

              {isExpanded ? (
                <div className="sidebar-group-items" aria-label={group.label}>
                  {group.items.map((item) => {
                    const Icon = item.icon;
                    const isActive = currentPage === item.id;

                    return (
                      <button
                        key={item.id}
                        type="button"
                        className={`sidebar-item sidebar-subitem ${isActive ? "active" : ""}`}
                        aria-current={isActive ? "page" : undefined}
                        onClick={() => onPageChange(item.id)}
                      >
                        <Icon size={18} />
                        <span>{item.label}</span>
                      </button>
                    );
                  })}
                </div>
              ) : null}
            </div>
          );
        })}
      </nav>

      <div className="sidebar-footer">
        <button
          type="button"
          className="sidebar-item logout"
          onClick={() => onLogout?.()}
        >
          <LogOut size={20} />
          <span>{t("nav.logout")}</span>
        </button>
      </div>
    </aside>
  );
}
