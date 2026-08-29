import { useState, useEffect, useRef } from 'react'
import { Search } from 'lucide-react'
import { globalSearch } from '../api/search'

export default function GlobalSearch({ onNavigate }) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState(null)
  const [isOpen, setIsOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const containerRef = useRef(null)

  useEffect(() => {
    if (query.length < 2) {
      setResults(null)
      return undefined
    }

    const timer = setTimeout(async () => {
      setLoading(true)
      try {
        const data = await globalSearch(query)
        setResults(data)
        setIsOpen(true)
      } catch {
        setResults(null)
      } finally {
        setLoading(false)
      }
    }, 300)

    return () => clearTimeout(timer)
  }, [query])

  useEffect(() => {
    function handleClickOutside(event) {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const handleSelect = (type, item) => {
    setIsOpen(false)
    setQuery('')
    if (onNavigate) {
      onNavigate(type, item)
    }
  }

  const hasResults = results && (
    results.products?.length > 0 ||
    results.categories?.length > 0 ||
    results.suppliers?.length > 0 ||
    results.invoices?.length > 0 ||
    results.stock_movements?.length > 0 ||
    results.customers?.length > 0 || results.purchase_orders?.length > 0 ||
    results.shipments?.length > 0 || results.stock_transfers?.length > 0 || results.goods_receipts?.length > 0
  )

  return (
    <div className="global-search" ref={containerRef}>
      <Search size={18} className="global-search-icon" />
      <input
        type="search"
        placeholder="Search inventory..."
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        onFocus={() => results && setIsOpen(true)}
      />
      {loading && <span className="global-search-loading">Searching...</span>}

      {isOpen && hasResults && (
        <div className="global-search-results">
          {results.products?.length > 0 && (
            <section>
              <h4>Products</h4>
              {results.products.map((item) => (
                <button key={`p-${item.id}`} type="button" onClick={() => handleSelect('products', item)}>
                  {item.name} <span>{item.sku}</span>
                </button>
              ))}
            </section>
          )}
          {results.categories?.length > 0 && (
            <section>
              <h4>Categories</h4>
              {results.categories.map((item) => (
                <button key={`c-${item.id}`} type="button" onClick={() => handleSelect('categories', item)}>
                  {item.name}
                </button>
              ))}
            </section>
          )}
          {results.suppliers?.length > 0 && (
            <section>
              <h4>Suppliers</h4>
              {results.suppliers.map((item) => (
                <button key={`s-${item.id}`} type="button" onClick={() => handleSelect('suppliers', item)}>
                  {item.name}
                </button>
              ))}
            </section>
          )}
          {results.invoices?.length > 0 && (
            <section>
              <h4>Invoices</h4>
              {results.invoices.map((item) => (
                <button key={`i-${item.id}`} type="button" onClick={() => handleSelect('invoices', item)}>
                  {item.invoice_number} - {item.customer_name}
                </button>
              ))}
            </section>
          )}
          {results.stock_movements?.length > 0 && (
            <section>
              <h4>Stock movements</h4>
              {results.stock_movements.map((item) => (
                <button key={`m-${item.id}`} type="button" onClick={() => handleSelect('stock', item)}>
                  {item.product?.name || 'Product'} ({item.type} {item.quantity})
                </button>
              ))}
            </section>
          )}
          {results.customers?.length > 0 && <section><h4>Customers</h4>{results.customers.map((item) => <button key={`cu-${item.id}`} type="button" onClick={() => handleSelect('customer-debts', item)}>{item.name} <span>{item.business_registration_number || item.email}</span></button>)}</section>}
          {results.purchase_orders?.length > 0 && <section><h4>Purchase orders</h4>{results.purchase_orders.map((item) => <button key={`po-${item.id}`} type="button" onClick={() => handleSelect('purchase-orders', item)}>{item.po_number} <span>{item.supplier?.name}</span></button>)}</section>}
          {results.shipments?.length > 0 && <section><h4>Shipments</h4>{results.shipments.map((item) => <button key={`sh-${item.id}`} type="button" onClick={() => handleSelect('shipments/my-shipments', item)}>{item.tracking_number || item.tracking_reference} <span>{item.destination_port}</span></button>)}</section>}
          {results.stock_transfers?.length > 0 && <section><h4>Stock transfers</h4>{results.stock_transfers.map((item) => <button key={`tr-${item.id}`} type="button" onClick={() => handleSelect('warehouse-operations', item)}>{item.transfer_number} <span>{item.status}</span></button>)}</section>}
          {results.goods_receipts?.length > 0 && <section><h4>Goods receipts</h4>{results.goods_receipts.map((item) => <button key={`gr-${item.id}`} type="button" onClick={() => handleSelect('warehouse-operations', item)}>{item.receipt_number} <span>{item.purchase_order?.po_number}</span></button>)}</section>}
        </div>
      )}
    </div>
  )
}
