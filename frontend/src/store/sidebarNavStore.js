import { create } from "zustand";
import { persist } from "zustand/middleware";

const DEFAULT_GROUPS = {
  overview: true,
  inventory: true,
  orders: true,
  shipments: false,
  finance: true,
  reports: true,
  warehouse: false,
  settings: false,
};

export const useSidebarNavStore = create(
  persist(
    (set, get) => ({
      expandedGroups: { ...DEFAULT_GROUPS },

      toggleGroup(groupId) {
        const current = get().expandedGroups[groupId] ?? false;
        set({
          expandedGroups: {
            ...get().expandedGroups,
            [groupId]: !current,
          },
        });
      },

      ensureGroupOpen(groupId) {
        if (get().expandedGroups[groupId]) return;
        set({
          expandedGroups: {
            ...get().expandedGroups,
            [groupId]: true,
          },
        });
      },
    }),
    {
      name: "aims-sidebar-nav",
    },
  ),
);

export function pageToGroup(pageId) {
  const map = {
    dashboard: "overview",
    products: "inventory",
    stock: "inventory",
    categories: "inventory",
    suppliers: "inventory",
    "warehouse-operations": "inventory",
    invoices: "finance",
    finance: "finance",
    "money-accounts": "finance",
    "customer-debts": "reports",
    "purchase-orders": "orders",
    "daily-sales": "finance",
    "shipments/global-map": "shipments",
    "shipments/my-shipments": "shipments",
    "shipments/alerts": "shipments",
    shipments: "shipments",
    reports: "reports",
    "warehouse-3d": "warehouse",
    "warehouse-layout": "warehouse",
    users: "settings",
    "activity-logs": "settings",
    cms: "settings",
    superadmin: "settings",
  };

  return map[pageId] ?? null;
}
