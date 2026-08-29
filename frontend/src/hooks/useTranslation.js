import { useCallback } from "react";
import { useSettingsStore } from "../store/settingsStore";
import { en } from "../locales/en";
import { sq } from "../locales/sq";

const catalogs = { en, sq };

export function useTranslation() {
  const language = useSettingsStore((state) => state.language);
  const dict = catalogs[language] || en;

  const t = useCallback((key, values = {}) => {
    const template = dict[key] ?? catalogs.en[key] ?? key;
    return Object.entries(values).reduce(
      (text, [name, value]) => text.replaceAll(`{{${name}}}`, String(value)),
      template,
    );
  }, [dict]);

  return { t, language };
}
