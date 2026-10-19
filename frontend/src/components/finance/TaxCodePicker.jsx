import { useEffect, useState } from "react";
import { availableTaxCodes } from "../../api/tax";
export function useTaxCodes() {
  const [codes, setCodes] = useState([]),
    [error, setError] = useState("");
  useEffect(() => {
    let alive = true;
    availableTaxCodes()
      .then((d) => alive && setCodes(d.codes))
      .catch((e) => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, []);
  return { codes, error };
}
export default function TaxCodePicker({
  codes,
  value,
  onChange,
  direction = "sales",
  date,
  label = "Tax code",
  disabled = false,
}) {
  return (
    <label>
      {label}
      <select
        disabled={disabled}
        value={value || ""}
        onChange={(e) =>
          onChange(codes.find((c) => String(c.id) === e.target.value) || null)
        }
      >
        <option value="">—</option>
        {codes
          .filter(
            (c) =>
              c[direction + "_enabled"] &&
              (!date || String(c.effective_from).slice(0, 10) <= date) &&
              (!c.effective_to ||
                !date ||
                String(c.effective_to).slice(0, 10) >= date),
          )
          .map((c) => (
            <option key={c.id} value={c.id}>
              {c.code} · {c.name} · {c.rate}%
            </option>
          ))}
      </select>
    </label>
  );
}
