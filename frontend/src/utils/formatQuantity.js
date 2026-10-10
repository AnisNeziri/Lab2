export function isMeterUnit(unit) {
  return ["m", "meter", "meters", "metre", "metër", "metri", "metra"].includes(
    String(unit || "").trim().toLowerCase(),
  );
}

export function formatQuantity(value, unit = "pcs", language = "en") {
  if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—';
  const quantity = Number(value);
  return new Intl.NumberFormat(language === "sq" ? "sq-AL" : "en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: isMeterUnit(unit) ? 3 : 0,
  }).format(quantity);
}
