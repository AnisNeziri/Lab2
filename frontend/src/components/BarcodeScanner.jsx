import { useEffect, useRef, useState } from 'react'
import { Html5QrcodeScanner } from 'html5-qrcode'
import { X, ScanLine } from 'lucide-react'

export default function BarcodeScanner({ onScanSuccess, onClose }) {
  const scannerRef = useRef(null)
  const [error, setError] = useState('')
  const [isScanning, setIsScanning] = useState(true)

  useEffect(() => {
    let scanner = null

    const startScanner = async () => {
      try {
        scanner = new Html5QrcodeScanner(
          scannerRef.current.id,
          {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0,
          },
          false
        )

        await scanner.start(
          { facingMode: 'environment' },
          (decodedText) => {
            setIsScanning(false)
            onScanSuccess(decodedText)
            if (scanner) {
              scanner.stop()
            }
          },
          (errorMessage) => {
            // Ignore scan errors as they happen frequently
            console.debug('Scan error:', errorMessage)
          }
        )
      } catch (err) {
        setError('Failed to start camera. Please ensure camera permissions are granted.')
        console.error('Scanner error:', err)
      }
    }

    startScanner()

    return () => {
      if (scanner) {
        scanner.stop().catch(console.error)
      }
    }
  }, [onScanSuccess])

  const handleRescan = () => {
    setIsScanning(true)
    setError('')
    // Scanner will restart automatically when component remounts
  }

  return (
    <div className="barcode-scanner-overlay">
      <div className="barcode-scanner-modal">
        <div className="scanner-header">
          <h2>Scan Barcode</h2>
          <button
            type="button"
            className="close-btn"
            onClick={onClose}
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
                Try Again
              </button>
            </div>
          ) : (
            <>
              <div
                id="barcode-scanner"
                ref={scannerRef}
                className="scanner-container"
              />
              {!isScanning && (
                <div className="scan-success">
                  <ScanLine size={48} />
                  <p>Barcode scanned successfully!</p>
                  <button
                    type="button"
                    className="secondary"
                    onClick={handleRescan}
                  >
                    Scan Another
                  </button>
                </div>
              )}
            </>
          )}
        </div>

        <div className="scanner-footer">
          <p>Point camera at a barcode to scan</p>
        </div>
      </div>
    </div>
  )
}
