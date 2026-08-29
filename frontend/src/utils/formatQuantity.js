export function isMeterUnit(unit) {
  return ["m", "meter", "meters", "metre", "metër", "metri", "metra"].includes(
    String(unit || "").trim().toLowerCase(),
  );
}

export function formatQuantity(value, unit = "pcs", language = "en") {
  const quantity = Number(value || 0);
  return new Intl.NumberFormat(language === "sq" ? "sq-AL" : "en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: isMeterUnit(unit) ? 3 : 0,
  }).format(quantity);
}
