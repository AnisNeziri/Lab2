import { useEffect, useId, useRef, useState } from 'react'
import { Html5Qrcode } from 'html5-qrcode'
import { X, ScanLine } from 'lucide-react'
import { useSettingsStore } from '../store/settingsStore'

export default function BarcodeScanner({ onScanSuccess, onClose }) {
  const sq=useSettingsStore(s=>s.language)==='sq'
  const reactId = useId()
  const scannerId = `barcode-scanner-${reactId.replace(/[^a-zA-Z0-9_-]/g, '')}`
  const successRef = useRef(onScanSuccess)
  const [error, setError] = useState('')
  const [attempt, setAttempt] = useState(0)
  const [status, setStatus] = useState('starting')

  useEffect(() => {
    successRef.current = onScanSuccess
  }, [onScanSuccess])

  useEffect(() => {
    let mounted = true
    let scanner = null
    let delivered = false
    let stopPromise = null

    const stopScanner = () => {
      if (stopPromise) return stopPromise
      stopPromise = (async () => {
        if (!scanner) return
        try {
          if (scanner.isScanning) await scanner.stop()
        } catch {
          // A browser may stop the camera stream before React unmounts it.
        }
        try {
          await scanner.clear()
        } catch {
          // The scanner may already be cleared after a successful scan.
        }
      })()

      return stopPromise
    }

    const startScanner = async () => {
      try {
        scanner = new Html5Qrcode(scannerId)

        await scanner.start(
          { facingMode: 'environment' },
          {
            fps: 10,
            qrbox: (width, height) => {
              const edge = Math.max(180, Math.min(280, Math.floor(Math.min(width, height) * 0.72)))
              return { width: edge, height: edge }
            },
            aspectRatio: 1,
          },
          (decodedText) => {
            if (delivered) return
            delivered = true
            if (mounted) setStatus('success')
            void stopScanner().finally(() => successRef.current(decodedText))
          },
          () => {},
        )
        if (mounted) setStatus('scanning')
        else { if(scanner.isScanning)await scanner.stop();await scanner.clear() }
      } catch (err) {
        if (!mounted) return
        setStatus('error')
        setError(err?.message || (sq?'Lejoni kamerën ose përdorni skanerin USB.':'Allow camera access or use a USB scanner.'))
      }
    }

    void startScanner()

    return () => {
      mounted = false
      void stopScanner()
    }
  }, [attempt, scannerId])

  const handleRescan = () => {
    setError('')
    setStatus('starting')
    setAttempt((value) => value + 1)
  }

  return (
    <div className="barcode-scanner-overlay" role="dialog" aria-modal="true" aria-label={sq?'Skano barkodin':'Scan barcode'} onKeyDown={e=>{if(e.key==='Escape')onClose()}}>
      <div className="barcode-scanner-modal">
        <div className="scanner-header">
          <h2>{sq?'Skano barkodin':'Scan Barcode'}</h2>
          <button
            type="button"
            className="close-btn"
            onClick={onClose}
            aria-label={sq?'Mbyll skanerin':'Close scanner'}
            autoFocus
          >
            <X size={20} />
          </button>
        </div>

        <div className="scanner-content">
          {error ? (
            <div className="scanner-error">
              <p>{error}</p>
              <button
                type="button"
                className="secondary"
                onClick={handleRescan}
              >
                {sq?'Provo përsëri':'Try Again'}
              </button>
            </div>
          ) : (
            <>
              <div id={scannerId} className="scanner-container" />
              {status === 'success' && (
                <div className="scan-success">
                  <ScanLine size={48} />
                  <p>{sq?'Barkodi u skanua!':'Barcode scanned successfully!'}</p>
                </div>
              )}
            </>
          )}
        </div>

        <div className="scanner-footer">
          <p>{sq?'Drejto kamerën te barkodi':'Point camera at a barcode to scan'}</p>
        </div>
      </div>
    </div>
  )
}
