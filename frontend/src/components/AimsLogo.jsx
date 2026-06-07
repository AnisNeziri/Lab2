export default function AimsLogo({ className = '', showText = true, size = 'md', variant = 'default' }) {
  const heights = { sm: 36, md: 44, lg: 52, xl: 60 }
  const height = heights[size] || heights.md

  return (
    <div className={`aims-logo aims-logo-${variant} ${className}`.trim()}>
      <img
        src="/aims-logo.svg"
        alt="AIMS Inventory"
        className="aims-logo-image"
        style={{ height: `${height}px`, width: 'auto' }}
      />
      {showText && <span className="aims-logo-tagline">Inventory</span>}
    </div>
  )
}
