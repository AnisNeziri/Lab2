import { create } from "zustand";
import { persist } from "zustand/middleware";
import { navigationContext } from '../config/navigation';

const DEFAULT_GROUPS = {
  overview: true,
  inventory: false,
  sales: false,
  purchasing: false,
  shipments: false,
  finance: false,
  "stock-supply": false,
  company: false,
  workforce: false,
  intelligence: false,
  operations: false,
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

      ensureGroupsOpen(...groupIds) {
        if (groupIds.every(id => get().expandedGroups[id])) return;
        set({
          expandedGroups: {
            ...get().expandedGroups,
            ...Object.fromEntries(groupIds.map(id => [id, true])),
          },
        });
      },
    }),
    {
      name: "aims-sidebar-nav",
      version: 2,
      migrate: (state) => ({ ...state, expandedGroups: Object.fromEntries(Object.entries(DEFAULT_GROUPS).map(([key, value]) => [key, typeof state?.expandedGroups?.[key] === 'boolean' ? state.expandedGroups[key] : value])) }),
    },
  ),
);

export function pageToGroup(pageId) {
  return pageId === 'superadmin' ? 'settings' : navigationContext(pageId)?.group.id ?? null;
}
